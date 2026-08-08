<script setup>
import ContentVerificationPanel from '@/Components/ContentVerificationPanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    task: { type: Object, required: true },
    verification: { type: Object, default: null },
    activeSeconds: { type: Number, default: 0 },
});

const page = usePage();
const publishForm = useForm({});
const returnForm = useForm({ reason: '' });

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;
const formatDuration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m ${s}s`;
};

const sendBack = () => {
    if (!confirm('Send this chapter back to the uploader for re-verification? All verification ticks will be cleared.')) {
        return;
    }

    returnForm.post(route('admin.content-tasks.return-for-reverification', props.task.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Task · Ch ${task.chapter?.chapter_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        {{ task.chapter?.grade_name }} · Ch {{ task.chapter?.chapter_number }} — {{ task.chapter?.title }}
                    </h2>
                    <p class="text-sm text-gray-500">{{ task.status_label }}</p>
                </div>
                <Link :href="route('admin.content-tasks.index')" class="text-sm text-indigo-600 hover:underline">← All tasks</Link>
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

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-xs uppercase text-gray-500">Uploader</p>
                        <p class="font-semibold">{{ task.assignee?.name }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-xs uppercase text-gray-500">Agreed rate</p>
                        <p class="font-semibold">{{ task.agreed_amount_inr ? formatInr(task.agreed_amount_inr) : formatInr(task.offered_amount_inr) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-xs uppercase text-gray-500">Uploader active time</p>
                        <p class="font-semibold">{{ formatDuration(activeSeconds) }}</p>
                    </div>
                </div>

                <div v-if="task.duplicate_override_reason" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm">
                    <strong>Duplicate override:</strong> {{ task.duplicate_override_reason }}
                </div>

                <div v-if="task.admin_notes" class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm whitespace-pre-wrap">
                    <strong>Admin notes:</strong><br>{{ task.admin_notes }}
                </div>

                <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-4">
                    <p class="text-sm text-indigo-950">
                        Fix wrong options or explanations here (same as uploader verification), or
                        <strong>send back to uploader</strong> so they re-verify every question.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <Link
                            v-if="task.chapter?.id"
                            :href="route('admin.textbooks.show', task.chapter.id)"
                            class="text-sm font-medium text-indigo-700 hover:underline"
                        >
                            Open textbook chapter →
                        </Link>
                    </div>
                </div>

                <ContentVerificationPanel
                    v-if="verification && task.can_verify_questions"
                    :task="task"
                    :verification="verification"
                    :save-question-route="route('admin.content-tasks.verification-question', task.id)"
                    :editable-statuses="['uploaded', 'verification_in_progress', 'verified', 'submitted_for_publish', 'published']"
                    :show-complete-actions="false"
                />

                <div v-if="task.can_return_for_reverification" class="rounded-lg border border-amber-300 bg-amber-50 p-4">
                    <p class="text-sm font-medium text-amber-950">Send back to uploader</p>
                    <p class="mt-1 text-sm text-amber-900">
                        Clears all verification ticks and reopens the task for the uploader. Use when they need to fix options/explanations themselves.
                    </p>
                    <div class="mt-3">
                        <InputLabel value="Message to uploader (optional)" />
                        <textarea
                            v-model="returnForm.reason"
                            rows="2"
                            class="mt-1 block w-full rounded-md border-amber-200 text-sm"
                            placeholder="e.g. All correct answers are option A — please vary B/C/D and fix explanations."
                        />
                    </div>
                    <SecondaryButton
                        class="mt-3"
                        type="button"
                        :disabled="returnForm.processing"
                        @click="sendBack"
                    >
                        {{ returnForm.processing ? 'Sending…' : 'Send back for re-verification' }}
                    </SecondaryButton>
                </div>

                <div v-if="task.status === 'submitted_for_publish'" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-sm text-emerald-900">Uploader submitted this chapter for publish.</p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <PrimaryButton type="button" :disabled="publishForm.processing" @click="publishForm.post(route('admin.content-tasks.publish', task.id))">
                            Mark task published
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
