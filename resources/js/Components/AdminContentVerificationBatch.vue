<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const PAGE_SIZE = 10;

const props = defineProps({
    task: { type: Object, required: true },
    verification: { type: Object, required: true },
    batchVerifyRoute: { type: String, required: true },
    returnRoute: { type: String, required: true },
    skipRoute: { type: String, default: '' },
    unskipRoute: { type: String, default: '' },
    uploadDiagramRoute: { type: String, default: '' },
    removeDiagramRoute: { type: String, default: '' },
    saveQuestionRoute: { type: String, default: '' },
    canReturn: { type: Boolean, default: false },
});

const pageIndex = ref(0);
const selectedIds = ref([]);
const expandedId = ref(null);
const editingId = ref(null);
const questionForms = reactive({});
const flagRemarks = reactive({});
const sendBackCart = ref([]);
const overallReason = ref('');
const diagramUploading = ref({});
const skippingId = ref(null);

const batchForm = useForm({
    run_id: props.verification?.run_id ?? null,
    question_ids: [],
});

const returnForm = useForm({
    reason: '',
    items: [],
});

const skipForm = useForm({
    run_id: props.verification?.run_id ?? null,
    question_id: null,
    skip_reason: '',
});

const canSkip = computed(() => Boolean(props.skipRoute) && props.skipRoute !== '#');

watch(
    () => props.verification?.run_id,
    (runId) => {
        batchForm.run_id = runId;
    },
);

const allQuestions = computed(() => props.verification?.questions ?? []);
const pendingQuestions = computed(() => allQuestions.value.filter((q) => !q.is_verified));
const skippedQuestions = computed(() => allQuestions.value.filter((q) => q.is_skipped));
const verifiedCount = computed(() => props.verification?.summary?.verified ?? 0);
const skippedCount = computed(() => props.verification?.summary?.skipped ?? skippedQuestions.value.length);
const totalCount = computed(() => props.verification?.summary?.total ?? 0);

const totalPages = computed(() => Math.max(1, Math.ceil(pendingQuestions.value.length / PAGE_SIZE)));

watch(pendingQuestions, () => {
    if (pageIndex.value > totalPages.value - 1) {
        pageIndex.value = Math.max(0, totalPages.value - 1);
    }
    selectedIds.value = selectedIds.value.filter((id) =>
        pendingQuestions.value.some((q) => q.question_id === id),
    );
});

const pageQuestions = computed(() => {
    const start = pageIndex.value * PAGE_SIZE;

    return pendingQuestions.value.slice(start, start + PAGE_SIZE);
});

const pageLabel = computed(() => {
    if (pendingQuestions.value.length === 0) {
        return totalCount.value > 0
            ? `All ${totalCount.value} questions done (${verifiedCount.value} verified · ${skippedCount.value} skipped)`
            : 'No questions to verify';
    }

    const start = pageIndex.value * PAGE_SIZE + 1;
    const end = Math.min((pageIndex.value + 1) * PAGE_SIZE, pendingQuestions.value.length);

    return `Pending ${start}–${end} of ${pendingQuestions.value.length} · ${verifiedCount.value} verified · ${skippedCount.value} skipped (not paid)`;
});

const canManageDiagram = computed(() => {
    const url = props.uploadDiagramRoute;

    return Boolean(url) && url !== '#';
});

const canEditQuestions = computed(() => {
    const url = props.saveQuestionRoute;

    return Boolean(url) && url !== '#';
});

const buildQuestionForm = (row) => useForm({
    run_id: props.verification.run_id,
    question_id: row.question_id,
    question_text: row.question_text ?? '',
    explanation: row.explanation ?? '',
    method_hint: row.method_hint ?? '',
    difficulty: row.difficulty ?? 'Easy',
    options: (row.options ?? []).map((option) => ({
        id: option.id,
        option_text: option.option_text ?? '',
        is_correct: Boolean(option.is_correct),
    })),
});

