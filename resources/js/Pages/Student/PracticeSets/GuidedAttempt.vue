<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';
import AttemptHiddenOverlay from '@/Components/AttemptHiddenOverlay.vue';
import AttemptIntegrityNotice from '@/Components/AttemptIntegrityNotice.vue';
import AttemptProtectionBadge from '@/Components/AttemptProtectionBadge.vue';
import { useAttemptActiveTimer } from '@/composables/useAttemptActiveTimer';
import { useAttemptContentProtection } from '@/composables/useAttemptContentProtection';
import { optionLetter } from '@/utils/mcqDisplay';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    finished: { type: Boolean, default: false },
    progress: { type: Object, default: null },
    phase: { type: String, default: 'answering' },
    show_explanation: { type: Boolean, default: false },
    can_show_hint: { type: Boolean, default: false },
    can_give_up: { type: Boolean, default: false },
    question: { type: Object, default: null },
    practice_set: { type: Object, default: null },
    attempt: { type: Object, default: null },
    summary: { type: Object, default: null },
    integrity: {
        type: Object,
        default: () => ({ mode: 'off', enabled: false }),
    },
});

const page = usePage();

const { elapsed, formatTime } = useAttemptActiveTimer(props.attempt?.id, {
    active_seconds: props.attempt?.active_seconds ?? 0,
    active_session_started_at: props.attempt?.active_session_started_at,
});

const protectionMode = computed(() => props.integrity?.mode ?? 'off');

const { contentHidden, enabled: protectionEnabled, tabLeaveCount } = useAttemptContentProtection({
    mode: protectionMode.value,
    attemptId: props.attempt?.id,
    trackTabLeaves: props.integrity?.track_tab_leaves ?? false,
    initialTabLeaveCount: props.integrity?.tab_leave_count ?? 0,
});

const answerForm = useForm({ option_id: null, answer_text: '' });
const giveUpForm = useForm({});
const hintForm = useForm({});
const pendingMcqOption = ref(null);

const showMcqConfirm = computed(() => pendingMcqOption.value !== null);

const feedback = computed(() => page.props.flash?.guided_feedback ?? null);
const isFillInBlank = computed(() => props.question?.type === 'fill_in_blank');

const answerPlaceholder = computed(() => {
    const format = props.question?.answer_format;

    if (format === 'integer') {
        return 'Enter a whole number, e.g. -4';
    }

    if (format === 'decimal') {
        return 'Enter a decimal, e.g. 3.5';
    }

    if (format === 'fraction') {
        return 'Enter a fraction, e.g. 3/4 or 1 1/2';
    }

    if (format === 'text') {
        return 'Enter your answer, e.g. < or > or =';
    }

    return 'Enter your answer';
});

const setLabel = () => props.practice_set?.set_code || 'Practice';

const feedbackClass = computed(() => {
    if (!feedback.value) {
        return '';
    }

    return {
        correct: 'border-emerald-200 bg-emerald-50 text-emerald-900',
        retry: 'border-amber-200 bg-amber-50 text-amber-900',
        explained: 'border-sky-200 bg-sky-50 text-sky-900',
        incorrect: 'border-rose-200 bg-rose-50 text-rose-900',
    }[feedback.value.type] || 'border-gray-200 bg-gray-50 text-gray-800';
});

const submitMcqAnswer = (optionId) => {
    answerForm.option_id = optionId;
    answerForm.answer_text = '';
    answerForm.post(route('student.attempts.guided.answer', props.attempt.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingMcqOption.value = null;
        },
    });
};

const promptMcqAnswer = (option, optIndex) => {
    if (!canAnswer.value || answerForm.processing) {
        return;
    }

    pendingMcqOption.value = {
        id: option.id,
        index: optIndex,
        text: option.option_text,
        letter: optionLetter(optIndex),
    };
};

const confirmMcqAnswer = () => {
    if (!pendingMcqOption.value) {
        return;
    }

    submitMcqAnswer(pendingMcqOption.value.id);
};

const cancelMcqConfirm = () => {
    pendingMcqOption.value = null;
};

const submitBlankAnswer = () => {
    answerForm.option_id = null;
    answerForm.post(route('student.attempts.guided.answer', props.attempt.id), {
        preserveScroll: true,
    });
};

