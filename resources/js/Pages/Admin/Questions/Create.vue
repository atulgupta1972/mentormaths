<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { parseMcqJson, rowsFromImportData } from '@/utils/mcqImport';

const props = defineProps({
    chapters: Array,
    chapterTopics: Array,
    topicsByChapter: Object,
    selectedChapterId: [Number, String, null],
    selectedTopicId: [Number, String, null],
    topic: Object,
    cursorPrompt: String,
    promptOptions: Object,
    importMode: { type: String, default: 'custom' },
    extractedPreview: String,
    pdfFileName: String,
    pdfExtracted: { type: Boolean, default: false },
    pdfDirectParsed: { type: Boolean, default: false },
    initialImportRows: Array,
    pageError: String,
});

const page = usePage();
const chapterFilter = ref(props.selectedChapterId || '');
const selectedTopic = ref(props.selectedTopicId || '');
const importMode = ref(props.importMode || 'custom');
const jsonInput = ref('');
const previewError = ref('');
const step3Ref = ref(null);
const jsonFileInput = ref(null);
const saveChapterId = ref(props.selectedChapterId || '');
const saveTopicId = ref(props.selectedTopicId || '');
const saveTopicError = ref('');
const copied = ref(false);
const copyError = ref('');
const promptBox = ref(null);
const pdfInput = ref(null);
const pdfResultBox = ref(null);
const selectedPdfName = ref('');

const promptSettings = ref({
    total: props.promptOptions?.total ?? 6,
    easy: props.promptOptions?.easy ?? 2,
    medium: props.promptOptions?.medium ?? 2,
    hard: props.promptOptions?.hard ?? 2,
    focus: props.promptOptions?.focus ?? '',
});

const pdfForm = useForm({
    syllabus_topic_id: props.selectedTopicId || '',
    pdf: null,
    pdf_mode: 'sums',
});

const importForm = useForm({
    syllabus_topic_id: props.selectedTopicId || '',
    json: '',
});

const rows = ref([]);

const applyImportRows = (importRows) => {
    if (!importRows?.length) {
        return;
    }
    rows.value = rowsFromImportData(importRows);
    nextTick(() => {
        step3Ref.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        resizeMcqFields();
    });
};

const saveForm = useForm({
    syllabus_topic_id: props.selectedTopicId || '',
    rows: [],
});

const saveTopics = computed(() => {
    if (!saveChapterId.value || !props.topicsByChapter) {
        return props.chapterTopics || [];
    }

    return props.topicsByChapter[saveChapterId.value] || props.topicsByChapter[String(saveChapterId.value)] || [];
});

const selectedSaveTopicLabel = computed(() => {
    const topic = saveTopics.value.find((t) => String(t.id) === String(saveTopicId.value));
    if (!topic) {
        return '';
    }

    const chapter = props.chapters?.find((c) => String(c.id) === String(saveChapterId.value));
    return chapter ? `${chapter.label} → ${topic.name}` : topic.name;
});

watch(saveChapterId, (id, oldId) => {
    if (id === oldId) {
        return;
    }

    const stillValid = saveTopics.value.some((t) => String(t.id) === String(saveTopicId.value));
    if (!stillValid) {
        saveTopicId.value = '';
    }
});

const modes = [
    {
        id: 'custom',
        title: 'Custom prompt',
        hint: 'Set count, difficulty split, and sum types',
    },
    {
        id: 'pdf_sums',
        title: 'PDF → MCQ',
        hint: 'Upload sums; convert to MCQs in Cursor',
    },
    {
        id: 'pdf_mcq',
        title: 'PDF with MCQs',
        hint: 'Upload MCQ sheet — auto-parse when possible',
    },
];

const difficultySum = computed(
    () => Number(promptSettings.value.easy) + Number(promptSettings.value.medium) + Number(promptSettings.value.hard),
);

const difficultyMismatch = computed(
    () => difficultySum.value !== Number(promptSettings.value.total),
);

const activePrompt = computed(() => {
    if (!props.cursorPrompt) {
        return 'Select a topic first to generate the Cursor prompt.';
    }

    return props.cursorPrompt;
});

const modeTitle = computed(() => {
    if (importMode.value === 'pdf_sums') {
        return 'Step 1 — PDF sums → Cursor prompt';
    }
    if (importMode.value === 'pdf_mcq') {
        return 'Step 1 — PDF MCQs → Cursor prompt';
    }

    return 'Step 1 — Custom prompt → Cursor';
});

