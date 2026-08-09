<script setup>
import AdminContentVerificationBatch from '@/Components/AdminContentVerificationBatch.vue';
import ContentVerificationPanel from '@/Components/ContentVerificationPanel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { safeRoute } from '@/utils/routes';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    task: { type: Object, required: true },
    verification: { type: Object, default: null },
    activeSeconds: { type: Number, default: 0 },
});

const page = usePage();
const publishForm = useForm({});
const reviewMode = ref('batch');

const adminTaskPath = (suffix = '') => `/admin/content-tasks/${props.task.id}${suffix}`;

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;
const formatDuration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m ${s}s`;
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
                <Link :href="safeRoute('admin.content-tasks.index', null, '/admin/content-tasks')" class="text-sm text-indigo-600 hover:underline">← All tasks</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6">
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
                        Review questions in batches of 10. Tick OK to verify, or flag with a remark to send back — one email when you finish.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <Link
                            v-if="task.chapter?.id"
                            :href="safeRoute('admin.textbooks.show', task.chapter.id, `/admin/textbooks/chapters/${task.chapter.id}`)"
                            class="text-sm font-medium text-indigo-700 hover:underline"
                        >
                            Open textbook chapter →
                        </Link>
                        <button
                            v-if="verification && task.can_verify_questions"
                            type="button"
                            class="text-sm font-medium text-indigo-700 hover:underline"
                            @click="reviewMode = reviewMode === 'batch' ? 'detail' : 'batch'"
                        >
                            {{ reviewMode === 'batch' ? 'Switch to one-by-one edit' : 'Switch to batch table' }}
                        </button>
                    </div>
                </div>

                <AdminContentVerificationBatch
                    v-if="verification && task.can_verify_questions && reviewMode === 'batch'"
                    :task="task"
                    :verification="verification"
                    :batch-verify-route="safeRoute('admin.content-tasks.verification-batch', task.id, adminTaskPath('/verification-batch'))"
                    :return-route="safeRoute('admin.content-tasks.return-for-reverification', task.id, adminTaskPath('/return-for-reverification'))"
                    :upload-diagram-route="safeRoute('admin.content-tasks.verification-diagram', task.id, adminTaskPath('/verification-diagram'))"
                    :remove-diagram-route="safeRoute('admin.content-tasks.verification-diagram.remove', task.id, adminTaskPath('/verification-diagram/remove'))"
                    :can-return="Boolean(task.can_return_for_reverification)"
                />

                <ContentVerificationPanel
                    v-else-if="verification && task.can_verify_questions"
                    :task="task"
                    :verification="verification"
                    :save-question-route="safeRoute('admin.content-tasks.verification-question', task.id, adminTaskPath('/verification-question'))"
                    :upload-diagram-route="safeRoute('admin.content-tasks.verification-diagram', task.id, adminTaskPath('/verification-diagram'))"
                    :remove-diagram-route="safeRoute('admin.content-tasks.verification-diagram.remove', task.id, adminTaskPath('/verification-diagram/remove'))"
                    :editable-statuses="['uploaded', 'verification_in_progress', 'verified', 'submitted_for_publish', 'published']"
                    :show-complete-actions="false"
                />

                <div
                    v-if="task.can_publish"
                    class="rounded-lg border border-emerald-300 bg-emerald-50 p-4"
                >
                    <p class="text-sm font-semibold text-emerald-950">Ready to publish</p>
                    <p class="mt-1 text-sm text-emerald-900">
                        All questions are verified. Publish to clear this from the verifying list.
                    </p>
                    <PrimaryButton
                        class="mt-3"
                        type="button"
                        :disabled="publishForm.processing"
                        @click="publishForm.post(safeRoute('admin.content-tasks.publish', task.id, adminTaskPath('/publish')))"
                    >
                        {{ publishForm.processing ? 'Publishing…' : 'Publish task' }}
                    </PrimaryButton>
                </div>

                <div v-else-if="task.status === 'submitted_for_publish'" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
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