const startEditing = (row) => {
    questionForms[row.question_id] = buildQuestionForm(row);
    editingId.value = row.question_id;
    expandedId.value = row.question_id;
};

const cancelEditing = (questionId) => {
    if (editingId.value === questionId) {
        editingId.value = null;
    }
    delete questionForms[questionId];
};

const setCorrectOption = (questionId, optionIndex) => {
    const form = questionForms[questionId];
    if (!form) {
        return;
    }

    form.options = form.options.map((option, index) => ({
        ...option,
        is_correct: index === optionIndex,
    }));
};

const saveQuestion = (row) => {
    const form = questionForms[row.question_id];
    if (!form || form.processing || !canEditQuestions.value) {
        return;
    }

    form.post(props.saveQuestionRoute, {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
            delete questionForms[row.question_id];
        },
    });
};

const isSelected = (id) => selectedIds.value.includes(id);

const toggleSelected = (id) => {
    if (isSelected(id)) {
        selectedIds.value = selectedIds.value.filter((x) => x !== id);
    } else {
        selectedIds.value = [...selectedIds.value, id];
    }
};

const tickAllOnPage = () => {
    const ids = pageQuestions.value.map((q) => q.question_id);
    selectedIds.value = [...new Set([...selectedIds.value, ...ids])];
};

const clearPageSelection = () => {
    const pageIds = new Set(pageQuestions.value.map((q) => q.question_id));
    selectedIds.value = selectedIds.value.filter((id) => !pageIds.has(id));
};

const markSelectedVerified = () => {
    const ids = selectedIds.value.filter((id) =>
        pageQuestions.value.some((q) => q.question_id === id) || pendingQuestions.value.some((q) => q.question_id === id),
    );

    if (ids.length === 0) {
        window.alert('Tick at least one question to mark verified.');
        return;
    }

    batchForm.question_ids = ids;
    batchForm.post(props.batchVerifyRoute, {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = [];
        },
    });
};

const skipQuestion = (row) => {
    if (!canSkip.value || skipForm.processing) {
        return;
    }

    const reason = window.prompt(
        `Skip Q${row.number} as irrelevant?\nIt will not count toward uploader payment.\nOptional reason:`,
        'Irrelevant / not suitable for upload',
    );

    if (reason === null) {
        return;
    }

    skippingId.value = row.question_id;
    skipForm.run_id = props.verification.run_id;
    skipForm.question_id = row.question_id;
    skipForm.skip_reason = reason.trim();
    skipForm.post(props.skipRoute, {
        preserveScroll: true,
        onFinish: () => {
            skippingId.value = null;
        },
    });
};

const inCart = (questionId) => sendBackCart.value.some((item) => item.question_id === questionId);

const addToSendBack = (row) => {
    const remark = (flagRemarks[row.question_id] || '').trim();
    if (!remark) {
        window.alert('Add a short remark (e.g. wrong answer / needs figure).');
        return;
    }

    sendBackCart.value = [
        ...sendBackCart.value.filter((item) => item.question_id !== row.question_id),
        {
            question_id: row.question_id,
            number: row.number,
            question_text: row.question_text,
            remark,
        },
    ];
};

const removeFromSendBack = (questionId) => {
    sendBackCart.value = sendBackCart.value.filter((item) => item.question_id !== questionId);
};

const submitSendBack = () => {
    if (sendBackCart.value.length === 0 && !overallReason.value.trim()) {
        window.alert('Flag at least one question with a remark, or write an overall note.');
        return;
    }

    const count = sendBackCart.value.length;
    const msg = count > 0
        ? `Email uploader about ${count} question(s) to fix? Only those will be reopened.`
        : 'Send the whole chapter back and clear all verification ticks?';

    if (!window.confirm(msg)) {
        return;
    }

    returnForm.reason = overallReason.value.trim();
    returnForm.items = sendBackCart.value.map((item) => ({
        question_id: item.question_id,
        number: item.number,
        remark: item.remark,
        question_text: String(item.question_text || '').slice(0, 500),
    }));

    returnForm.post(props.returnRoute, {
        preserveScroll: true,
        onSuccess: () => {
            sendBackCart.value = [];
            overallReason.value = '';
            Object.keys(flagRemarks).forEach((key) => {
                delete flagRemarks[key];
            });
        },
    });
};

