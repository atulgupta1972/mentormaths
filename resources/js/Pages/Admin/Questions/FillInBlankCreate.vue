<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChapterQuestionPlan from '@/Components/ChapterQuestionPlan.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import {
    defaultFillBlankRow,
    fillBlankFormats,
    parseFillBlankJson,
    rowsFromImportData,
} from '@/utils/fillBlankImport';

const props = defineProps({
    chapters: Array,
    chapterTopics: Array,
    topicsByChapter: Object,
    selectedChapterId: [Number, String, null],
    selectedTopicId: [Number, String, null],
    topic: Object,
    cursorPrompt: String,
    promptOptions: Object,
    initialImportRows: Array,
    pageError: String,
    scope: { type: String, default: 'topic' },
    chapterPlan: { type: Array, default: () => [] },
});

const page = usePage();
const chapterFilter = ref(props.selectedChapterId || '');
const selectedTopic = ref(props.selectedTopicId || '');
const scopeMode = ref(props.scope || 'topic');
const jsonInput = ref('');
const previewError = ref('');
const step3Ref = ref(null);
const jsonFileInput = ref(null);
const copied = ref(false);
const copyError = ref('');
const promptBox = ref(null);
const saveTopicError = ref('');
const generatingChapterPrompt = ref(false);

const promptSettings = ref({
    total: props.promptOptions?.total ?? 6,
    easy: props.promptOptions?.easy ?? 2,
    medium: props.promptOptions?.medium ?? 2,
    hard: props.promptOptions?.hard ?? 2,
    focus: props.promptOptions?.focus ?? '',
});

const buildDefaultChapterPlan = () => (props.chapterTopics || []).map((topic, index) => ({
    topic_id: topic.id,
    topic_name: topic.name,
    easy: 0,
    medium: 0,
    hard: 0,
    sort_order: index + 1,
}));

const chapterPlanRows = ref(
    props.chapterPlan?.length
        ? props.chapterPlan
        : buildDefaultChapterPlan(),
);

const rows = ref([]);

const saveForm = useForm({
    syllabus_topic_id: props.selectedTopicId || '',
    syllabus_chapter_id: props.selectedChapterId || '',
    rows: [],
});

const zipPackInput = ref(null);
const zipImportForm = useForm({
    pack: null,
    scope: scopeMode.value,
    syllabus_chapter_id: '',
    syllabus_topic_id: '',
});

const onZipPackSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }

    if (!chapterFilter.value) {
        previewError.value = 'Select a chapter first.';
        event.target.value = '';

        return;
    }

    if (isTopicScope.value && !selectedTopic.value) {
        previewError.value = 'Select a topic first.';
        event.target.value = '';

        return;
    }

    zipImportForm.pack = file;
    zipImportForm.scope = scopeMode.value;
    zipImportForm.syllabus_chapter_id = isChapterScope.value ? chapterFilter.value : '';
    zipImportForm.syllabus_topic_id = isTopicScope.value ? selectedTopic.value : '';

    zipImportForm.post(route('admin.questions.import-zip-pack'), {
        forceFormData: true,
        onError: (errors) => {
            previewError.value = errors.pack || errors.syllabus_chapter_id || errors.syllabus_topic_id || 'Could not import zip pack.';
        },
    });

    event.target.value = '';
};

const difficultySum = computed(
    () => Number(promptSettings.value.easy) + Number(promptSettings.value.medium) + Number(promptSettings.value.hard),
);

const difficultyMismatch = computed(
    () => difficultySum.value !== Number(promptSettings.value.total),
);

const isChapterScope = computed(() => scopeMode.value === 'chapter' && Boolean(chapterFilter.value));
const isTopicScope = computed(() => scopeMode.value === 'topic' && Boolean(selectedTopic.value));
const hasChapterPrompt = computed(() => isChapterScope.value && Boolean(props.cursorPrompt));

