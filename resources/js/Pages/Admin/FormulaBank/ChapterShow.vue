<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formulaPreviewRowsToJson, parseFormulaImportPreview } from '@/utils/formulaImportPreview';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    chapter: { type: Object, required: true },
    grade: { type: Object, required: true },
    board: { type: Object, required: true },
    topics: { type: Array, default: () => [] },
    formulas_count: { type: Number, default: 0 },
    sets_count: { type: Number, default: 0 },
    topic_sets_count: { type: Number, default: 0 },
    cards: { type: Array, default: () => [] },
    cursorPrompt: { type: String, default: null },
    promptDefaults: { type: Object, default: () => ({}) },
});

const page = usePage();
const copied = ref(false);
const promptBox = ref(null);
const previewRows = ref([]);
const previewError = ref('');
const deleteForm = useForm({});
const consolidateForm = useForm({});

const cursorPromptText = computed(() => props.cursorPrompt || page.props.flash?.formula_bank_chapter_prompt || '');
const unmatchedCount = computed(() => previewRows.value.filter((row) => row.topic && !row.topic_matched).length);
const canConsolidate = computed(() => props.formulas_count > 0 && props.topic_sets_count > 0);

const promptForm = useForm({
    total: props.promptDefaults?.total || 12,
    focus: props.promptDefaults?.focus || '',
    style: props.promptDefaults?.style || 'mixed',
    topic_ids: props.promptDefaults?.topic_ids?.length
        ? [...props.promptDefaults.topic_ids]
        : props.topics.map((topic) => topic.id),
});

const importForm = useForm({
    json: '',
    create_sets: true,
});

watch(
    () => cursorPromptText.value,
    (value) => {
        if (value) {
            copied.value = false;
        }
    },
);

const toggleTopic = (topicId) => {
    const id = Number(topicId);
    if (promptForm.topic_ids.includes(id)) {
        promptForm.topic_ids = promptForm.topic_ids.filter((item) => item !== id);
    } else {
        promptForm.topic_ids = [...promptForm.topic_ids, id];
    }
};

const generatePrompt = () => {
    promptForm.post(route('admin.formula-bank.chapters.prompt', props.chapter.id), {
        preserveScroll: true,
    });
};

const copyPrompt = async () => {
    if (!cursorPromptText.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(cursorPromptText.value);
        copied.value = true;
    } catch {
        promptBox.value?.select();
        document.execCommand('copy');
        copied.value = true;
    }
};

const loadPreview = () => {
    const result = parseFormulaImportPreview(importForm.json, props.topics);
    previewError.value = result.error || '';
    previewRows.value = result.rows;
};

const discardPreview = () => {
    previewRows.value = [];
    previewError.value = '';
};

const removePreviewRow = (index) => {
    previewRows.value = previewRows.value.filter((_, i) => i !== index);
};

const setCorrectOption = (rowIndex, optionIndex) => {
    const row = previewRows.value[rowIndex];
    if (!row) {
        return;
    }
    row.correct_index = optionIndex;
};

const submitImport = () => {
    if (!previewRows.value.length) {
        previewError.value = 'Preview formulas first, then save.';

        return;
    }

    if (unmatchedCount.value > 0) {
        previewError.value = `${unmatchedCount.value} card(s) have a topic name that does not match this chapter. Fix or remove them before saving.`;

        return;
    }

    importForm.json = formulaPreviewRowsToJson(previewRows.value);
    importForm.post(route('admin.formula-bank.chapters.import', props.chapter.id), {
        preserveScroll: true,
        onSuccess: () => {
            importForm.reset('json');
            importForm.create_sets = true;
            discardPreview();
        },
    });
};

const deleteCard = (card) => {
    if (!confirm(`Delete this card?\n\n${card.question_text}`)) {
        return;
    }

    deleteForm.delete(route('admin.formula-bank.cards.destroy', card.id), {
        preserveScroll: true,
    });
};