const uploadDiagram = (questionId, event) => {
    const file = event.target?.files?.[0];
    if (!file || !props.uploadDiagramRoute || diagramUploading.value[questionId]) {
        return;
    }

    const formData = new FormData();
    formData.append('run_id', String(props.verification.run_id));
    formData.append('question_id', String(questionId));
    formData.append('diagram', file);

    diagramUploading.value = { ...diagramUploading.value, [questionId]: true };

    router.post(props.uploadDiagramRoute, formData, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            diagramUploading.value = { ...diagramUploading.value, [questionId]: false };
            if (event.target) {
                event.target.value = '';
            }
        },
    });
};

const removeDiagram = (questionId) => {
    if (!props.removeDiagramRoute || diagramUploading.value[questionId]) {
        return;
    }

    if (!window.confirm('Remove this figure?')) {
        return;
    }

    diagramUploading.value = { ...diagramUploading.value, [questionId]: true };

    router.post(props.removeDiagramRoute, {
        run_id: props.verification.run_id,
        question_id: questionId,
    }, {
        preserveScroll: true,
        onFinish: () => {
            diagramUploading.value = { ...diagramUploading.value, [questionId]: false };
        },
    });
};

const optionLine = (row) =>
    (row.options || [])
        .map((opt) => `${opt.letter}${opt.is_correct ? '✓' : ''}. ${opt.option_text}`)
        .join(' · ');
</script>

