<script setup>
import ContentAiReviewPanel from '@/Components/ContentAiReviewPanel.vue';
import ContentVerificationPanel from '@/Components/ContentVerificationPanel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { safeRoute } from '@/utils/routes';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    task: { type: Object, required: true },
    verification: { type: Object, default: null },
    activeSeconds: { type: Number, default: 0 },
    textbookChapterUrl: { type: String, default: '' },
});

const page = usePage();
const agreeForm = useForm({});
const startReviewForm = useForm({});

const verificationPendingCount = computed(() =>
    Number(props.verification?.summary?.unverified ?? 0),
);

const taskPath = (suffix = '') => `/content/tasks/${props.task.id}${suffix}`;

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;
const formatDuration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m ${s}s`;
};

const pingSession = () => {
    if (!props.task.can_work) {
        return;
    }
    router.post(safeRoute('content.tasks.ping-session', props.task.id, taskPath('/ping-session')), {}, {
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
                        {{ task.chapter?.grade_name }} · {{ task.chapter?.textbook_name || 'Book' }} · Ch {{ task.chapter?.chapter_number }}
                    </h2>
                    <p class="text-sm text-gray-500">
                        {{ task.status_label }} · {{ task.rate_description || formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}
                        · PDF {{ task.has_pdf ? 'uploaded' : 'missing' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <Link
                        v-if="task.textbook_chapter_id"
                        :href="safeRoute('content.chapters.show', task.textbook_chapter_id, `/content/chapters/${task.textbook_chapter_id}`)"
                        class="text-sm text-indigo-600 hover:underline"
                    >
                        View all questions
                    </Link>
                    <Link :href="safeRoute('content.tasks.index', null, '/content/tasks')" class="text-sm text-indigo-600 hover:underline">← My tasks</Link>
                </div>
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
                        Admin offered <strong>{{ task.rate_description || formatInr(task.offered_amount_inr) }}</strong>.
                        Agree to start work.
                    </p>
                    <PrimaryButton
                        class="mt-4"
                        type="button"
                        :disabled="agreeForm.processing"
                        @click="agreeForm.post(safeRoute('content.tasks.agree', task.id, taskPath('/agree')))"
                    >
                        I agree — start work
                    </PrimaryButton>
                </div>

                <div v-else-if="task.needs_review && !verification" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-700">
                        MCQ sets are saved. Open review to check each question — fix options and explanations, then submit.
                    </p>
                    <PrimaryButton
                        class="mt-4"
                        type="button"
                        :disabled="startReviewForm.processing"
                        @click="startReviewForm.post(safeRoute('content.tasks.start-review', task.id, taskPath('/start-review')))"
                    >
                        Review &amp; complete →
                    </PrimaryButton>
                </div>

                <div v-else-if="verification" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-700">
                        One question at a time: review details, edit if needed, then
                        <strong>Save &amp; mark verified → next</strong>.
                        Upload a figure when the question needs one.
                        Or run <strong>AI review</strong> to auto-clear the easy ones.
                    </p>
                    <a v-if="textbookChapterUrl" :href="textbookChapterUrl" class="mt-3 inline-block text-sm font-medium text-indigo-600 hover:underline">
                        Open textbook chapter →
                    </a>
                </div>

                <ContentAiReviewPanel
                    v-if="verification"
                    :run-id="verification.run_id"
                    :pending-count="verificationPendingCount"
                    :ai-review-route="safeRoute('content.tasks.verification-ai-review', task.id, taskPath('/verification-ai-review'))"
                />

                <ContentVerificationPanel
                    v-if="verification"
                    :task="task"
                    :verification="verification"
                    :save-question-route="safeRoute('content.tasks.verification-question', task.id, taskPath('/verification-question'))"
                    :skip-route="safeRoute('content.tasks.verification-skip', task.id, taskPath('/verification-skip'))"
                    :unskip-route="safeRoute('content.tasks.verification-unskip', task.id, taskPath('/verification-unskip'))"
                    :upload-diagram-route="safeRoute('content.tasks.verification-diagram', task.id, taskPath('/verification-diagram'))"
                    :remove-diagram-route="safeRoute('content.tasks.verification-diagram.remove', task.id, taskPath('/verification-diagram/remove'))"
                    :complete-verification-route="safeRoute('content.tasks.complete-verification', task.id, taskPath('/complete-verification'))"
                    :submit-for-publish-route="safeRoute('content.tasks.submit-for-publish', task.id, taskPath('/submit-for-publish'))"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
