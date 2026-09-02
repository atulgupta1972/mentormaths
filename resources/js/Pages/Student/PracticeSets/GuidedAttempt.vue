<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Modal from '@/Components/Modal.vue';
import ReportQuestionIssueButton from '@/Components/ReportQuestionIssueButton.vue';
import AttemptFullscreenGate from '@/Components/AttemptFullscreenGate.vue';
import AttemptHiddenOverlay from '@/Components/AttemptHiddenOverlay.vue';
import AttemptLockedOverlay from '@/Components/AttemptLockedOverlay.vue';
import AttemptIntegrityNotice from '@/Components/AttemptIntegrityNotice.vue';
import AttemptProtectionBadge from '@/Components/AttemptProtectionBadge.vue';
import { useAttemptActiveTimer } from '@/composables/useAttemptActiveTimer';
import { useAttemptContentProtection } from '@/composables/useAttemptContentProtection';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    finished: { type: Boolean, default: false },
    progress: { type: Object, default: null },
    phase: { type: String, default: 'answering' },
    show_explanation: { type: Boolean, default: false },
    can_show_hint: { type: Boolean, default: false },
    can_give_up: { type: Boolean, default: false },
    can_report_issue: { type: Boolean, default: false },
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
const needsFullscreenGate = computed(() =>
    (props.integrity?.require_fullscreen ?? false) && !props.finished,
);
const fullscreenReady = ref(!needsFullscreenGate.value);

const { contentHidden, enabled: protectionEnabled, tabLeaveCount, attemptLocked, lockLimit } = useAttemptContentProtection({
    mode: protectionMode.value,
    attemptId: props.attempt?.id,
    trackTabLeaves: props.integrity?.track_tab_leaves ?? false,
    locksOnTabLeaves: props.integrity?.locks_on_tab_leaves ?? (protectionMode.value === 'strict'),
    initialTabLeaveCount: props.integrity?.tab_leave_count ?? 0,
    lockLimit: props.integrity?.tab_leave_lock_limit ?? 4,
    initiallyLocked: props.integrity?.locked ?? false,
    requireFullscreen: needsFullscreenGate.value,
});

const canShowAttempt = computed(() => !needsFullscreenGate.value || fullscreenReady.value);

const answerForm = useForm({ option_id: null, answer_text: '' });
const giveUpForm = useForm({});
const hintForm = useForm({});
const selectedOptionId = ref(null);
/** In-page confirm only — native confirm() exits fullscreen and falsely counts a leave. */
const pendingActionConfirm = ref(null);

const showActionConfirm = computed(() => pendingActionConfirm.value !== null);

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

const isWrongFeedback = (value) => value && ['retry', 'incorrect'].includes(value.type);

const selectMcqOption = (option) => {
    if (!canAnswer.value || answerForm.processing) {
        return;
    }

    selectedOptionId.value = option.id;
};

const submitAnswer = () => {
    if (isFillInBlank.value) {
        answerForm.option_id = null;
    } else {
        if (!selectedOptionId.value) {
            return;
        }

        answerForm.option_id = selectedOptionId.value;
        answerForm.answer_text = '';
    }

    answerForm.post(route('student.attempts.guided.answer', props.attempt.id), {
        preserveScroll: true,
    });
};

const requestHelp = () => {
    pendingActionConfirm.value = 'help';
};

const requestHint = () => {
    pendingActionConfirm.value = 'hint';
};

const cancelActionConfirm = () => {
    pendingActionConfirm.value = null;
};

const confirmAction = () => {
    const action = pendingActionConfirm.value;
    pendingActionConfirm.value = null;

    if (action === 'help') {
        giveUpForm.post(route('student.attempts.guided.give-up', props.attempt.id), {
            preserveScroll: true,
        });

        return;
    }

    if (action === 'hint') {
        hintForm.post(route('student.attempts.guided.request-hint', props.attempt.id), {
            preserveScroll: true,
        });
    }
};

const actionConfirmTitle = computed(() => {
    if (pendingActionConfirm.value === 'help') {
        return 'Ask your teacher for help?';
    }

    if (pendingActionConfirm.value === 'hint') {
        return 'Show the method hint?';
    }

    return '';
});

const actionConfirmBody = computed(() => {
    if (pendingActionConfirm.value === 'help') {
        return 'This sum goes on your help list and you move to the next question.';
    }

    if (pendingActionConfirm.value === 'hint') {
        return 'You can still answer this sum, but it will NOT count toward your first-try score.';
    }

    return '';
});

const actionConfirmButton = computed(() => {
    if (pendingActionConfirm.value === 'help') {
        return giveUpForm.processing ? 'Sending…' : 'Yes, I need help';
    }

    if (pendingActionConfirm.value === 'hint') {
        return hintForm.processing ? 'Loading…' : 'Show hint';
    }

    return 'Continue';
});

const canAnswer = computed(() => ['answering', 'retry', 'explained'].includes(props.phase));

const hintAvailable = computed(() => {
    if (props.show_explanation) {
        return false;
    }

    return props.can_show_hint || ['answering', 'retry'].includes(props.phase);
});

const helpAvailable = computed(() => props.can_give_up);

