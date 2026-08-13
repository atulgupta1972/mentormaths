<script setup>
import ContentUploaderTasksPanel from '@/Components/ContentUploaderTasksPanel.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({
    tasks: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({ upload_pending: 0, review_pending: 0, total_active: 0 }) },
    uploadPending: { type: Array, default: () => [] },
    reviewPending: { type: Array, default: () => [] },
});

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;

const startReview = (taskId) => {
    router.post(route('content.tasks.start-review', taskId));
};

const chapterHref = (task) => {
    if (task.status === 'pending_agreement' || !task.chapter?.id) {
        return route('content.tasks.show', task.id);
    }

    return route('content.textbooks.show', task.chapter.id);
};
</script>

<template>
    <Head title="My content tasks" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">My content tasks</h2>
                <p class="text-sm text-gray-500">Upload chapter MCQs, then review every question before submit.</p>
                <Link
                    v-if="route().has('content.chapters.index')"
                    :href="route('content.chapters.index')"
                    class="mt-1 inline-block text-sm font-medium text-indigo-600 hover:underline"
                >
                    Browse class → chapter → questions
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6">
                <ContentUploaderTasksPanel
                    :summary="summary"
                    :upload-pending="uploadPending"
                    :review-pending="reviewPending"
                />

                <div v-if="tasks.length" class="space-y-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">All assignments</h3>
                    <div
                        v-for="task in tasks"
                        :key="task.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200"
                    >
                        <div>
                            <p class="font-semibold text-gray-900">
                                {{ task.chapter?.grade_name }} · Ch {{ task.chapter?.chapter_number }} — {{ task.chapter?.title }}
                            </p>
                            <p class="text-sm text-gray-500">
                                {{ task.bucket_label }} · {{ task.status_label }} · {{ task.rate_description || formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-if="task.bucket === 'review_pending'"
                                type="button"
                                class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
                                @click="startReview(task.id)"
                            >
                                Review &amp; complete →
                            </button>
                            <Link
                                v-else-if="task.bucket === 'upload_pending'"
                                :href="chapterHref(task)"
                                class="text-sm font-medium text-indigo-600 hover:underline"
                            >
                                Upload →
                            </Link>
                            <Link :href="route('content.tasks.show', task.id)" class="text-sm text-gray-500 hover:underline">
                                Open task
                            </Link>
                        </div>
                    </div>
                </div>

                <p v-if="!tasks.length" class="rounded-lg bg-white p-8 text-center text-gray-500 shadow-sm ring-1 ring-gray-200">
                    No assignments yet.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
