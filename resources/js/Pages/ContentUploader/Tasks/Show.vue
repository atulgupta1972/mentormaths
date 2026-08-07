<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive, watch } from 'vue';

const props = defineProps({
    task: { type: Object, required: true },
    verification: { type: Object, default: null },
    activeSeconds: { type: Number, default: 0 },
    textbookChapterUrl: { type: String, default: '' },
});

const page = usePage();
const agreeForm = useForm({});
const uploadForm = useForm({});
const submitForm = useForm({});
const completeForm = useForm({ run_id: props.verification?.run_id ?? null });

const questionForms = reactive({});
const filterMode = reactive({ value: 'all' }); // all | pending | verified

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;
const formatDuration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m ${s}s`;
};

const verificationSummary = computed(() => props.verification?.summary ?? { total: 0, verified: 0, unverified: 0 });
const canEditQuestions = computed(() => ['uploaded', 'verification_in_progress'].includes(props.task.status));

const visibleQuestions = computed(() => {
    const rows = props.verification?.questions ?? [];

    if (filterMode.value === 'pending') {
        return rows.filter((row) => !row.is_verified);
    }

    if (filterMode.value === 'verified') {
        return rows.filter((row) => row.is_verified);
    }

    return rows;
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

        // Keep in-progress edits; only refresh when server marked verified / fields match empty form.
        if (row.is_verified && !questionForms[row.question_id].processing) {
            questionForms[row.question_id] = buildForm(row);
        }
    });
};

syncQuestionForms();

watch(
    () => props.verification?.questions,
    () => syncQuestionForms(),
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

    form.post(route('content.tasks.verification-question', props.task.id), {
        preserveScroll: true,
    });
};

const pingSession = () => {
    if (!props.task.can_work) {
        return;
    }
    router.post(route('content.tasks.ping-session', props.task.id), {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['activeSeconds'],
    });
};

let pingTimer;
onMounted(() => {
    pingSession();
    pingTimer = window.setInterval(pingSession, 60000);
});
onUnmounted(() => clearInterval(pingTimer));
</script>

<template>
    <Head :title="`Ch ${task.chapter?.chapter_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        {{ task.chapter?.grade_name }} · Ch {{ task.chapter?.chapter_number }}
                    </h2>
                    <p class="text-sm text-gray-500">{{ task.status_label }} · {{ formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}</p>
                </div>
                <Link :href="route('content.tasks.index')" class="text-sm text-indigo-600 hover:underline">← My tasks</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-4 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-950">
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                    Active time tracked: <strong>{{ formatDuration(activeSeconds) }}</strong> (pauses after 5 min idle).
                </div>

                <div v-if="task.awaiting_agreement" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-700">
                        Admin offered <strong>{{ formatInr(task.offered_amount_inr) }}</strong> for this chapter.
                        Agree to start work.
                    </p>
                    <PrimaryButton class="mt-4" type="button" :disabled="agreeForm.processing" @click="agreeForm.post(route('content.tasks.agree', task.id))">
                        I agree — start work
                    </PrimaryButton>
                </div>

                <div v-else-if="['in_progress', 'uploaded', 'verification_in_progress', 'verified'].includes(task.status)" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-700">
                        Review each question below line by line (text, options, correct answer, hint, explanation). Edit if needed, then
                        <strong>Save &amp; mark verified</strong> one by one.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <a v-if="textbookChapterUrl" :href="textbookChapterUrl" class="text-sm font-medium text-indigo-600 hover:underline">
                            Open textbook chapter →
                        </a>
                        <SecondaryButton
                            v-if="['in_progress', 'uploaded'].includes(task.status)"
                            type="button"
                            :disabled="uploadForm.processing"
                            @click="uploadForm.post(route('content.tasks.mark-uploaded', task.id))"
                        >
                            Mark upload complete
                        </SecondaryButton>
                    </div>
                </div>

                <div v-if="verification" class="space-y-4">
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">Verification progress</p>
                                <p class="mt-1 text-sm text-gray-600">
                                    {{ verificationSummary.verified }} verified · {{ verificationSummary.unverified }} remaining · {{ verificationSummary.total }} total
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs">
                                <button
                                    type="button"
                                    class="rounded-md border px-3 py-1.5"
                                    :class="filterMode.value === 'all' ? 'border-indigo-400 bg-indigo-50 text-indigo-900' : 'border-gray-300 text-gray-700'"
                                    @click="filterMode.value = 'all'"
                                >
                                    All
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border px-3 py-1.5"
                                    :class="filterMode.value === 'pending' ? 'border-amber-400 bg-amber-50 text-amber-900' : 'border-gray-300 text-gray-700'"
                                    @click="filterMode.value = 'pending'"
                                >
                                    Pending
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border px-3 py-1.5"
                                    :class="filterMode.value === 'verified' ? 'border-emerald-400 bg-emerald-50 text-emerald-900' : 'border-gray-300 text-gray-700'"
                                    @click="filterMode.value = 'verified'"
                                >
                                    Verified
                                </button>
                            </div>
                        </div>
                        <p v-if="verificationSummary.total === 0" class="mt-2 text-sm text-amber-800">
                            No published MCQ questions found yet. Open the textbook chapter, tick Approved, click Save MCQ sets, then mark upload complete again.
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

                            <div v-if="row.diagram_url" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">Diagram</p>
                                <img :src="row.diagram_url" alt="Question diagram" class="max-h-56 rounded border border-slate-200 bg-white object-contain">
                            </div>
                            <p v-else class="text-xs text-gray-500">No diagram for this question.</p>

                            <div class="flex flex-wrap items-center gap-3 pt-1">
                                <PrimaryButton
                                    v-if="canEditQuestions"
                                    type="button"
                                    :disabled="questionForms[row.question_id].processing"
                                    @click="saveQuestion(row.question_id)"
                                >
                                    {{ questionForms[row.question_id].processing ? 'Saving…' : (row.is_verified ? 'Save again' : 'Save & mark verified') }}
                                </PrimaryButton>
                                <span v-if="row.correct_letter && row.is_verified" class="text-xs text-emerald-700">
                                    Correct answer: {{ row.correct_letter }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div v-if="task.status === 'verification_in_progress' || task.status === 'verified'" class="flex flex-wrap gap-3">
                        <PrimaryButton
                            type="button"
                            :disabled="completeForm.processing || verificationSummary.unverified > 0"
                            @click="completeForm.post(route('content.tasks.complete-verification', task.id))"
                        >
                            All verified — lock verification
                        </PrimaryButton>
                        <PrimaryButton
                            v-if="task.status === 'verified'"
                            type="button"
                            :disabled="submitForm.processing"
                            @click="submitForm.post(route('content.tasks.submit-for-publish', task.id))"
                        >
                            Submit for admin publish
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
