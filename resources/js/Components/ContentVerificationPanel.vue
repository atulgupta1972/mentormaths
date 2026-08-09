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
    uploadDiagramRoute: { type: String, default: '' },
    removeDiagramRoute: { type: String, default: '' },
    editableStatuses: {
        type: Array,
        default: () => ['uploaded', 'verification_in_progress'],
    },
    showCompleteActions: { type: Boolean, default: true },
    completeVerificationRoute: { type: String, default: '' },
    submitForPublishRoute: { type: String, default: '' },
});

const completeForm = useForm({ run_id: props.verification?.run_id ?? null });
const submitForm = useForm({});
const questionForms = reactive({});
const filterMode = reactive({ value: 'pending' });
const diagramUploading = ref({});
const diagramFileInputs = ref({});

const verificationSummary = computed(() => props.verification?.summary ?? { total: 0, verified: 0, unverified: 0 });
const canEditQuestions = computed(() => props.editableStatuses.includes(props.task.status));

const pendingQuestions = computed(() =>
    (props.verification?.questions ?? []).filter((row) => !row.is_verified),
);

const verifiedQuestions = computed(() =>
    (props.verification?.questions ?? []).filter((row) => row.is_verified),
);

const currentPending = computed(() => pendingQuestions.value[0] ?? null);

const visibleQuestions = computed(() => {
    if (filterMode.value === 'verified') {
        return verifiedQuestions.value;
    }

    return currentPending.value ? [currentPending.value] : [];
});

const queueLabel = computed(() => {
    const total = verificationSummary.value.total;
    const verified = verificationSummary.value.verified;
    const remaining = verificationSummary.value.unverified;

    if (filterMode.value === 'verified') {
        return `${verified} verified — open any to re-check and save again`;
    }

    if (!currentPending.value) {
        return remaining === 0 && total > 0
            ? 'All questions verified'
            : 'No pending questions';
    }

    return `Now reviewing Q${currentPending.value.number} of ${total} · ${verified} done · ${remaining} left`;
});

const buildForm = (row) => useForm({
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

const syncQuestionForms = () => {
    if (!props.verification?.questions) {
        return;
    }

    completeForm.run_id = props.verification.run_id;

    props.verification.questions.forEach((row) => {
        if (!questionForms[row.question_id]) {
            questionForms[row.question_id] = buildForm(row);
            return;
        }

        if (row.is_verified && !questionForms[row.question_id].processing) {
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

const canManageDiagram = computed(() =>
    Boolean(props.uploadDiagramRoute) && canEditQuestions.value,
);

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
                    <p class="text-sm font-semibold text-gray-900">MCQ verification</p>
                    <p class="mt-1 text-sm text-gray-600">{{ queueLabel }}</p>
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
                </div>
            </div>
            <p v-if="verificationSummary.total === 0" class="mt-2 text-sm text-amber-800">
                No published MCQ questions found yet.
            </p>
            <p v-else-if="filterMode.value === 'pending' && !currentPending && verificationSummary.unverified === 0" class="mt-2 text-sm text-emerald-800">
                All questions verified.
            </p>
        </div>

        <div
            v-for="row in visibleQuestions"
            :key="row.question_id"
            class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200"
            :class="row.is_verified ? 'ring-emerald-300' : 'ring-amber-200'"
        >
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-gray-900">
                    Q{{ row.number }}
                    <span v-if="row.set_code" class="ml-2 text-xs font-normal text-gray-500">{{ row.set_code }}</span>
                    <span v-if="row.correct_letter" class="ml-2 text-xs font-normal text-gray-500">Current answer: {{ row.correct_letter }}</span>
                </p>
                <span
                    class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :class="row.is_verified ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'"
                >
                    {{ row.is_verified ? 'Verified' : 'Needs review' }}
                </span>
            </div>

            <div v-if="questionForms[row.question_id]" class="space-y-3">
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
                </div>
            </div>
        </div>

        <div
            v-if="filterMode.value === 'verified' && !verifiedQuestions.length"
            class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500"
        >
            No verified questions yet.
        </div>

        <div v-if="showCompleteActions && (task.status === 'verification_in_progress' || task.status === 'verified')" class="flex flex-wrap gap-3">
            <PrimaryButton
                v-if="completeVerificationRoute"
                type="button"
                :disabled="completeForm.processing || verificationSummary.unverified > 0"
                @click="completeForm.post(completeVerificationRoute)"
            >
                All verified — lock verification
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
