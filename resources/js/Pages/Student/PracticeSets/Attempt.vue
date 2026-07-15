<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import WorksheetPdfViewer from '@/Components/WorksheetPdfViewer.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AttemptFullscreenGate from '@/Components/AttemptFullscreenGate.vue';
import AttemptHiddenOverlay from '@/Components/AttemptHiddenOverlay.vue';
import AttemptIntegrityNotice from '@/Components/AttemptIntegrityNotice.vue';
import AttemptProtectionBadge from '@/Components/AttemptProtectionBadge.vue';
import { useAttemptActiveTimer } from '@/composables/useAttemptActiveTimer';
import { useAttemptContentProtection } from '@/composables/useAttemptContentProtection';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    attempt: Object,
    practiceSet: Object,
    questions: Array,
    referencePdfUrl: { type: String, default: null },
    integrity: {
        type: Object,
        default: () => ({ mode: 'off', enabled: false }),
    },
});

const answers = ref({});
const fullscreenReady = ref(!(props.integrity?.require_fullscreen ?? false));

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

const isTest = computed(() => props.practiceSet?.kind_label === 'Test');
const needsFullscreenGate = computed(() => props.integrity?.require_fullscreen ?? false);
const canShowAttempt = computed(() => !needsFullscreenGate.value || fullscreenReady.value);

const form = useForm({
    answers: {},
});

const setLabel = () => props.practiceSet.set_code || `Set ${props.practiceSet.set_number}`;

const selectOption = (questionId, optionId) => {
    answers.value[questionId] = optionId;
};

const submit = () => {
    form.answers = { ...answers.value };
    form.post(route('student.attempts.submit', props.attempt.id));
};

const allAnswered = () => props.questions.every((q) => answers.value[q.id]);
</script>

<template>
    <Head :title="setLabel()" />

    <AuthenticatedLayout>
        <AttemptFullscreenGate
            v-if="needsFullscreenGate && !fullscreenReady"
            @ready="fullscreenReady = true"
        />
        <AttemptHiddenOverlay v-if="protectionEnabled && contentHidden && canShowAttempt" />

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
                    />
                </div>
                <span class="shrink-0 rounded-full bg-gray-100 px-3 py-1 font-mono text-sm">{{ formatTime(elapsed) }}</span>
            </div>
        </template>

        <div v-if="canShowAttempt" :class="protectionEnabled ? 'attempt-protected py-12' : 'py-12'">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <AttemptIntegrityNotice :is-test="isTest" :mode="protectionMode" />

                <div class="rounded-lg bg-white p-4 shadow-sm">
                    <p class="text-sm text-gray-600">
                        Read each question and select one answer. All {{ questions.length }} questions must be answered before you submit.
                    </p>
                </div>

                <div class="space-y-5">
                    <div
                        v-for="q in questions"
                        :key="q.id"
                        class="rounded-lg bg-white p-5 shadow-sm"
                    >
                        <p class="text-sm font-semibold text-indigo-600">Question {{ q.number }}</p>

                        <div class="mt-3">
                            <QuestionBody
                                :question-text="q.question_text"
                                :diagram-url="q.diagram_url"
                                use-html
                            />
                        </div>

                        <div class="mt-4 space-y-2">
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
                        {{ Object.keys(answers).length }} / {{ questions.length }} answered
                    </p>
                    <PrimaryButton :disabled="form.processing || !allAnswered()" @click="submit">
                        Submit {{ practiceSet.kind_label === 'Test' ? 'test' : 'practice set' }}
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
