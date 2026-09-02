<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    task: { type: Object, required: true },
    verification: { type: Object, required: true },
    saveQuestionRoute: { type: String, required: true },
    skipRoute: { type: String, default: '' },
    unskipRoute: { type: String, default: '' },
    uploadDiagramRoute: { type: String, default: '' },
    removeDiagramRoute: { type: String, default: '' },
    editableStatuses: {
        type: Array,
        // Stay editable until admin publishes — locking verification must not freeze the form.
        default: () => ['uploaded', 'verification_in_progress', 'verified', 'submitted_for_publish'],
    },
    showCompleteActions: { type: Boolean, default: true },
    completeVerificationRoute: { type: String, default: '' },
    submitForPublishRoute: { type: String, default: '' },
});

const completeForm = useForm({ run_id: props.verification?.run_id ?? null });
const submitForm = useForm({});
const skipForm = useForm({
    run_id: props.verification?.run_id ?? null,
    question_id: null,
    skip_reason: '',
});
const unskipForm = useForm({
    run_id: props.verification?.run_id ?? null,
    question_id: null,
});
const questionForms = reactive({});
const filterMode = reactive({ value: 'pending' });
const diagramUploading = ref({});
const diagramFileInputs = ref({});

const verificationSummary = computed(() => props.verification?.summary ?? {
    total: 0,
    verified: 0,
    skipped: 0,
    unverified: 0,
});
const canEditQuestions = computed(() => props.editableStatuses.includes(props.task.status));
const canSkip = computed(() => Boolean(props.skipRoute) && props.skipRoute !== '#' && canEditQuestions.value);
const canUnskip = computed(() => Boolean(props.unskipRoute) && props.unskipRoute !== '#' && canEditQuestions.value);

const pendingQuestions = computed(() =>
    (props.verification?.questions ?? []).filter((row) => !row.is_verified),
);

const verifiedQuestions = computed(() =>
    (props.verification?.questions ?? []).filter((row) => row.is_verified && !row.is_skipped),
);

const skippedQuestions = computed(() =>
    (props.verification?.questions ?? []).filter((row) => row.is_skipped),
);

const currentPending = computed(() => pendingQuestions.value[0] ?? null);

const visibleQuestions = computed(() => {
    if (filterMode.value === 'verified') {
        return verifiedQuestions.value;
    }

    if (filterMode.value === 'skipped') {
        return skippedQuestions.value;
    }

    return currentPending.value ? [currentPending.value] : [];
});

const queueLabel = computed(() => {
    const total = verificationSummary.value.total;
    const verified = verificationSummary.value.verified;
    const skipped = verificationSummary.value.skipped ?? 0;
    const remaining = verificationSummary.value.unverified;

    if (filterMode.value === 'verified') {
        return `${verified} verified — open any to re-check and save again`;
    }

    if (filterMode.value === 'skipped') {
        return `${skipped} skipped — not counted in uploader pay`;
    }

    if (!currentPending.value) {
        return remaining === 0 && total > 0
            ? `All questions done (${verified} verified · ${skipped} skipped)`
            : 'No pending questions';
    }

    return `Now reviewing Q${currentPending.value.number} of ${total} · ${verified} verified · ${skipped} skipped · ${remaining} left`;
});