watch(chapterFilter, (id, oldId) => {
    if (id === oldId) {
        return;
    }
    selectedTopic.value = '';
    if (id) {
        router.get(
            route('admin.questions.create', { syllabus_chapter_id: id }),
            {},
            { preserveState: false },
        );
    }
});

watch(selectedTopic, (id, oldId) => {
    if (!id || id === oldId) {
        return;
    }
    router.get(
        route('admin.questions.create', buildQueryParams({ syllabus_topic_id: id })),
        {},
        { preserveState: false },
    );
});

function defaultOptions() {
    return [
        { option_text: '', is_correct: true, sort_order: 1 },
        { option_text: '', is_correct: false, sort_order: 2 },
        { option_text: '', is_correct: false, sort_order: 3 },
        { option_text: '', is_correct: false, sort_order: 4 },
    ];
}

function buildQueryParams(overrides = {}) {
    const params = {
        syllabus_chapter_id: chapterFilter.value || props.selectedChapterId || undefined,
        syllabus_topic_id: selectedTopic.value || props.selectedTopicId || undefined,
        mode: importMode.value,
        ...overrides,
    };

    if (importMode.value === 'custom') {
        params.total = promptSettings.value.total;
        params.easy = promptSettings.value.easy;
        params.medium = promptSettings.value.medium;
        params.hard = promptSettings.value.hard;
        if (promptSettings.value.focus) {
            params.focus = promptSettings.value.focus;
        }
    }

    return params;
}

const switchMode = (mode) => {
    importMode.value = mode;
    if (selectedTopic.value) {
        router.get(route('admin.questions.create', buildQueryParams({ mode })), {}, { preserveState: false });
    }
};

const refreshCustomPrompt = () => {
    if (!selectedTopic.value) {
        return;
    }

    router.get(route('admin.questions.create', buildQueryParams()), {}, { preserveState: false });
};

const onPdfSelected = (event) => {
    const file = event.target.files?.[0] ?? null;
    pdfForm.pdf = file;
    selectedPdfName.value = file?.name ?? '';
    pdfForm.clearErrors();
};

