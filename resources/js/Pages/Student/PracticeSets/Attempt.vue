<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import WorksheetPdfViewer from '@/Components/WorksheetPdfViewer.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AttemptFullscreenGate from '@/Components/AttemptFullscreenGate.vue';
import AttemptHiddenOverlay from '@/Components/AttemptHiddenOverlay.vue';
import AttemptLockedOverlay from '@/Components/AttemptLockedOverlay.vue';
import AttemptIntegrityNotice from '@/Components/AttemptIntegrityNotice.vue';
import AttemptProtectionBadge from '@/Components/AttemptProtectionBadge.vue';
import ReportQuestionIssueButton from '@/Components/ReportQuestionIssueButton.vue';
import { useAttemptActiveTimer } from '@/composables/useAttemptActiveTimer';
import { useAttemptContentProtection } from '@/composables/useAttemptContentProtection';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    attempt: Object,
    practiceSet: Object,
    questions: Array,
    reportedQuestionIds: { type: Array, default: () => [] },
    referencePdfUrl: { type: String, default: null },
    integrity: {
        type: Object,
        default: () => ({ mode: 'off', enabled: false }),
    },
});

const page = usePage();
const answers = ref({});
const reportedIds = ref([...(props.reportedQuestionIds || [])]);
const fullscreenReady = ref(!(props.integrity?.require_fullscreen ?? false));

const { elapsed, formatTime } = useAttemptActiveTimer(props.attempt?.id, {
    active_seconds: props.attempt?.active_seconds ?? 0,
    active_session_started_at: props.attempt?.active_session_started_at,
});

const protectionMode = computed(() => props.integrity?.mode ?? 'off');
const needsFullscreenGate = computed(() => props.integrity?.require_fullscreen ?? false);

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

const isTest = computed(() => props.practiceSet?.kind_label === 'Test');
const canShowAttempt = computed(() => !needsFullscreenGate.value || fullscreenReady.value);

const form = useForm({
    answers: {},
});

const setLabel = () => props.practiceSet.set_code || `Set ${props.practiceSet.set_number}`;

const isReported = (questionId) => reportedIds.value.includes(questionId);

const selectOption = (questionId, optionId) => {
    if (isReported(questionId)) {
        return;
    }
    answers.value[questionId] = optionId;
};

const onIssueReported = (questionId) => {
    if (!reportedIds.value.includes(questionId)) {
        reportedIds.value = [...reportedIds.value, questionId];
    }
    delete answers.value[questionId];
};

const activeQuestions = computed(() => props.questions.filter((q) => !isReported(q.id)));

const submit = () => {
    form.answers = { ...answers.value };
    form.post(route('student.attempts.submit', props.attempt.id));
};

const allAnswered = () => activeQuestions.value.every((q) => answers.value[q.id]);
</script>

<template>
    <Head :title="setLabel()" />

    <AuthenticatedLayout>
        <AttemptFullscreenGate
            v-if="needsFullscreenGate"
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
                    <p class="text-sm text-gray-500">{{ practiceSet.kind_label }}</p>
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
                <span class="shrink-0 rounded-full bg-gray-100 px-3 py-1 font-mono text-sm">{{ formatTime(elapsed) }}</span>
            </div>
        </template>

        <div v-if="canShowAttempt" :class="protectionEnabled ? 'attempt-protected py-12' : 'py-12'">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <AttemptIntegrityNotice :is-test="isTest" :mode="protectionMode" />

                <div v-if="page.props.flash?.success" class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-900">
                    {{ page.props.flash.success }}
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm">
                    <p class="text-sm text-gray-600">
                        Read each question and select one answer. Reported misprints are skipped and do not affect your score.
                    </p>
                </div>

                <div class="space-y-5">
                    <div
                        v-for="q in questions"
                        :key="q.id"
                        class="rounded-lg bg-white p-5 shadow-sm"
                        :class="isReported(q.id) ? 'opacity-80 ring-1 ring-amber-200' : ''"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <p class="text-sm font-semibold text-indigo-600">Question {{ q.number }}</p>
                            <ReportQuestionIssueButton
                                v-if="!isReported(q.id)"
                                :action="route('student.attempts.report-issue', attempt.id)"
                                :fields="{ question_id: q.id }"
                                :disabled="attemptLocked || form.processing"
                                @reported="onIssueReported(q.id)"
                            />
                            <span
                                v-else
                                class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900"
                            >
                                Reported — skipped (no marks lost)
                            </span>
                        </div>

                        <div class="mt-3">
                            <QuestionBody
                                :question-text="q.question_text"
                                :diagram-url="q.diagram_url"
                                enlarge-diagram
                            />
                        </div>

                        <div v-if="!isReported(q.id)" class="mt-4 space-y-2">
                            <label
                                v-for="(opt, optIndex) in q.options"
                                :key="opt.id"
                                class="flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3 text-sm transition"
                                :class="answers[q.id] === opt.id
                                    ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-200'
                                    : 'border-gray-200 hover:border-indigo-200 hover:bg-gray-50'"
                            >
                                <input
                                    type="radio"
                                    :name="`q-${q.id}`"
                                    :value="opt.id"
                                    :checked="answers[q.id] === opt.id"
                                    class="mt-1 shrink-0 text-indigo-600"
                                    @change="selectOption(q.id, opt.id)"
                                />
                                <McqOptionLine :index="optIndex" :text="opt.option_text" />
                            </label>
                        </div>
                    </div>
                </div>

                <WorksheetPdfViewer
                    v-if="referencePdfUrl"
                    :url="referencePdfUrl"
                    title="Reference worksheet (optional)"
                    helper-text="Extra reference material for this topic. Answer using the questions above."
                    :protected="protectionEnabled"
                />

                <div class="sticky bottom-4 rounded-lg bg-white p-4 shadow-lg">
                    <p class="mb-3 text-sm text-gray-600">
                        <template v-if="attemptLocked">
                            Attempt locked — ask your teacher to unlock. Submit is disabled.
                        </template>
                        <template v-else>
                            {{ Object.keys(answers).length }} / {{ activeQuestions.length }} answered
                            <span v-if="reportedIds.length" class="text-amber-800">
                                · {{ reportedIds.length }} reported
                            </span>
                        </template>
                    </p>
                    <PrimaryButton :disabled="attemptLocked || form.processing || !allAnswered()" @click="submit">
                        Submit {{ practiceSet.kind_label === 'Test' ? 'test' : 'practice set' }}
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