<template>
    <div id="verification-batch" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3">
            <div>
                <p class="text-sm font-semibold text-indigo-950">Batch verification (10 at a time)</p>
                <p class="text-xs text-indigo-900">{{ pageLabel }}</p>
                <p class="mt-1 text-xs text-indigo-800">
                    Skip irrelevant questions — they do not count in uploader pay.
                    Expand a row and use <strong>Edit</strong> to fix wrong answers, options, or explanations.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <SecondaryButton type="button" class="!text-xs" :disabled="pageIndex <= 0" @click="pageIndex -= 1">
                    ← Prev 10
                </SecondaryButton>
                <SecondaryButton
                    type="button"
                    class="!text-xs"
                    :disabled="pageIndex >= totalPages - 1 || pendingQuestions.length === 0"
                    @click="pageIndex += 1"
                >
                    Next 10 →
                </SecondaryButton>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <SecondaryButton type="button" class="!text-xs" :disabled="pageQuestions.length === 0" @click="tickAllOnPage">
                Tick all on this page
            </SecondaryButton>
            <SecondaryButton type="button" class="!text-xs" :disabled="selectedIds.length === 0" @click="clearPageSelection">
                Clear ticks
            </SecondaryButton>
            <PrimaryButton
                type="button"
                class="!text-xs"
                :disabled="batchForm.processing || selectedIds.length === 0"
                @click="markSelectedVerified"
            >
                {{ batchForm.processing ? 'Saving…' : `Mark ${selectedIds.length || ''} verified → next` }}
            </PrimaryButton>
        </div>

                <div v-if="pageQuestions.length === 0" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-6 text-sm text-emerald-950">
                    <p class="font-semibold">All questions reviewed.</p>
                    <p class="mt-1 text-xs">
                        {{ verifiedCount }} verified · {{ skippedCount }} skipped (not paid).
                        Status should show as Verified on the task list. Use <strong>Publish</strong> below to mark the task published.
                    </p>
                    <ul v-if="skippedQuestions.length" class="mt-3 space-y-1 text-xs text-slate-700">
                        <li v-for="row in skippedQuestions" :key="row.question_id">
                            Q{{ row.number }} skipped
                            <span v-if="row.skip_reason">— {{ row.skip_reason }}</span>
                        </li>
                    </ul>
                </div>

        <div v-else class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-left text-xs">
                <thead class="bg-gray-50 text-[10px] uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-2 py-2">OK</th>
                        <th class="px-2 py-2">#</th>
                        <th class="px-2 py-2 min-w-[12rem]">Question</th>
                        <th class="px-2 py-2 min-w-[10rem]">Options</th>
                        <th class="px-2 py-2">Ans</th>
                        <th class="px-2 py-2 min-w-[7rem]">Hint</th>
                        <th class="px-2 py-2 min-w-[8rem]">Explanation</th>
                        <th class="px-2 py-2">Figure</th>
                        <th class="px-2 py-2 min-w-[6rem]">Skip</th>
                        <th class="px-2 py-2 min-w-[10rem]">Send back</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template v-for="row in pageQuestions" :key="row.question_id">
                        <tr :class="inCart(row.question_id) ? 'bg-amber-50/70' : 'bg-white'">
                            <td class="px-2 py-2 align-top">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300 text-indigo-600"
                                    :checked="isSelected(row.question_id)"
                                    @change="toggleSelected(row.question_id)"
                                >
                            </td>
                            <td class="px-2 py-2 align-top font-mono font-semibold text-gray-800">
                                Q{{ row.number }}
                                <button
                                    type="button"
                                    class="mt-1 block text-[10px] font-normal text-indigo-600 hover:underline"
                                    @click="expandedId = expandedId === row.question_id ? null : row.question_id"
                                >
                                    {{ expandedId === row.question_id ? 'Hide' : 'Expand' }}
                                </button>
                                <button
                                    v-if="canEditQuestions"
                                    type="button"
                                    class="mt-0.5 block text-[10px] font-semibold text-emerald-700 hover:underline"
                                    @click="startEditing(row)"
                                >
                                    {{ editingId === row.question_id ? 'Editing…' : 'Edit' }}
                                </button>
                            </td>
                            <td class="px-2 py-2 align-top text-gray-900">
                                <p class="line-clamp-3 whitespace-pre-wrap">{{ row.question_text }}</p>
                                <p v-if="row.set_code" class="mt-1 font-mono text-[10px] text-gray-500">{{ row.set_code }}</p>
                                <p v-if="row.ai_note" class="mt-1 text-[10px] text-violet-800">
                                    AI {{ row.ai_verdict }}: {{ row.ai_note }}
                                </p>
                            </td>
                            <td class="px-2 py-2 align-top text-gray-700">
                                <p class="line-clamp-4">{{ optionLine(row) }}</p>
                            </td>
                            <td class="px-2 py-2 align-top font-semibold text-emerald-800">
                                {{ row.correct_letter || '—' }}
                            </td>
                            <td class="px-2 py-2 align-top text-gray-700">
                                <p class="line-clamp-3 whitespace-pre-wrap">{{ row.method_hint || '—' }}</p>
                            </td>
                            <td class="px-2 py-2 align-top text-gray-700">
                                <p class="line-clamp-3 whitespace-pre-wrap">{{ row.explanation || '—' }}</p>
                            </td>
                            <td class="px-2 py-2 align-top">
                                <div v-if="row.diagram_url" class="space-y-1">
                                    <a :href="row.diagram_url" target="_blank" rel="noopener" class="block">
                                        <img :src="row.diagram_url" alt="Figure" class="h-12 w-auto rounded border border-gray-200 object-contain">
                                    </a>
                                    <button
                                        v-if="canManageDiagram"
                                        type="button"
                                        class="text-[10px] text-rose-700 hover:underline"
                                        :disabled="diagramUploading[row.question_id]"
                                        @click="removeDiagram(row.question_id)"
                                    >
                                        Remove
                                    </button>
                                </div>
                                <div v-else class="space-y-1">
                                    <p class="text-[10px]" :class="row.needs_figure ? 'font-semibold text-amber-800' : 'text-gray-400'">
                                        {{ row.needs_figure ? 'Needs figure' : 'None' }}
                                    </p>
                                    <label
                                        v-if="canManageDiagram"
                                        class="inline-block cursor-pointer text-[10px] font-medium text-indigo-700 hover:underline"
                                    >
                                        {{ diagramUploading[row.question_id] ? '…' : 'Upload' }}
                                        <input
                                            type="file"
                                            accept="image/*"
                                            class="hidden"
                                            :disabled="diagramUploading[row.question_id]"
                                            @change="uploadDiagram(row.question_id, $event)"
                                        >
                                    </label>
                                </div>
                            </td>
                            <td class="px-2 py-2 align-top">
                                <button
                                    v-if="canSkip"
                                    type="button"
                                    class="text-[10px] font-semibold text-slate-700 hover:underline"
                                    :disabled="skipForm.processing && skippingId === row.question_id"
                                    @click="skipQuestion(row)"
                                >
                                    {{ skipForm.processing && skippingId === row.question_id ? '…' : 'Skip (not paid)' }}
                                </button>
                                <span v-else class="text-[10px] text-gray-400">—</span>
                            </td>
                            <td class="px-2 py-2 align-top">
                                <input
                                    v-model="flagRemarks[row.question_id]"
                                    type="text"
                                    class="mb-1 w-full rounded border-gray-300 text-[11px]"
                                    placeholder="Short remark"
                                    maxlength="500"
                                >
                                <button
                                    v-if="!inCart(row.question_id)"
                                    type="button"
                                    class="text-[10px] font-semibold text-amber-800 hover:underline"
                                    @click="addToSendBack(row)"
                                >
                                    + Flag for uploader
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="text-[10px] font-semibold text-rose-700 hover:underline"
                                    @click="removeFromSendBack(row.question_id)"
                                >
                                    Remove flag
                                </button>
                            </td>
                        </tr>
                        <tr v-if="expandedId === row.question_id" class="bg-slate-50">
                            <td colspan="10" class="px-4 py-3">
                                <div v-if="editingId === row.question_id && questionForms[row.question_id]" class="space-y-3 text-sm">
                                    <p class="text-xs font-semibold text-emerald-900">Edit question — save marks it verified</p>
                                    <div>
                                        <InputLabel value="Question text" />
                                        <textarea
                                            v-model="questionForms[row.question_id].question_text"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                        />
                                        <InputError :message="questionForms[row.question_id].errors.question_text" class="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel value="Options (select the correct answer)" />
                                        <div class="mt-2 space-y-2">
                                            <label
                                                v-for="(option, optionIndex) in questionForms[row.question_id].options"
                                                :key="option.id || `new-${optionIndex}`"
                                                class="flex items-start gap-3 rounded-md border px-3 py-2"
                                                :class="option.is_correct ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200'"
                                            >
                                                <input
                                                    type="radio"
                                                    class="mt-1"
                                                    :name="`correct-${row.question_id}`"
                                                    :checked="option.is_correct"
                                                    @change="setCorrectOption(row.question_id, optionIndex)"
                                                >
                                                <span class="mt-0.5 w-5 shrink-0 text-sm font-semibold text-gray-600">
                                                    {{ String.fromCharCode(65 + optionIndex) }}
                                                </span>
                                                <input
                                                    v-model="option.option_text"
                                                    type="text"
                                                    class="block w-full rounded-md border-gray-300 text-sm"
                                                >
                                            </label>
                                        </div>
                                        <InputError :message="questionForms[row.question_id].errors.options" class="mt-1" />
                                    </div>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <InputLabel value="Hint" />
                                            <textarea
                                                v-model="questionForms[row.question_id].method_hint"
                                                rows="2"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                            />
                                        </div>
                                        <div>
                                            <InputLabel value="Difficulty" />
                                            <select
                                                v-model="questionForms[row.question_id].difficulty"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                            >
                                                <option>Easy</option>
                                                <option>Medium</option>
                                                <option>Hard</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <InputLabel value="Explanation / answer note" />
                                        <textarea
                                            v-model="questionForms[row.question_id].explanation"
                                            rows="3"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                        />
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <PrimaryButton
                                            type="button"
                                            class="!text-xs"
                                            :disabled="questionForms[row.question_id].processing"
                                            @click="saveQuestion(row)"
                                        >
                                            {{ questionForms[row.question_id].processing ? 'Saving…' : 'Save & mark verified' }}
                                        </PrimaryButton>
                                        <SecondaryButton
                                            type="button"
                                            class="!text-xs"
                                            :disabled="questionForms[row.question_id].processing"
                                            @click="cancelEditing(row.question_id)"
                                        >
                                            Cancel
                                        </SecondaryButton>
                                    </div>
                                </div>
                                <div v-else class="grid gap-3 text-sm text-gray-900 lg:grid-cols-2">
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase text-gray-500">Full question</p>
                                        <QuestionBody
                                            class="mt-1"
                                            :question-text="row.question_text"
                                            :diagram-url="row.diagram_url"
                                            :compact="true"
                                        />
                                    </div>
                                    <div class="space-y-2">
                                        <div>
                                            <p class="text-[10px] font-semibold uppercase text-gray-500">Options</p>
                                            <ul class="mt-1 space-y-0.5">
                                                <li
                                                    v-for="opt in row.options"
                                                    :key="opt.id || opt.letter"
                                                    :class="opt.is_correct ? 'font-semibold text-emerald-800' : 'text-gray-700'"
                                                >
                                                    {{ opt.letter }}. {{ opt.option_text }}
                                                    <span v-if="opt.is_correct">(correct)</span>
                                                </li>
                                            </ul>
                                        </div>
                                        <p><span class="text-gray-500">Hint:</span> {{ row.method_hint || '—' }}</p>
                                        <p><span class="text-gray-500">Explanation:</span> {{ row.explanation || '—' }}</p>
                                        <p><span class="text-gray-500">Difficulty:</span> {{ row.difficulty || '—' }}</p>
                                        <button
                                            v-if="canEditQuestions"
                                            type="button"
                                            class="text-xs font-semibold text-emerald-700 hover:underline"
                                            @click="startEditing(row)"
                                        >
                                            Edit question
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div
            v-if="canReturn"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4"
        >
            <p class="text-sm font-semibold text-amber-950">Send flagged questions to uploader</p>
            <p class="mt-1 text-xs text-amber-900">
                Accumulate flags while you review. One email goes out when you submit — only flagged questions are reopened.
            </p>

            <ul v-if="sendBackCart.length" class="mt-3 space-y-1 text-sm text-amber-950">
                <li v-for="item in sendBackCart" :key="item.question_id" class="flex flex-wrap items-start justify-between gap-2">
                    <span>
                        <strong>Q{{ item.number }}</strong> — {{ item.remark }}
                    </span>
                    <button type="button" class="text-xs text-rose-700 hover:underline" @click="removeFromSendBack(item.question_id)">
                        Remove
                    </button>
                </li>
            </ul>
            <p v-else class="mt-3 text-xs text-amber-800">No questions flagged yet.</p>

            <div class="mt-3">
                <label class="text-xs font-medium text-amber-950">Overall note (optional)</label>
                <textarea
                    v-model="overallReason"
                    rows="2"
                    class="mt-1 block w-full rounded-md border-amber-200 text-sm"
                    placeholder="e.g. Please upload missing figures and fix wrong answers on the flagged sums."
                />
            </div>

            <SecondaryButton
                class="mt-3"
                type="button"
                :disabled="returnForm.processing || (sendBackCart.length === 0 && !overallReason.trim())"
                @click="submitSendBack"
            >
                {{ returnForm.processing ? 'Sending…' : `Email uploader (${sendBackCart.length} flagged)` }}
            </SecondaryButton>
        </div>
    </div>
</template>