const extractPdf = () => {
    if (!selectedTopic.value) {
        pdfForm.setError('syllabus_topic_id', 'Select a syllabus topic first.');
        return;
    }

    if (!pdfForm.pdf) {
        pdfForm.setError('pdf', 'Choose a PDF file first.');
        return;
    }

    pdfForm.syllabus_topic_id = selectedTopic.value;
    pdfForm.pdf_mode = importMode.value === 'pdf_mcq' ? 'mcq' : 'sums';
    pdfForm.post(route('admin.questions.extract-pdf'), {
        forceFormData: true,
        preserveScroll: false,
        onSuccess: () => {
            nextTick(() => {
                pdfResultBox.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        },
        onFinish: () => {
            if (!pdfForm.hasErrors) {
                if (pdfInput.value) {
                    pdfInput.value.value = '';
                }
                selectedPdfName.value = '';
                pdfForm.reset('pdf');
            }
        },
    });
};

const loadPreview = () => {
    importForm.clearErrors();
    previewError.value = '';

    if (!jsonInput.value.trim()) {
        importForm.setError('json', 'Paste JSON or upload a .json file first.');
        return;
    }

    try {
        rows.value = rowsFromImportData(parseMcqJson(jsonInput.value));
        if (!saveTopicId.value && selectedTopic.value) {
            saveTopicId.value = selectedTopic.value;
            saveChapterId.value = chapterFilter.value || props.selectedChapterId || '';
        }
        nextTick(() => {
            step3Ref.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            resizeMcqFields();
        });
    } catch (error) {
        previewError.value = error.message || 'Could not parse JSON.';
        importForm.setError('json', previewError.value);
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

    if (promptBox.value) {
        promptBox.value.focus();
        promptBox.value.select();
        try {
            document.execCommand('copy');
            copied.value = true;
            setTimeout(() => (copied.value = false), 2500);
            return;
        } catch {
            // Show manual copy hint.
        }
    }

    copyError.value = 'Auto-copy blocked by browser. Click inside the prompt box below, press Ctrl+A then Ctrl+C.';
};

const selectPrompt = () => {
    promptBox.value?.select();
};

const addRow = () => {
    rows.value.push({
        question_text: '',
        explanation: '',
        difficulty: 'Medium',
        options: defaultOptions(),
    });
};

const removeRow = (index) => {
    rows.value.splice(index, 1);
};

const setCorrect = (row, optionIndex) => {
    row.options.forEach((opt, i) => {
        opt.is_correct = i === optionIndex;
    });
};

const saveToBank = () => {
    saveTopicError.value = '';

    if (!saveTopicId.value) {
        saveTopicError.value = 'Choose chapter and topic to save into.';
        return;
    }

    saveForm.syllabus_topic_id = saveTopicId.value;
    saveForm.rows = rows.value;
    saveForm.post(route('admin.questions.bulk-store'));
};

const autoResize = (event) => {
    const el = event?.target ?? event;
    if (!el) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
};

const resizeMcqFields = () => {
    nextTick(() => {
        document.querySelectorAll('.mcq-field').forEach((el) => autoResize(el));
    });
};

onMounted(() => {
    applyImportRows(page.props.flash?.import_rows || props.initialImportRows);
    if (props.initialImportRows?.length) {
        saveChapterId.value = props.selectedChapterId || saveChapterId.value;
        saveTopicId.value = props.selectedTopicId || saveTopicId.value;
    }
    resizeMcqFields();
});
watch(rows, resizeMcqFields, { deep: true });
</script>

<template>
    <Head title="Add MCQs" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">Add MCQs</h2>
                <Link
                    :href="selectedTopic ? route('admin.questions.topics.show', selectedTopic) : route('admin.questions.index')"
                    class="text-sm text-indigo-600"
                >
                    Question bank
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="$page.props.flash?.error || pageError" class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                    {{ pageError || $page.props.flash?.error }}
                </div>

                <div v-if="pdfExtracted" class="rounded-md bg-green-50 p-4 text-sm text-green-800">
                    <template v-if="pdfDirectParsed">
                        PDF MCQs parsed automatically — review in Step 3 below. You can change chapter/topic before saving.
                    </template>
                    <template v-else>
                        PDF read successfully. The prompt below now includes your worksheet text — copy it to Cursor, or paste JSON in Step 2.
                    </template>
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

                <div v-if="topic" class="rounded-lg bg-white p-2 shadow-sm">
                    <div class="grid gap-2 sm:grid-cols-3">
                        <button
                            v-for="mode in modes"
                            :key="mode.id"
                            type="button"
                            class="rounded-lg border p-4 text-left transition"
                            :class="importMode === mode.id
                                ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
                                : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'"
                            @click="switchMode(mode.id)"
                        >
                            <p class="font-medium text-gray-900">{{ mode.title }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ mode.hint }}</p>
                        </button>
                    </div>
                </div>

                <div v-if="topic && importMode === 'custom'" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                    <h3 class="font-medium text-gray-900">Question settings</h3>
                    <p class="mt-1 text-sm text-gray-600">Control how many sums Cursor generates and what to focus on.</p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <InputLabel value="Total questions" />
                            <input
                                v-model.number="promptSettings.total"
                                type="number"
                                min="1"
                                max="50"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            />
                        </div>
                        <div>
                            <InputLabel value="Easy" />
                            <input
                                v-model.number="promptSettings.easy"
                                type="number"
                                min="0"
                                max="50"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            />
                        </div>
                        <div>
                            <InputLabel value="Medium" />
                            <input
                                v-model.number="promptSettings.medium"
                                type="number"
                                min="0"
                                max="50"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            />
                        </div>
                        <div>
                            <InputLabel value="Hard" />
                            <input
                                v-model.number="promptSettings.hard"
                                type="number"
                                min="0"
                                max="50"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            />
                        </div>
                    </div>

                    <p
                        class="mt-2 text-xs"
                        :class="difficultyMismatch ? 'text-amber-700' : 'text-gray-500'"
                    >
                        Difficulty split: {{ difficultySum }} / {{ promptSettings.total }}
                        <span v-if="difficultyMismatch"> — should match total (prompt will auto-adjust if needed)</span>
                    </p>

                    <div class="mt-4">
                        <InputLabel value="Focus on specific sum types (optional)" />
                        <textarea
                            v-model="promptSettings.focus"
                            rows="3"
                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            placeholder="e.g. word problems on fraction × whole number; no diagram questions; include 2 sums with mixed fractions"
                        />
                    </div>

                    <PrimaryButton type="button" class="mt-4" @click="refreshCustomPrompt">
                        Build prompt with these settings
                    </PrimaryButton>
                </div>

                <div
                    v-if="topic && (importMode === 'pdf_sums' || importMode === 'pdf_mcq')"
                    class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
                >
                    <h3 class="font-medium text-gray-900">
                        {{ importMode === 'pdf_sums' ? 'Upload PDF with sums' : 'Upload PDF with MCQs' }}
                    </h3>
                    <p class="mt-1 text-sm text-gray-600">
                        <template v-if="importMode === 'pdf_sums'">
                            We read the PDF text and build a Cursor prompt to turn each sum into a 4-option MCQ.
                        </template>
                        <template v-else>
                            We try to parse MCQs directly from the PDF (no copy-paste). If that fails, a Cursor prompt is shown as fallback.
                        </template>
                    </p>

                    <input
                        ref="pdfInput"
                        type="file"
                        accept="application/pdf,.pdf"
                        class="mt-4 block w-full max-w-lg text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700"
                        @change="onPdfSelected"
                    />

                    <p v-if="selectedPdfName" class="mt-2 text-sm font-medium text-gray-700">
                        Selected: {{ selectedPdfName }}
                    </p>

                    <InputError class="mt-2" :message="pdfForm.errors.pdf" />
                    <InputError class="mt-1" :message="pdfForm.errors.syllabus_topic_id" />
                    <InputError class="mt-1" :message="pdfForm.errors.pdf_mode" />

                    <p class="mt-2 text-xs text-gray-500">
                        Text-based PDF only (not scanned photos). Max 10 MB. Then click the button below.
                    </p>

                    <PrimaryButton
                        type="button"
                        class="mt-4"
                        :disabled="pdfForm.processing || !pdfForm.pdf"
                        @click="extractPdf"
                    >
                        {{ pdfForm.processing ? 'Reading PDF…' : 'Extract text & build prompt' }}
                    </PrimaryButton>

                    <div
                        v-if="pdfFileName || extractedPreview"
                        ref="pdfResultBox"
                        class="mt-4 rounded-md bg-gray-50 p-4"
                    >
                        <p v-if="pdfFileName" class="text-sm font-medium text-gray-800">
                            Last file: {{ pdfFileName }}
                        </p>
                        <p v-if="extractedPreview" class="mt-2 max-h-40 overflow-y-auto whitespace-pre-wrap font-mono text-xs text-gray-600">
                            {{ extractedPreview }}
                            <span v-if="extractedPreview.length >= 3000">…</span>
                        </p>
                    </div>
                </div>

                <div v-if="topic" class="rounded-lg border border-indigo-100 bg-indigo-50 p-6">
                    <h3 class="font-medium text-indigo-900">{{ modeTitle }}</h3>

                    <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-indigo-950">
                        <li>Click <strong>Copy prompt</strong> below (or select all text in the box and press <kbd class="rounded bg-white px-1">Ctrl+C</kbd>).</li>
                        <li>Open <strong>Cursor</strong> on your computer (this IDE — not the browser).</li>
                        <li>Open the <strong>Chat</strong> panel in Cursor (<kbd class="rounded bg-white px-1">Ctrl+L</kbd> or chat icon on the right).</li>
                        <li><strong>Paste</strong> there with <kbd class="rounded bg-white px-1">Ctrl+V</kbd> and press Enter.</li>
                        <li>When Cursor replies with JSON, copy that JSON and paste it in <strong>Step 2</strong> on this page.</li>
                    </ol>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <SecondaryButton type="button" @click="copyPrompt">
                            {{ copied ? 'Copied! Now paste in Cursor chat' : 'Copy prompt' }}
                        </SecondaryButton>
                        <SecondaryButton type="button" @click="selectPrompt">Select all</SecondaryButton>
                    </div>

                    <p v-if="copyError" class="mt-2 text-sm font-medium text-amber-800">{{ copyError }}</p>
                    <p v-else-if="copied" class="mt-2 text-sm font-medium text-green-800">
                        Copied! Switch to Cursor → Chat panel → Ctrl+V → Enter.
                    </p>

                    <textarea
                        ref="promptBox"
                        :value="activePrompt"
                        readonly
                        rows="14"
                        class="mt-3 w-full cursor-text rounded-md border border-indigo-200 bg-white p-3 font-mono text-xs text-gray-800"
                        @focus="selectPrompt"
                    />
                    <p class="mt-2 text-xs text-indigo-800">
                        Tip: You can also click inside this box, press Ctrl+A then Ctrl+C if the button does not work.
                    </p>
                </div>

                <div v-if="topic" class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="font-medium text-gray-900">Step 2 — Import JSON (no Cursor needed if you already have JSON)</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Paste JSON, upload a <strong>.json</strong> file, or paste from Cursor — preview loads automatically.
                    </p>
                    <textarea
                        v-model="jsonInput"
                        rows="8"
                        class="mt-3 w-full rounded-md border-gray-300 font-mono text-xs"
                        placeholder='{"questions": [{"question": "...", "options": ["A","B","C","D"], "correct_index": 0, ...}]}'
                        @paste="onJsonPaste"
                    />
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <PrimaryButton type="button" :disabled="!jsonInput.trim()" @click="loadPreview">
                            Load preview
                        </PrimaryButton>
                        <SecondaryButton type="button" @click="jsonFileInput?.click()">
                            Upload .json file
                        </SecondaryButton>
                        <input
                            ref="jsonFileInput"
                            type="file"
                            accept=".json,application/json"
                            class="hidden"
                            @change="onJsonFileSelected"
                        />
                    </div>
                    <InputError class="mt-2" :message="previewError || importForm.errors.json" />
                    <p v-if="rows.length" class="mt-2 text-sm text-green-700">
                        {{ rows.length }} question(s) loaded — scroll down to review, pick chapter/topic, and save.
                    </p>
                </div>

                <div v-if="rows.length" id="step3-preview" ref="step3Ref" class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="border-b px-4 py-3 space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h3 class="font-medium">Step 3 — Review &amp; save ({{ rows.length }} questions)</h3>
                            <div class="flex gap-2">
                                <SecondaryButton type="button" @click="addRow">Add row</SecondaryButton>
                                <PrimaryButton type="button" :disabled="saveForm.processing" @click="saveToBank">
                                    Save to question bank
                                </PrimaryButton>
                            </div>
                        </div>

                        <div class="rounded-md bg-amber-50 p-4 text-sm text-amber-900">
                            <p class="font-medium">Save destination — change here if you picked the wrong topic earlier</p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <InputLabel value="Chapter" />
                                    <select v-model="saveChapterId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                        <option value="">Select chapter</option>
                                        <option v-for="ch in chapters" :key="ch.id" :value="ch.id">{{ ch.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <InputLabel value="Topic" />
                                    <select v-model="saveTopicId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                        <option value="">Select topic</option>
                                        <option v-for="t in saveTopics" :key="t.id" :value="t.id">
                                            {{ t.name }} ({{ t.questions_count }} in bank)
                                        </option>
                                    </select>
                                </div>
                            </div>
                            <p v-if="selectedSaveTopicLabel" class="mt-2 text-xs text-amber-800">
                                Will save to: <strong>{{ selectedSaveTopicLabel }}</strong>
                            </p>
                            <InputError class="mt-2" :message="saveTopicError" />
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-2 py-3 text-left text-xs uppercase text-gray-500">Question</th>
                                    <th class="min-w-[280px] px-2 py-3 text-left text-xs uppercase text-gray-500">Options (click letter for correct)</th>
                                    <th class="px-2 py-3 text-left text-xs uppercase text-gray-500">Explanation</th>
                                    <th class="px-2 py-3 text-left text-xs uppercase text-gray-500">Difficulty</th>
                                    <th class="px-2 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="(row, rowIndex) in rows" :key="rowIndex">
                                    <td class="align-top px-2 py-2">
                                        <textarea
                                            v-model="row.question_text"
                                            rows="2"
                                            class="mcq-field w-full min-w-[200px] rounded-md border-gray-300 text-sm"
                                            @input="autoResize"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <div v-for="(opt, optIndex) in row.options" :key="optIndex" class="mb-2 flex items-start gap-2">
                                            <button
                                                type="button"
                                                class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                                :class="opt.is_correct ? 'bg-green-600 text-white' : 'bg-gray-200 text-gray-700'"
                                                @click="setCorrect(row, optIndex)"
                                            >
                                                {{ String.fromCharCode(65 + optIndex) }}
                                            </button>
                                            <textarea
                                                v-model="opt.option_text"
                                                rows="2"
                                                class="mcq-field w-full min-w-[220px] rounded-md border-gray-300 text-sm"
                                                @input="autoResize"
                                            />
                                        </div>
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <textarea
                                            v-model="row.explanation"
                                            rows="2"
                                            class="mcq-field w-full min-w-[180px] rounded-md border-gray-300 text-sm"
                                            @input="autoResize"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <select v-model="row.difficulty" class="rounded-md border-gray-300 text-sm">
                                            <option value="">—</option>
                                            <option value="Easy">Easy</option>
                                            <option value="Medium">Medium</option>
                                            <option value="Hard">Hard</option>
                                        </select>
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <DangerButton type="button" class="!px-2 !py-1 text-xs" @click="removeRow(rowIndex)">
                                            Remove
                                        </DangerButton>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.mcq-field {
    resize: vertical;
    min-height: 2.5rem;
    line-height: 1.4;
    overflow: hidden;
}
</style>
