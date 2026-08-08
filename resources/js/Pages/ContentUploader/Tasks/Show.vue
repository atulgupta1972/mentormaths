<script setup>
import ContentVerificationPanel from '@/Components/ContentVerificationPanel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

const props = defineProps({
    task: { type: Object, required: true },
    verification: { type: Object, default: null },
    activeSeconds: { type: Number, default: 0 },
    textbookChapterUrl: { type: String, default: '' },
});

const page = usePage();
const agreeForm = useForm({});
const uploadForm = useForm({});

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
                        One question at a time: review details, edit if needed, then
                        <strong>Save &amp; mark verified → next</strong>.
                        Verified questions move to the Verified tab (open that tab to re-check any).
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

                <ContentVerificationPanel
                    v-if="verification"
                    :task="task"
                    :verification="verification"
                    :save-question-route="route('content.tasks.verification-question', task.id)"
                    :complete-verification-route="route('content.tasks.complete-verification', task.id)"
                    :submit-for-publish-route="route('content.tasks.submit-for-publish', task.id)"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