const requestHelp = () => {
    if (!confirm('Ask your teacher for help on this sum? It goes on your help list and you move to the next question.')) {
        return;
    }

    giveUpForm.post(route('student.attempts.guided.give-up', props.attempt.id), {
        preserveScroll: true,
    });
};

const requestHint = () => {
    if (!confirm(
        'Show the method hint?\n\nYou can still answer this sum, but it will NOT count toward your first-try score.\n\nTap Cancel to keep trying on your own.',
    )) {
        return;
    }

    hintForm.post(route('student.attempts.guided.request-hint', props.attempt.id), {
        preserveScroll: true,
    });
};

const canAnswer = computed(() => ['answering', 'retry', 'explained'].includes(props.phase));

const hintAvailable = computed(() => {
    if (props.show_explanation) {
        return false;
    }

    return props.can_show_hint || ['answering', 'retry'].includes(props.phase);
});

const helpAvailable = computed(() => props.can_give_up || hintAvailable.value);

watch(
    () => props.question?.id,
    (questionId, previousId) => {
        if (questionId && questionId !== previousId) {
            answerForm.answer_text = '';
            answerForm.option_id = null;
            answerForm.clearErrors();
            pendingMcqOption.value = null;
        }
    },
);
</script>

<template>
    <Head :title="setLabel()" />

    <AuthenticatedLayout>
        <AttemptHiddenOverlay v-if="protectionEnabled && contentHidden" />

        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-500">Guided practice</p>
                    <h2 class="font-mono text-xl font-semibold text-gray-800">{{ setLabel() }}</h2>
                    <AttemptProtectionBadge
                        v-if="protectionEnabled"
                        class="mt-1"
                        :mode="protectionMode"
                        :tab-leave-count="tabLeaveCount"
                    />
                </div>
                <span v-if="attempt" class="shrink-0 rounded-full bg-gray-100 px-3 py-1 font-mono text-sm">{{ formatTime(elapsed) }}</span>
            </div>
        </template>

        <div :class="protectionEnabled ? 'attempt-protected py-10' : 'py-10'">
            <div class="mx-auto max-w-3xl space-y-5 sm:px-6 lg:px-8">
                <AttemptIntegrityNotice :mode="protectionMode" />

                <div v-if="page.props.flash?.success" class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-900">
                    {{ page.props.flash.success }}
                </div>

                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 p-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div v-if="progress" class="rounded-lg bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700">
                            Question {{ progress.current }} of {{ progress.total }}
                        </span>
                        <span class="text-gray-500">First-try score counts; fixes after help are tracked separately.</span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100">
                        <div
                            class="h-full rounded-full bg-indigo-500 transition-all"
                            :style="{ width: `${(progress.current / progress.total) * 100}%` }"
                        />
                    </div>
                </div>

                <div v-if="feedback" class="rounded-lg border p-4 text-sm" :class="feedbackClass">
                    <p>{{ feedback.message }}</p>
                    <div v-if="hintAvailable" class="mt-3 flex flex-wrap gap-2">
                        <SecondaryButton type="button" :disabled="hintForm.processing" @click="requestHint">
                            {{ hintForm.processing ? 'Loading…' : 'Show hint' }}
                        </SecondaryButton>
                    </div>
                </div>

                <div v-if="question" class="rounded-lg bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <p class="text-sm font-semibold text-indigo-600">Question {{ question.number }}</p>
                        <div class="flex flex-wrap gap-2">
                            <SecondaryButton
                                v-if="hintAvailable"
                                type="button"
                                :disabled="hintForm.processing"
                                @click="requestHint"
                            >
                                {{ hintForm.processing ? 'Loading…' : 'Show hint' }}
                            </SecondaryButton>
                            <SecondaryButton
                                v-if="helpAvailable"
                                type="button"
                                class="!border-rose-200 !text-rose-800 hover:!bg-rose-50"
                                :disabled="giveUpForm.processing"
                                @click="requestHelp"
                            >
                                {{ giveUpForm.processing ? 'Sending…' : 'I need help' }}
                            </SecondaryButton>
                        </div>
                    </div>

                    <div class="mt-3">
                        <QuestionBody
                            :question-text="question.question_text"
                            :diagram-url="question.diagram_url"
                        />
                    </div>

                    <div v-if="show_explanation" class="mt-4 rounded-lg border border-sky-200 bg-sky-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-sky-800">Method — theory only</p>
                        <p v-if="question.method_hint" class="mt-2 whitespace-pre-wrap text-sm text-sky-950">{{ question.method_hint }}</p>
                        <p v-else class="mt-2 text-sm text-sky-900">
                            Think about the rules for this type of sum. No final answer is shown here — try again using the idea your teacher taught.
                        </p>
                    </div>

                    <div v-if="isFillInBlank" class="mt-4 space-y-3">
                        <p v-if="question.answer_format_label" class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            {{ question.answer_format_label }}
                        </p>
                        <TextInput
                            :key="question.id"
                            v-model="answerForm.answer_text"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            class="block w-full max-w-xs text-lg"
                            :placeholder="answerPlaceholder"
                            :disabled="!canAnswer || answerForm.processing"
                            @keyup.enter="submitBlankAnswer"
                        />
                        <PrimaryButton
                            type="button"
                            :disabled="!canAnswer || answerForm.processing || !answerForm.answer_text.trim()"
                            @click="submitBlankAnswer"
                        >
                            {{ answerForm.processing ? 'Checking…' : 'Submit answer' }}
                        </PrimaryButton>
                    </div>

                    <div v-else class="mt-4 space-y-2">
                        <p class="text-xs text-gray-500">Tap an option to check it before submitting.</p>
                        <button
                            v-for="(opt, optIndex) in question.options"
                            :key="opt.id"
                            type="button"
                            class="flex w-full items-start gap-3 rounded-lg border px-4 py-3 text-left text-sm transition"
                            :class="pendingMcqOption?.id === opt.id
                                ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-200'
                                : canAnswer && !answerForm.processing
                                    ? 'border-gray-200 hover:border-indigo-300 hover:bg-indigo-50'
                                    : 'cursor-not-allowed border-gray-100 bg-gray-50 opacity-70'"
                            :disabled="!canAnswer || answerForm.processing"
                            @click="promptMcqAnswer(opt, optIndex)"
                        >
                            <McqOptionLine :index="optIndex" :text="opt.option_text" />
                        </button>
                    </div>

                    <div v-if="hintAvailable || helpAvailable" class="mt-4 flex flex-wrap gap-3 border-t pt-4">
                        <SecondaryButton
                            v-if="hintAvailable"
                            type="button"
                            :disabled="hintForm.processing"
                            @click="requestHint"
                        >
                            {{ hintForm.processing ? 'Loading…' : 'Show hint (no first-try mark)' }}
                        </SecondaryButton>
                        <SecondaryButton
                            v-if="helpAvailable"
                            type="button"
                            class="!border-rose-200 !text-rose-800 hover:!bg-rose-50"
                            :disabled="giveUpForm.processing"
                            @click="requestHelp"
                        >
                            {{ giveUpForm.processing ? 'Sending…' : 'I need help' }}
                        </SecondaryButton>
                    </div>
                </div>

                <div v-else-if="finished" class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                    <p class="font-medium">This practice session has ended.</p>
                    <Link :href="route('dashboard')" class="mt-3 inline-block text-indigo-600 hover:underline">
                        Back to dashboard
                    </Link>
                </div>

                <Link :href="route('dashboard')" class="inline-block text-sm text-indigo-600 hover:underline">
                    Back to dashboard
                </Link>
            </div>
        </div>

        <Modal :show="showMcqConfirm" max-width="md" @close="cancelMcqConfirm">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900">Submit this answer?</h3>
                <p class="mt-2 text-sm text-gray-600">
                    You selected option <strong>{{ pendingMcqOption?.letter }}</strong> for question {{ question?.number }}.
                </p>
                <div class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-gray-900">
                    <McqOptionLine :index="pendingMcqOption?.index ?? 0" :text="pendingMcqOption?.text ?? ''" />
                </div>
                <p class="mt-3 text-xs text-gray-500">
                    Your answer will be checked immediately. Tap Cancel to pick a different option.
                </p>
                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <SecondaryButton type="button" :disabled="answerForm.processing" @click="cancelMcqConfirm">
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton type="button" :disabled="answerForm.processing" @click="confirmMcqAnswer">
                        {{ answerForm.processing ? 'Submitting…' : 'Submit answer' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