const consolidateChapter = () => {
    if (!confirm('Combine all formula cards in this chapter into one assignable set? Separate topic sets will be removed (existing student assignments are moved to the chapter set).')) {
        return;
    }

    consolidateForm.post(route('admin.formula-bank.chapters.consolidate', props.chapter.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Formulas · ${chapter.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-amber-700">Formula bank</p>
                    <h2 class="text-xl font-semibold text-gray-800">
                        Ch {{ chapter.chapter_number }} · {{ chapter.name }}
                    </h2>
                    <p class="text-sm text-gray-500">
                        {{ grade.name }} · {{ board.code }} · {{ formulas_count }} cards · {{ sets_count }} sets
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 text-sm">
                    <button
                        v-if="canConsolidate"
                        type="button"
                        class="rounded-md bg-violet-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-violet-800 disabled:opacity-50"
                        :disabled="consolidateForm.processing"
                        @click="consolidateChapter"
                    >
                        {{ consolidateForm.processing ? 'Merging…' : 'Merge into one chapter set' }}
                    </button>
                    <Link
                        :href="route('admin.formula-bank.index', { board_id: board.id, grade_id: grade.id })"
                        class="font-medium text-amber-800 hover:underline"
                    >
                        ← Formula summary
                    </Link>
                    <Link
                        :href="`${route('admin.formula-bank.classes.show', grade.id)}?board_id=${board.id}`"
                        class="text-gray-600 hover:underline"
                    >
                        Class topics
                    </Link>
                    <Link
                        :href="route('admin.questions.chapters.show', chapter.id)"
                        class="text-gray-600 hover:underline"
                    >
                        Question bank
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
                >
                    {{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash?.error"
                    class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
                >
                    {{ page.props.flash.error }}
                </div>

                <div id="all-formulas" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-3 py-2">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">All formula / concept cards</h3>
                            <p class="text-[11px] text-gray-500">{{ cards.length }} cards · delete wrong / calculation ones</p>
                        </div>
                        <a href="#prompt-builder" class="text-[11px] font-medium text-amber-800 hover:underline">Add more ↓</a>
                    </div>

                    <div v-if="!cards.length" class="px-3 py-4 text-sm text-gray-500">
                        No cards yet — generate a Cursor prompt below, preview, then save.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full text-xs leading-tight">
                            <thead class="bg-gray-50 text-left text-[10px] uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="w-8 px-2 py-1">#</th>
                                    <th class="px-2 py-1">Topic</th>
                                    <th class="px-2 py-1">Formula / concept</th>
                                    <th class="px-2 py-1">Answer</th>
                                    <th class="w-12 px-2 py-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="card in cards"
                                    :key="card.id"
                                    class="border-t border-gray-100 hover:bg-amber-50/40"
                                >
                                    <td class="px-2 py-0.5 align-middle text-gray-400">{{ card.number }}</td>
                                    <td class="max-w-[9rem] truncate px-2 py-0.5 align-middle font-medium text-indigo-800" :title="card.topic_name">
                                        {{ card.topic_name }}
                                    </td>
                                    <td class="px-2 py-0.5 align-middle text-gray-900">
                                        <span class="line-clamp-1" :title="card.question_text">{{ card.question_text }}</span>
                                        <span
                                            v-if="card.options?.length"
                                            class="mt-0.5 block truncate text-[10px] text-gray-500"
                                            :title="card.options.map((opt, i) => `${String.fromCharCode(65 + i)}) ${opt.text}`).join(' · ')"
                                        >
                                            <template v-for="(opt, i) in card.options" :key="i">
                                                <span :class="opt.is_correct ? 'font-semibold text-emerald-700' : ''">{{ String.fromCharCode(65 + i) }}) {{ opt.text }}</span>
                                                <span v-if="i < card.options.length - 1"> · </span>
                                            </template>
                                        </span>
                                    </td>
                                    <td class="max-w-[8rem] truncate px-2 py-0.5 align-middle font-semibold text-emerald-800" :title="card.correct_answer">
                                        {{ card.correct_answer || '—' }}
                                    </td>
                                    <td class="px-2 py-0.5 align-middle text-right">
                                        <button
                                            type="button"
                                            class="text-[11px] font-medium text-rose-600 hover:underline"
                                            :disabled="deleteForm.processing"
                                            @click="deleteCard(card)"
                                        >
                                            Del
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="prompt-builder" class="rounded-lg border border-amber-200 bg-amber-50/50 p-5 shadow-sm">
                    <h3 class="font-medium text-amber-950">Generate Cursor prompt for this chapter</h3>
                    <p class="mt-1 text-sm text-amber-900">
                        Prompt asks only for <strong>formulas, concepts, and True/False</strong> — no calculation sums.
                    </p>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="How many cards?" class="!text-xs" />
                            <input
                                v-model.number="promptForm.total"
                                type="number"
                                min="1"
                                max="60"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            >
                            <InputError :message="promptForm.errors.total" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Card style" class="!text-xs" />
                            <select v-model="promptForm.style" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                <option value="mixed">Formulas + concepts + True/False</option>
                                <option value="formula_recall">Formulas / identities only</option>
                                <option value="concept">Concepts / definitions only</option>
                                <option value="true_false">True / False only</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <InputLabel value="Describe formulas / concepts to cover" class="!text-xs" />
                        <textarea
                            v-model="promptForm.focus"
                            rows="4"
                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            placeholder="e.g. Integer: sign rules, addition/subtraction of integers · Absolute value · Number line concepts as MCQs"
                        />
                        <InputError :message="promptForm.errors.focus" class="mt-1" />
                    </div>

                    <div class="mt-3">
                        <InputLabel value="Topics to include" class="!text-xs" />
                        <div class="mt-2 flex flex-wrap gap-2">
                            <label
                                v-for="topic in topics"
                                :key="topic.id"
                                class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs"
                                :class="promptForm.topic_ids.includes(topic.id)
                                    ? 'border-amber-400 bg-amber-100 text-amber-950'
                                    : 'border-gray-200 bg-white text-gray-600'"
                            >
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300"
                                    :checked="promptForm.topic_ids.includes(topic.id)"
                                    @change="toggleTopic(topic.id)"
                                >
                                {{ topic.name }}
                            </label>
                        </div>
                        <InputError :message="promptForm.errors.topic_ids" class="mt-1" />
                    </div>

                    <PrimaryButton
                        type="button"
                        class="mt-4 !bg-amber-700 hover:!bg-amber-800"
                        :disabled="promptForm.processing || !promptForm.topic_ids.length"
                        @click="generatePrompt"
                    >
                        {{ promptForm.processing ? 'Building…' : 'Generate Cursor prompt' }}
                    </PrimaryButton>

                    <div v-if="cursorPromptText" class="mt-4 rounded-md border border-amber-200 bg-white p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Copy → paste in Cursor</p>
                            <SecondaryButton type="button" class="!py-1 !text-xs" @click="copyPrompt">
                                {{ copied ? 'Copied!' : 'Copy prompt' }}
                            </SecondaryButton>
                        </div>
                        <textarea
                            ref="promptBox"
                            :value="cursorPromptText"
                            readonly
                            rows="14"
                            class="mt-2 w-full rounded-md border-gray-300 font-mono text-xs"
                            @focus="$event.target.select()"
                        />
                        <p class="mt-2 text-xs text-gray-600">
                            After Cursor returns JSON, paste it in step 2 below (no need to open each topic).
                        </p>
                    </div>
                </div>

                <div class="rounded-lg border border-indigo-200 bg-indigo-50/40 p-5 shadow-sm">
                    <h3 class="font-medium text-indigo-950">2. Paste JSON → preview → save</h3>
                    <p class="mt-1 text-sm text-indigo-900">
                        Paste Cursor JSON, click <strong>Preview formulas</strong>, verify each card, then save.
                        Each question should include a <code class="rounded bg-white px-1">topic</code> name.
                    </p>
                    <label class="mt-3 flex items-center gap-2 text-sm text-gray-800">
                        <input v-model="importForm.create_sets" type="checkbox" class="rounded border-gray-300">
                        Create one formula set for the whole chapter when saving
                    </label>
                    <textarea
                        v-model="importForm.json"
                        rows="10"
                        class="mt-3 w-full rounded-md border-gray-300 font-mono text-xs"
                        placeholder='{"questions":[{"topic":"Introduction to Integers","question":"...","options":["A","B","C","D"],"correct_index":0}]}'
                        @input="discardPreview"
                    />
                    <InputError :message="importForm.errors.json" class="mt-1" />
                    <p v-if="previewError" class="mt-2 text-sm text-rose-700">{{ previewError }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <SecondaryButton
                            type="button"
                            class="!py-1.5 !text-xs"
                            :disabled="!importForm.json.trim()"
                            @click="loadPreview"
                        >
                            Preview formulas
                        </SecondaryButton>
                        <PrimaryButton
                            type="button"
                            class="!py-1.5 !text-xs"
                            :disabled="importForm.processing || !previewRows.length"
                            @click="submitImport"
                        >
                            {{ importForm.processing ? 'Saving…' : `Save ${previewRows.length || ''} verified formula${previewRows.length === 1 ? '' : 's'}` }}
                        </PrimaryButton>
                        <SecondaryButton
                            v-if="previewRows.length"
                            type="button"
                            class="!py-1.5 !text-xs"
                            @click="discardPreview"
                        >
                            Discard preview
                        </SecondaryButton>
                    </div>
                </div>

                <div v-if="previewRows.length" class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-indigo-800">
                            Preview ({{ previewRows.length }}) — verify before saving
                        </h3>
                        <p v-if="unmatchedCount" class="text-xs font-medium text-rose-700">
                            {{ unmatchedCount }} topic name(s) not found in this chapter
                        </p>
                    </div>
                    <div
                        v-for="(row, rowIndex) in previewRows"
                        :key="row.key"
                        class="rounded-lg bg-white p-4 shadow-sm ring-1"
                        :class="row.topic && !row.topic_matched ? 'ring-rose-300' : 'ring-gray-200'"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Card {{ rowIndex + 1 }}
                                    <span v-if="row.topic" class="ml-2 normal-case text-indigo-700">· {{ row.topic }}</span>
                                    <span v-if="row.topic && !row.topic_matched" class="ml-2 normal-case text-rose-700">· topic not found</span>
                                </p>
                                <p class="mt-1 font-medium text-gray-900">{{ row.question }}</p>
                            </div>
                            <button type="button" class="text-xs text-rose-600 hover:underline" @click="removePreviewRow(rowIndex)">
                                Remove
                            </button>
                        </div>
                        <ul class="mt-3 space-y-1.5 text-sm">
                            <li
                                v-for="(option, optionIndex) in row.options"
                                :key="`${row.key}-${optionIndex}`"
                            >
                                <button
                                    type="button"
                                    class="w-full rounded-md px-3 py-1.5 text-left"
                                    :class="row.correct_index === optionIndex
                                        ? 'bg-emerald-50 font-medium text-emerald-900 ring-1 ring-emerald-200'
                                        : 'bg-gray-50 text-gray-700 hover:bg-gray-100'"
                                    @click="setCorrectOption(rowIndex, optionIndex)"
                                >
                                    {{ String.fromCharCode(65 + optionIndex) }}. {{ option }}
                                    <span v-if="row.correct_index === optionIndex" class="ml-1 text-xs">✓ correct</span>
                                </button>
                            </li>
                        </ul>
                        <p v-if="row.explanation" class="mt-2 text-xs text-gray-500">{{ row.explanation }}</p>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-600">
                        Topics in this chapter (open one to edit sets or add more cards).
                    </p>
                </div>

                <div v-if="!topics.length" class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
                    No topics in this chapter yet.
                </div>

                <div class="divide-y divide-gray-100 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <Link
                        v-for="topic in topics"
                        :key="topic.id"
                        :href="route('admin.formula-bank.topics.show', topic.id)"
                        class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-amber-50/60"
                    >
                        <div>
                            <p class="font-medium text-gray-900">{{ topic.name }}</p>
                            <p class="text-xs text-gray-500">Open to review / add more formula sets</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-900 ring-1 ring-amber-200">
                            {{ topic.formulas_count }} cards · {{ topic.sets_count }} sets
                        </span>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