const canSubmit = computed(() => {
    if (!canAnswer.value || answerForm.processing) {
        return false;
    }

    if (isFillInBlank.value) {
        return Boolean(answerForm.answer_text.trim());
    }

    return selectedOptionId.value !== null;
});

const mcqOptionClass = (optionId) => {
    const selected = selectedOptionId.value === optionId;

    if (selected) {
        return 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-200';
    }

    if (canAnswer.value && !answerForm.processing) {
        return 'border-gray-200 hover:border-indigo-300 hover:bg-indigo-50';
    }

    return 'cursor-not-allowed border-gray-100 bg-gray-50 opacity-70';
};

watch(
    () => props.question?.id,
    (questionId, previousId) => {
        if (questionId && questionId !== previousId) {
            answerForm.answer_text = '';
            answerForm.option_id = null;
            answerForm.clearErrors();
            selectedOptionId.value = null;
        }
    },
);

watch(feedback, (value) => {
    if (isWrongFeedback(value) && isFillInBlank.value) {
        answerForm.answer_text = '';
    }
});
</script>

<template>
    <Head :title="setLabel()" />

    <AuthenticatedLayout>
        <AttemptFullscreenGate
            v-if="needsFullscreenGate"
            title="Enter fullscreen for guided practice"
            message="Stay in fullscreen so only Mentor Maths is on screen. Do not switch tabs or open other apps — your teacher is informed of each leave."
            @ready="fullscreenReady = true"
            @lost="fullscreenReady = false"
        />
        <AttemptLockedOverlay
            v-if="protectionEnabled && attemptLocked && canShowAttempt"
            :tab-leave-count="tabLeaveCount"
            :lock-limit="lockLimit"
        />
        <AttemptHiddenOverlay v-else-if="protectionEnabled && contentHidden && canShowAttempt" />

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
                        :locked="attemptLocked"
                        :lock-limit="lockLimit"
                    />
                </div>
                <span v-if="attempt" class="shrink-0 rounded-full bg-gray-100 px-3 py-1 font-mono text-sm">{{ formatTime(elapsed) }}</span>
            </div>
        </template>

        <div v-if="canShowAttempt" :class="protectionEnabled ? 'attempt-protected py-10' : 'py-10'">
            <div class="mx-auto max-w-4xl space-y-5 sm:px-6 lg:px-8">
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
                </div>

                <div v-if="question" class="rounded-lg bg-white p-5 shadow-sm">
                    <p class="text-sm font-semibold text-indigo-600">Question {{ question.number }}</p>

                    <div class="mt-3">
                        <QuestionBody
                            :question-text="question.question_text"
                            :diagram-url="question.diagram_url"
                            enlarge-diagram
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
                            @keyup.enter="submitAnswer"
                        />
                    </div>

                    <div v-else class="mt-4 space-y-2">
                        <p class="text-xs text-gray-500">Select an option, then tap Submit.</p>
                        <button
                            v-for="(opt, optIndex) in question.options"
                            :key="opt.id"
                            type="button"
                            class="flex w-full items-start gap-3 rounded-lg border px-4 py-3 text-left text-sm transition"
                            :class="mcqOptionClass(opt.id)"
                            :disabled="!canAnswer || answerForm.processing"
                            @click="selectMcqOption(opt)"
                        >
                            <McqOptionLine :index="optIndex" :text="opt.option_text" />
                        </button>
                    </div>

                    <div class="mt-6 space-y-3 border-t pt-4">
                        <PrimaryButton
                            type="button"
                            class="w-full sm:w-auto"
                            :disabled="!canSubmit"
                            @click="submitAnswer"
                        >
                            {{ answerForm.processing ? 'Checking…' : 'Submit' }}
                        </PrimaryButton>

                        <div
                            v-if="hintAvailable || helpAvailable || can_report_issue"
                            class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm"
                        >
                            <button
                                v-if="hintAvailable"
                                type="button"
                                class="font-medium text-indigo-700 hover:text-indigo-900 disabled:opacity-50"
                                :disabled="hintForm.processing"
                                @click="requestHint"
                            >
                                {{ hintForm.processing ? 'Loading hint…' : 'Show hint (no first-try mark)' }}
                            </button>
                            <button
                                v-if="helpAvailable"
                                type="button"
                                class="font-medium text-rose-700 hover:text-rose-900 disabled:opacity-50"
                                :disabled="giveUpForm.processing"
                                @click="requestHelp"
                            >
                                {{ giveUpForm.processing ? 'Sending…' : 'I need help' }}
                            </button>
                            <ReportQuestionIssueButton
                                v-if="can_report_issue"
                                :action="route('student.attempts.guided.report-issue', attempt.id)"
                                :disabled="giveUpForm.processing || hintForm.processing || answerForm.processing"
                            />
                        </div>
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

        <Modal :show="showActionConfirm" max-width="md" @close="cancelActionConfirm">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900">{{ actionConfirmTitle }}</h3>
                <p class="mt-2 text-sm text-gray-600">{{ actionConfirmBody }}</p>
                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <SecondaryButton
                        type="button"
                        :disabled="giveUpForm.processing || hintForm.processing"
                        @click="cancelActionConfirm"
                    >
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton
                        type="button"
                        :disabled="giveUpForm.processing || hintForm.processing"
                        @click="confirmAction"
                    >
                        {{ actionConfirmButton }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