const buildForm = (row) => {
    if (row.is_fill_in_blank || row.question_type === 'fill_in_blank') {
        return useForm({
            run_id: props.verification.run_id,
            question_id: row.question_id,
            question_text: row.question_text ?? '',
            explanation: row.explanation ?? '',
            method_hint: row.method_hint ?? '',
            difficulty: row.difficulty ?? 'Easy',
            correct_answer: row.correct_answer ?? '',
            answer_format: row.answer_format ?? 'integer',
            decimal_places: row.decimal_places ?? null,
        });
    }

    return useForm({
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
};

const isFillBlankRow = (row) => Boolean(row?.is_fill_in_blank || row?.question_type === 'fill_in_blank');

const answerFormatLabel = (format) => {
    const labels = {
        integer: 'Whole number',
        decimal: 'Decimal',
        fraction: 'Fraction',
        text: 'Text',
    };

    return labels[format] || format;
};

const syncQuestionForms = () => {
    if (!props.verification?.questions) {
        return;
    }

    completeForm.run_id = props.verification.run_id;

    props.verification.questions.forEach((row) => {
        const existing = questionForms[row.question_id];
        const needsRebuild = !existing
            || (row.is_verified && !existing.processing)
            || (isFillBlankRow(row) && existing.options)
            || (!isFillBlankRow(row) && existing.correct_answer !== undefined && !existing.options?.length);

        if (needsRebuild) {
            questionForms[row.question_id] = buildForm(row);
        }
    });
};

syncQuestionForms();

watch(
    () => props.verification?.questions,
    () => {
        syncQuestionForms();
        if (filterMode.value === 'pending') {
            window.setTimeout(() => {
                document.getElementById('verification-queue')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 50);
        }
    },
    { deep: true },
);

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

const saveQuestion = (questionId) => {
    const form = questionForms[questionId];
    if (!form || form.processing) {
        return;
    }

    filterMode.value = 'pending';

    form.post(props.saveQuestionRoute, {
        preserveScroll: false,
        onSuccess: () => {
            filterMode.value = 'pending';
            window.setTimeout(() => {
                document.getElementById('verification-queue')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 80);
        },
    });
};

const skipQuestion = (questionId) => {
    if (!canSkip.value || skipForm.processing) {
        return;
    }

    const reason = window.prompt(
        'Skip this question as irrelevant?\nIt will not count toward uploader payment.\nOptional reason:',
        'Irrelevant / not suitable for upload',
    );

    if (reason === null) {
        return;
    }

    filterMode.value = 'pending';
    skipForm.run_id = props.verification.run_id;
    skipForm.question_id = questionId;
    skipForm.skip_reason = reason.trim();
    skipForm.post(props.skipRoute, {
        preserveScroll: false,
        onSuccess: () => {
            filterMode.value = 'pending';
            window.setTimeout(() => {
                document.getElementById('verification-queue')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 80);
        },
    });
};

const unskipQuestion = (questionId) => {
    if (!canUnskip.value || unskipForm.processing) {
        return;
    }

    if (!window.confirm('Clear skip and put this question back in the verify queue?')) {
        return;
    }

    unskipForm.run_id = props.verification.run_id;
    unskipForm.question_id = questionId;
    unskipForm.post(props.unskipRoute, {
        preserveScroll: true,
        onSuccess: () => {
            filterMode.value = 'pending';
        },
    });
};

const statusBadge = (row) => {
    if (row.is_skipped) {
        return { label: 'Skipped (not paid)', className: 'bg-slate-200 text-slate-800' };
    }

    if (row.is_verified) {
        return { label: 'Verified', className: 'bg-emerald-100 text-emerald-800' };
    }

    return { label: 'Needs review', className: 'bg-amber-100 text-amber-900' };
};

const canManageDiagram = computed(() => {
    const url = props.uploadDiagramRoute;

    return Boolean(url) && url !== '#' && canEditQuestions.value;
});

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

    if (!window.confirm('Remove this figure from the question?')) {
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
</script>

<template>
    <div id="verification-queue" class="space-y-4">
        <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-gray-900">Question verification</p>
                    <p class="mt-1 text-sm text-gray-600">{{ queueLabel }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        Skip irrelevant questions — they stay off the pay count for the uploader.
                        You can edit fields until admin publishes (lock does not freeze editing).
                    </p>
                    <p v-if="!canEditQuestions" class="mt-2 text-sm font-medium text-rose-700">
                        Published — editing is locked. Ask admin to send the chapter back if a sum still needs a fix.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                    <button
                        type="button"
                        class="rounded-md border px-3 py-1.5"
                        :class="filterMode.value === 'pending' ? 'border-amber-400 bg-amber-50 text-amber-900' : 'border-gray-300 text-gray-700'"
                        @click="filterMode.value = 'pending'"
                    >
                        To verify ({{ verificationSummary.unverified }})
                    </button>
                    <button
                        type="button"
                        class="rounded-md border px-3 py-1.5"
                        :class="filterMode.value === 'verified' ? 'border-emerald-400 bg-emerald-50 text-emerald-900' : 'border-gray-300 text-gray-700'"
                        @click="filterMode.value = 'verified'"
                    >
                        Verified ({{ verificationSummary.verified }})
                    </button>
                    <button
                        type="button"
                        class="rounded-md border px-3 py-1.5"
                        :class="filterMode.value === 'skipped' ? 'border-slate-400 bg-slate-100 text-slate-900' : 'border-gray-300 text-gray-700'"
                        @click="filterMode.value = 'skipped'"
                    >
                        Skipped ({{ verificationSummary.skipped ?? 0 }})
                    </button>
                </div>
            </div>
            <p v-if="verificationSummary.total === 0" class="mt-2 text-sm text-amber-800">
                No published questions found yet.
            </p>
            <p v-else-if="filterMode.value === 'pending' && !currentPending && verificationSummary.unverified === 0" class="mt-2 text-sm text-emerald-800">
                All questions reviewed (verified or skipped).
            </p>
        </div>

        <div
            v-for="row in visibleQuestions"
            :key="row.question_id"
            class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200"
            :class="row.is_skipped ? 'ring-slate-300' : (row.is_verified ? 'ring-emerald-300' : 'ring-amber-200')"
        >
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-gray-900">
                    Q{{ row.number }}
                    <span v-if="row.set_code" class="ml-2 text-xs font-normal text-gray-500">{{ row.set_code }}</span>
                    <span
                        v-if="isFillBlankRow(row)"
                        class="ml-2 rounded bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-800"
                    >
                        Fill in blank
                    </span>
                    <span v-else-if="row.correct_letter" class="ml-2 text-xs font-normal text-gray-500">Current answer: {{ row.correct_letter }}</span>
                </p>
                <span
                    class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :class="statusBadge(row).className"
                >
                    {{ statusBadge(row).label }}
                </span>
            </div>
            <p
                v-if="row.is_skipped && row.skip_reason"
                class="mb-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800"
            >
                Skip reason: {{ row.skip_reason }}
            </p>
            <p
                v-if="row.correction_remark"
                class="mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900"
            >
                Admin: {{ row.correction_remark }}
            </p>
            <p
                v-if="row.ai_note && !row.is_skipped"
                class="mb-3 rounded-md border border-violet-200 bg-violet-50 px-3 py-2 text-sm text-violet-950"
            >
                AI ({{ row.ai_verdict || 'note' }} · {{ row.ai_confidence || 'n/a' }}): {{ row.ai_note }}
            </p>

            <div v-if="row.is_skipped" class="space-y-3">
                <p class="whitespace-pre-wrap text-sm text-slate-700">{{ row.question_text }}</p>
                <div v-if="canUnskip" class="pt-1">
                    <SecondaryButton
                        type="button"
                        :disabled="unskipForm.processing"
                        @click="unskipQuestion(row.question_id)"
                    >
                        {{ unskipForm.processing ? 'Working…' : 'Undo skip — verify instead' }}
                    </SecondaryButton>
                </div>
            </div>

            <div v-else-if="questionForms[row.question_id]" class="space-y-3">
                <div>
                    <InputLabel value="Question text" />
                    <textarea
                        v-model="questionForms[row.question_id].question_text"
                        rows="3"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                        :disabled="!canEditQuestions"
                    />
                    <InputError :message="questionForms[row.question_id].errors.question_text" class="mt-1" />
                </div>

                <div v-if="isFillBlankRow(row)">
                    <InputLabel value="Correct answer" />
                    <input
                        v-model="questionForms[row.question_id].correct_answer"
                        type="text"
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                        :disabled="!canEditQuestions"
                    >
                    <InputError :message="questionForms[row.question_id].errors.correct_answer" class="mt-1" />
                </div>

                <div v-if="isFillBlankRow(row)" class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <InputLabel value="Answer format" />
                        <select
                            v-model="questionForms[row.question_id].answer_format"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                            :disabled="!canEditQuestions"
                        >
                            <option value="integer">Whole number</option>
                            <option value="decimal">Decimal</option>
                            <option value="fraction">Fraction</option>
                            <option value="text">Text</option>
                        </select>
                        <InputError :message="questionForms[row.question_id].errors.answer_format" class="mt-1" />
                    </div>
                    <div v-if="questionForms[row.question_id].answer_format === 'decimal'">
                        <InputLabel value="Decimal places" />
                        <input
                            v-model.number="questionForms[row.question_id].decimal_places"
                            type="number"
                            min="0"
                            max="6"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                            :disabled="!canEditQuestions"
                        >
                    </div>
                </div>

                <div v-else>
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
                                :disabled="!canEditQuestions"
                                @change="setCorrectOption(row.question_id, optionIndex)"
                            >
                            <span class="mt-0.5 w-5 shrink-0 text-sm font-semibold text-gray-600">{{ String.fromCharCode(65 + optionIndex) }}</span>
                            <input
                                v-model="option.option_text"
                                type="text"
                                class="block w-full rounded-md border-gray-300 text-sm"
                                :disabled="!canEditQuestions"
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
                            :disabled="!canEditQuestions"
                        />
                    </div>
                    <div>
                        <InputLabel value="Difficulty" />
                        <select
                            v-model="questionForms[row.question_id].difficulty"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                            :disabled="!canEditQuestions"
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
                        :disabled="!canEditQuestions"
                    />
                </div>

                <div
                    class="rounded-md border p-3"
                    :class="row.needs_figure && !row.diagram_url
                        ? 'border-amber-300 bg-amber-50'
                        : 'border-slate-200 bg-slate-50'"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-600">Figure / diagram</p>
                            <p v-if="row.needs_figure && !row.diagram_url" class="mt-1 text-sm font-medium text-amber-900">
                                This question requires a figure upload.
                            </p>
                            <p v-else-if="row.diagram_url" class="mt-1 text-sm text-slate-600">
                                Figure attached — replace if the wrong image is linked.
                            </p>
                            <p v-else class="mt-1 text-sm text-slate-600">
                                Optional: upload a PNG/JPG if this question needs a textbook figure.
                            </p>
                        </div>
                        <div v-if="canManageDiagram" class="flex flex-wrap gap-2">
                            <SecondaryButton
                                type="button"
                                class="!px-3 !py-1.5 !text-xs"
                                :disabled="diagramUploading[row.question_id]"
                                @click="diagramFileInputs[row.question_id]?.click()"
                            >
                                {{ diagramUploading[row.question_id]
                                    ? 'Uploading…'
                                    : (row.diagram_url ? 'Replace figure' : 'Upload figure') }}
                            </SecondaryButton>
                            <SecondaryButton
                                v-if="row.diagram_url"
                                type="button"
                                class="!px-3 !py-1.5 !text-xs"
                                :disabled="diagramUploading[row.question_id]"
                                @click="removeDiagram(row.question_id)"
                            >
                                Remove
                            </SecondaryButton>
                            <input
                                :ref="(el) => { if (el) diagramFileInputs[row.question_id] = el; }"
                                type="file"
                                accept="image/*"
                                class="hidden"
                                @change="uploadDiagram(row.question_id, $event)"
                            >
                        </div>
                    </div>
                    <img
                        v-if="row.diagram_url"
                        :src="row.diagram_url"
                        alt="Question diagram"
                        class="mt-3 max-h-56 rounded border border-slate-200 bg-white object-contain"
                    >
                </div>

                <div class="flex flex-wrap items-center gap-3 pt-1">
                    <PrimaryButton
                        v-if="canEditQuestions"
                        type="button"
                        :disabled="questionForms[row.question_id].processing"
                        @click="saveQuestion(row.question_id)"
                    >
                        {{ questionForms[row.question_id].processing
                            ? 'Saving…'
                            : (row.is_verified ? 'Save again' : 'Save & mark verified → next') }}
                    </PrimaryButton>
                    <SecondaryButton
                        v-if="canSkip && !row.is_verified"
                        type="button"
                        :disabled="skipForm.processing"
                        @click="skipQuestion(row.question_id)"
                    >
                        {{ skipForm.processing ? 'Skipping…' : 'Skip (not paid)' }}
                    </SecondaryButton>
                </div>
            </div>
        </div>

        <div
            v-if="filterMode.value === 'verified' && !verifiedQuestions.length"
            class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500"
        >
            No verified questions yet.
        </div>

        <div
            v-if="filterMode.value === 'skipped' && !skippedQuestions.length"
            class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500"
        >
            No skipped questions.
        </div>

        <div v-if="showCompleteActions && (task.status === 'verification_in_progress' || task.status === 'verified')" class="flex flex-wrap gap-3">
            <PrimaryButton
                v-if="completeVerificationRoute"
                type="button"
                :disabled="completeForm.processing || verificationSummary.unverified > 0"
                @click="completeForm.post(completeVerificationRoute)"
            >
                All verified — ready for publish
            </PrimaryButton>
            <PrimaryButton
                v-if="submitForPublishRoute && task.status === 'verified'"
                type="button"
                :disabled="submitForm.processing"
                @click="submitForm.post(submitForPublishRoute)"
            >
                Submit for admin publish
            </PrimaryButton>
        </div>
    </div>
</template>