const activePrompt = computed(() => {
    if (isChapterScope.value) {
        return props.cursorPrompt || 'Fill in the chapter plan matrix, then click Generate Cursor prompt for chapter.';
    }

    if (!props.cursorPrompt) {
        return 'Select a topic first to generate the fill-in-the-blank Cursor prompt.';
    }

    return props.cursorPrompt;
});

const applyImportRows = (importRows) => {
    if (!importRows?.length) {
        return;
    }

    rows.value = isChapterScope.value
        ? rowsFromImportData(assignTopicsToRows(importRows))
        : rowsFromImportData(importRows);

    nextTick(() => {
        step3Ref.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
};

function buildQueryParams(overrides = {}) {
    const params = {
        syllabus_chapter_id: chapterFilter.value || props.selectedChapterId || undefined,
        syllabus_topic_id: selectedTopic.value || props.selectedTopicId || undefined,
        scope: scopeMode.value,
        total: promptSettings.value.total,
        easy: promptSettings.value.easy,
        medium: promptSettings.value.medium,
        hard: promptSettings.value.hard,
        ...overrides,
    };

    if (promptSettings.value.focus) {
        params.focus = promptSettings.value.focus;
    }

    if (scopeMode.value === 'chapter') {
        delete params.syllabus_topic_id;
    }

    return params;
}

const resolveTopicIdForRow = (row) => {
    if (row.syllabus_topic_id) {
        return row.syllabus_topic_id;
    }

    const name = String(row.topic_name || '').trim().toLowerCase();
    if (!name) {
        return null;
    }

    const match = (props.chapterTopics || []).find((topic) => topic.name.toLowerCase() === name);
    return match?.id ?? null;
};

const assignTopicsToRows = (parsedRows) => parsedRows.map((row) => ({
    ...row,
    syllabus_topic_id: resolveTopicIdForRow(row),
}));

watch(() => props.chapterPlan, (plan) => {
    if (plan?.length) {
        chapterPlanRows.value = plan;
    }
});

watch(() => props.scope, (scope) => {
    if (scope) {
        scopeMode.value = scope;
    }
});

watch(chapterFilter, (id, oldId) => {
    if (id === oldId) {
        return;
    }

    selectedTopic.value = '';
    chapterPlanRows.value = buildDefaultChapterPlan();

    if (id) {
        router.get(
            route('admin.questions.create-fill-in-blank', { syllabus_chapter_id: id, scope: scopeMode.value }),
            {},
            { preserveState: false },
        );
    }
});

watch(selectedTopic, (id, oldId) => {
    if (!id || id === oldId || scopeMode.value !== 'topic') {
        return;
    }

    router.get(
        route('admin.questions.create-fill-in-blank', buildQueryParams({ syllabus_topic_id: id })),
        {},
        { preserveState: false },
    );
});

const switchScope = (scope) => {
    scopeMode.value = scope;

    if (!chapterFilter.value) {
        return;
    }

    router.get(
        route('admin.questions.create-fill-in-blank', {
            syllabus_chapter_id: chapterFilter.value,
            scope,
            syllabus_topic_id: scope === 'topic' ? (selectedTopic.value || undefined) : undefined,
        }),
        {},
        { preserveState: false },
    );
};

const generateChapterPrompt = () => {
    if (!chapterFilter.value || generatingChapterPrompt.value) {
        return;
    }

    generatingChapterPrompt.value = true;
    router.post(route('admin.questions.chapter-fill-blank-prompt'), {
        syllabus_chapter_id: chapterFilter.value,
        plan: chapterPlanRows.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            generatingChapterPrompt.value = false;
        },
    });
};

const refreshPrompt = () => {
    if (!selectedTopic.value || scopeMode.value !== 'topic') {
        return;
    }

    router.get(route('admin.questions.create-fill-in-blank', buildQueryParams()), {}, { preserveState: false });
};

const loadPreview = () => {
    previewError.value = '';

    if (!jsonInput.value.trim()) {
        previewError.value = 'Paste JSON or upload a .json file first.';
        return;
    }

    try {
        const parsed = parseFillBlankJson(jsonInput.value);
        rows.value = isChapterScope.value
            ? rowsFromImportData(assignTopicsToRows(parsed))
            : rowsFromImportData(parsed);

        nextTick(() => {
            step3Ref.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    } catch (error) {
        previewError.value = error.message || 'Could not parse JSON.';
    }
};

const onJsonPaste = () => {
    nextTick(() => {
        if (jsonInput.value.trim().startsWith('{') || jsonInput.value.trim().startsWith('[')) {
            loadPreview();
        }
    });
};

const onJsonFileSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }

    const reader = new FileReader();
    reader.onload = () => {
        jsonInput.value = String(reader.result || '');
        loadPreview();
    };
    reader.readAsText(file);
    event.target.value = '';
};

const copyPrompt = async () => {
    copyError.value = '';

    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(activePrompt.value);
            copied.value = true;
            setTimeout(() => (copied.value = false), 2500);
            return;
        }
    } catch {
        // Fall through to manual select below.
    }

    promptBox.value?.focus();
    promptBox.value?.select();

    try {
        document.execCommand('copy');
        copied.value = true;
        setTimeout(() => (copied.value = false), 2500);
    } catch {
        copyError.value = 'Could not copy automatically. Select the prompt text and press Ctrl+C.';
    }
};

const selectPrompt = () => {
    promptBox.value?.select();
};

const addRow = () => {
    rows.value.push(defaultFillBlankRow());
};

const removeRow = (index) => {
    rows.value.splice(index, 1);
};

const saveQuestions = () => {
    saveTopicError.value = '';
    saveForm.clearErrors();

    if (!rows.value.length) {
        saveForm.setError('rows', 'Add at least one question.');
        return;
    }

    if (isChapterScope.value) {
        const missingTopic = rows.value.some((row) => !resolveTopicIdForRow(row));
        if (missingTopic) {
            saveTopicError.value = 'Each question must have a topic name that matches a topic in this chapter.';
            return;
        }

        saveForm.syllabus_chapter_id = chapterFilter.value;
        saveForm.rows = rows.value.map((row) => ({
            ...row,
            syllabus_topic_id: resolveTopicIdForRow(row),
        }));
        saveForm.post(route('admin.questions.bulk-store-chapter-fill-blank'), {
            preserveScroll: true,
            onError: () => {
                step3Ref.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            },
        });

        return;
    }

    if (!selectedTopic.value) {
        saveForm.setError('syllabus_topic_id', 'Select a topic before saving.');
        return;
    }

    saveForm.syllabus_topic_id = selectedTopic.value;
    saveForm.rows = rows.value;
    saveForm.post(route('admin.questions.bulk-store-fill-blank'), {
        preserveScroll: true,
    });
};

onMounted(() => {
    applyImportRows(page.props.flash?.import_rows || props.initialImportRows);
});
</script>

<template>
    <Head title="Add fill in the blanks" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-gray-800">Add fill in the blanks</h2>
                <div class="flex flex-wrap items-center gap-3">
                    <Link
                        :href="route('admin.questions.create', buildQueryParams())"
                        class="text-sm text-indigo-600 hover:underline"
                    >
                        Add MCQs instead
                    </Link>
                    <Link
                        :href="selectedTopic
                            ? route('admin.questions.topics.show', selectedTopic)
                            : (chapterFilter ? route('admin.questions.chapters.show', chapterFilter) : route('admin.questions.index'))"
                        class="text-sm text-indigo-600"
                    >
                        Question bank
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-md bg-sky-50 p-4 text-sm text-sky-950">
                    Fill-in-the-blank sums use guided practice only (integer, decimal, or fraction answers).
                    Students get one retry, then a method hint, then teacher help if still stuck — same flow as MCQ practice sets.
                </div>

                <div v-if="$page.props.flash?.error || pageError" class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                    {{ pageError || $page.props.flash?.error }}
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm space-y-4">
                    <div>
                        <InputLabel value="Chapter" />
                        <select v-model="chapterFilter" class="mt-1 block w-full max-w-3xl rounded-md border-gray-300 text-sm" required>
                            <option value="">Select chapter</option>
                            <option v-for="ch in chapters" :key="ch.id" :value="ch.id">{{ ch.label }}</option>
                        </select>
                    </div>

                    <div v-if="chapterFilter">
                        <InputLabel value="Add questions for" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-2 max-w-3xl">
                            <button
                                type="button"
                                class="rounded-lg border p-4 text-left transition"
                                :class="scopeMode === 'topic'
                                    ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
                                    : 'border-gray-200 hover:border-gray-300'"
                                @click="switchScope('topic')"
                            >
                                <p class="font-medium text-gray-900">One topic</p>
                                <p class="mt-1 text-xs text-gray-500">Pick a topic, then generate fill-in-the-blank sums for that topic only</p>
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border p-4 text-left transition"
                                :class="scopeMode === 'chapter'
                                    ? 'border-sky-500 bg-sky-50 ring-1 ring-sky-500'
                                    : 'border-gray-200 hover:border-gray-300'"
                                @click="switchScope('chapter')"
                            >
                                <p class="font-medium text-gray-900">Whole chapter</p>
                                <p class="mt-1 text-xs text-gray-500">One JSON upload → questions saved topic-wise across the chapter</p>
                            </button>
                        </div>
                    </div>

                    <div v-if="chapterFilter && scopeMode === 'topic'">
                        <InputLabel value="Topic" />
                        <select v-model="selectedTopic" class="mt-1 block w-full max-w-3xl rounded-md border-gray-300 text-sm" required>
                            <option value="">Select topic</option>
                            <option v-for="t in chapterTopics" :key="t.id" :value="t.id">
                                {{ t.name }} ({{ t.questions_count }} in bank)
                            </option>
                        </select>
                        <p v-if="chapterTopics.length === 0" class="mt-1 text-xs text-amber-700">No topics in this chapter.</p>
                    </div>
                </div>

                <div v-if="chapterFilter" class="rounded-lg border-2 border-emerald-300 bg-emerald-50 p-6 shadow-sm">
                    <h3 class="font-semibold text-emerald-950">Quick import — one .zip file</h3>
                    <p class="mt-1 text-sm text-emerald-900">
                        Upload a zip containing <strong>questions.json</strong> plus diagram images (<strong>q1.jpg</strong>, <strong>q2.jpg</strong>, … or names in <strong>diagram_file</strong>).
                        Questions and diagrams are saved to the bank in one step.
                    </p>
                    <p class="mt-2 text-xs text-emerald-800">
                        Example zip layout: <span class="font-mono">questions.json, q1.jpg, q2.jpg, …</span>
                    </p>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <PrimaryButton
                            type="button"
                            :disabled="zipImportForm.processing || (isTopicScope && !selectedTopic)"
                            @click="zipPackInput?.click()"
                        >
                            {{ zipImportForm.processing ? 'Importing…' : 'Upload .zip pack → save to bank' }}
                        </PrimaryButton>
                        <InputError :message="zipImportForm.errors.pack" />
                    </div>
                    <input
                        ref="zipPackInput"
                        type="file"
                        accept=".zip,application/zip"
                        class="hidden"
                        @change="onZipPackSelected"
                    />
                </div>

                <ChapterQuestionPlan
                    v-if="isChapterScope && chapterTopics.length"
                    v-model="chapterPlanRows"
                    :topics="chapterTopics"
                    :generating="generatingChapterPrompt"
                    question-label="fill-in-the-blank questions"
                    @generate-prompt="generateChapterPrompt"
                />

                <p v-else-if="isChapterScope && chapterTopics.length === 0" class="rounded-md bg-amber-50 p-4 text-sm text-amber-900">
                    No topics in this chapter — add topics in syllabus setup first.
                </p>

                <div v-if="hasChapterPrompt" class="rounded-lg border border-indigo-100 bg-indigo-50 p-6">
                    <h3 class="font-medium text-indigo-900">Step 1 — Chapter prompt → Cursor</h3>
                    <p class="mt-1 text-sm text-indigo-950">
                        Copy this prompt to Cursor. Each question in the JSON must include a <strong>topic</strong> field.
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <SecondaryButton type="button" @click="copyPrompt">
                            {{ copied ? 'Copied!' : 'Copy prompt' }}
                        </SecondaryButton>
                        <SecondaryButton type="button" @click="selectPrompt">Select all</SecondaryButton>
                    </div>

                    <p v-if="copyError" class="mt-2 text-sm text-red-700">{{ copyError }}</p>

                    <textarea
                        ref="promptBox"
                        readonly
                        rows="14"
                        class="mt-3 block w-full rounded-md border-indigo-200 bg-white font-mono text-xs text-gray-800"
                        :value="activePrompt"
                        @focus="selectPrompt"
                    />
                </div>

                <div v-if="isTopicScope && topic" class="rounded-lg border border-indigo-100 bg-indigo-50 p-6">
                    <h3 class="font-medium text-indigo-900">Step 1 — Cursor prompt</h3>
                    <p class="mt-1 text-sm text-indigo-950">
                        Copy this prompt to Cursor. It asks for fill-in-the-blank JSON only (no MCQ options).
                    </p>

                    <div class="mt-4 grid gap-3 sm:grid-cols-5 max-w-3xl">
                        <div>
                            <InputLabel value="Total" />
                            <input v-model.number="promptSettings.total" type="number" min="1" max="50" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <InputLabel value="Easy" />
                            <input v-model.number="promptSettings.easy" type="number" min="0" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <InputLabel value="Medium" />
                            <input v-model.number="promptSettings.medium" type="number" min="0" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <InputLabel value="Hard" />
                            <input v-model.number="promptSettings.hard" type="number" min="0" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div class="flex items-end">
                            <SecondaryButton type="button" class="w-full justify-center" @click="refreshPrompt">
                                Refresh prompt
                            </SecondaryButton>
                        </div>
                    </div>

                    <p v-if="difficultyMismatch" class="mt-2 text-xs text-amber-800">
                        Easy + medium + hard should equal total ({{ difficultySum }} ≠ {{ promptSettings.total }}).
                    </p>

                    <div class="mt-3">
                        <InputLabel value="Focus (optional)" />
                        <input
                            v-model="promptSettings.focus"
                            type="text"
                            placeholder="e.g. negative integers, unlike fractions"
                            class="mt-1 block w-full max-w-3xl rounded-md border-gray-300 text-sm"
                            @change="refreshPrompt"
                        />
                    </div>

                    <textarea
                        ref="promptBox"
                        readonly
                        rows="14"
                        class="mt-4 block w-full rounded-md border-indigo-200 bg-white font-mono text-xs text-gray-800"
                        :value="activePrompt"
                    />

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <PrimaryButton type="button" @click="copyPrompt">
                            {{ copied ? 'Copied!' : 'Copy prompt' }}
                        </PrimaryButton>
                        <p v-if="copyError" class="text-sm text-red-700">{{ copyError }}</p>
                    </div>
                </div>

                <div v-if="isChapterScope && hasChapterPrompt" class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="font-medium text-gray-900">Step 2 — Paste JSON from Cursor</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Paste JSON from Cursor — each question needs a <strong>topic</strong> name matching this chapter.
                    </p>
                    <textarea
                        v-model="jsonInput"
                        rows="8"
                        class="mt-3 block w-full rounded-md border-gray-300 font-mono text-xs"
                        placeholder='{"questions":[{"topic":"Introduction to Integers","question":"(-12)+8=____","answer_format":"integer","correct_answer":"-4",...}]}'
                        @input="onJsonPaste"
                    />
                    <InputError class="mt-2" :message="previewError" />
                    <div class="mt-3 flex flex-wrap gap-3">
                        <SecondaryButton type="button" @click="loadPreview">Load preview</SecondaryButton>
                        <SecondaryButton type="button" @click="jsonFileInput?.click()">Upload .json</SecondaryButton>
                        <input ref="jsonFileInput" type="file" accept=".json,application/json" class="hidden" @change="onJsonFileSelected" />
                    </div>
                </div>

                <div v-if="isTopicScope && topic" class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="font-medium text-gray-900">Step 2 — Paste JSON from Cursor</h3>
                    <textarea
                        v-model="jsonInput"
                        rows="8"
                        class="mt-3 block w-full rounded-md border-gray-300 font-mono text-xs"
                        placeholder='{"questions":[{"question":"(-12)+8=____","answer_format":"integer","correct_answer":"-4",...}]}'
                        @input="onJsonPaste"
                    />
                    <InputError class="mt-2" :message="previewError" />
                    <div class="mt-3 flex flex-wrap gap-3">
                        <SecondaryButton type="button" @click="loadPreview">Load preview</SecondaryButton>
                        <SecondaryButton type="button" @click="jsonFileInput?.click()">Upload .json</SecondaryButton>
                    </div>
                </div>

                <div v-if="rows.length" ref="step3Ref" class="rounded-lg bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-medium text-gray-900">Step 3 — Review and save</h3>
                        <SecondaryButton type="button" @click="addRow">Add row</SecondaryButton>
                    </div>

                    <div class="mt-4 space-y-4">
                        <div
                            v-for="(row, index) in rows"
                            :key="index"
                            class="rounded-lg border border-gray-200 p-4"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-medium text-gray-700">Question {{ index + 1 }}</p>
                                <DangerButton type="button" class="!px-2 !py-1 text-xs" @click="removeRow(index)">
                                    Remove
                                </DangerButton>
                            </div>

                            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                <div v-if="isChapterScope" class="lg:col-span-2">
                                    <InputLabel value="Topic" />
                                    <input v-model="row.topic_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div class="lg:col-span-2">
                                    <InputLabel value="Question (use ____ for the blank)" />
                                    <textarea v-model="row.question_text" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <InputLabel value="Answer format" />
                                    <select v-model="row.answer_format" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                        <option v-for="format in fillBlankFormats" :key="format" :value="format">
                                            {{ format }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <InputLabel value="Correct answer" />
                                    <input v-model="row.correct_answer" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div v-if="row.answer_format === 'decimal'">
                                    <InputLabel value="Decimal places (optional)" />
                                    <input v-model.number="row.decimal_places" type="number" min="0" max="6" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <InputLabel value="Difficulty" />
                                    <input v-model="row.difficulty" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div class="lg:col-span-2">
                                    <InputLabel value="Method hint (theory only)" />
                                    <textarea v-model="row.method_hint" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div class="lg:col-span-2">
                                    <InputLabel value="Explanation (teacher only)" />
                                    <textarea v-model="row.explanation" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <p v-if="saveTopicError" class="mt-3 text-sm text-red-700">{{ saveTopicError }}</p>
                    <InputError class="mt-3" :message="saveForm.errors.rows || saveForm.errors.syllabus_topic_id || saveForm.errors.syllabus_chapter_id" />

                    <PrimaryButton
                        type="button"
                        class="mt-4"
                        :disabled="saveForm.processing"
                        @click="saveQuestions"
                    >
                        {{ saveForm.processing ? 'Saving…' : `Save ${rows.length} question(s) to practice bank` }}
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
