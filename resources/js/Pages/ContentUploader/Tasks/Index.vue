<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    tasks: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({ upload_pending: 0, review_pending: 0, corrections_pending: 0, total_active: 0 }) },
    uploadPending: { type: Array, default: () => [] },
    reviewPending: { type: Array, default: () => [] },
    correctionsPending: { type: Array, default: () => [] },
});

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;
const bucketFilter = ref('all');

const startReview = (taskId) => {
    router.post(route('content.tasks.start-review', taskId));
};

const startCorrection = (correctionId) => {
    router.post(route('content.corrections.start', correctionId));
};

const chapterHref = (task) => {
    if (task.status === 'pending_agreement' || !task.chapter?.id) {
        return route('content.tasks.show', task.id);
    }

    return route('content.textbooks.show', task.chapter.id);
};

const statusTone = (bucket) => ({
    upload_pending: 'bg-amber-50 text-amber-900 ring-amber-200',
    review_pending: 'bg-violet-50 text-violet-900 ring-violet-200',
    done: 'bg-emerald-50 text-emerald-900 ring-emerald-200',
}[bucket] || 'bg-gray-50 text-gray-700 ring-gray-200');

const filteredTasks = computed(() => {
    if (bucketFilter.value === 'all') {
        return props.tasks;
    }

    return props.tasks.filter((task) => task.bucket === bucketFilter.value);
});
</script>

<template>
    <Head title="My content tasks" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">My content tasks</h2>
                <p class="text-sm text-gray-500">Upload chapter PDF + MCQs, then review every question before submit.</p>
                <Link
                    v-if="route().has('content.chapters.index')"
                    :href="route('content.chapters.index')"
                    class="mt-1 inline-block text-sm font-medium text-indigo-600 hover:underline"
                >
                    Browse class → chapter → questions
                </Link>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6">
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-full px-3 py-1 text-xs font-semibold ring-1"
                        :class="bucketFilter === 'all' ? 'bg-slate-800 text-white ring-slate-800' : 'bg-white text-slate-700 ring-slate-200'"
                        @click="bucketFilter = 'all'"
                    >
                        All · {{ tasks.length }}
                    </button>
                    <button
                        type="button"
                        class="rounded-full px-3 py-1 text-xs font-semibold ring-1"
                        :class="bucketFilter === 'upload_pending' ? 'bg-amber-600 text-white ring-amber-600' : 'bg-amber-50 text-amber-900 ring-amber-200'"
                        @click="bucketFilter = 'upload_pending'"
                    >
                        Upload pending · {{ summary.upload_pending }}
                    </button>
                    <button
                        type="button"
                        class="rounded-full px-3 py-1 text-xs font-semibold ring-1"
                        :class="bucketFilter === 'review_pending' ? 'bg-violet-600 text-white ring-violet-600' : 'bg-violet-50 text-violet-900 ring-violet-200'"
                        @click="bucketFilter = 'review_pending'"
                    >
                        Review pending · {{ summary.review_pending }}
                    </button>
                    <span
                        v-if="summary.corrections_pending"
                        class="rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-900"
                    >
                        To correct · {{ summary.corrections_pending }}
                    </span>
                </div>

                <div v-if="correctionsPending.length" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-rose-200">
                    <div class="border-b border-rose-100 bg-rose-50 px-3 py-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-rose-900">Sums to correct</h3>
                    </div>
                    <table class="min-w-full divide-y divide-rose-100 text-xs">
                        <thead class="bg-rose-50/50 text-left uppercase tracking-wide text-rose-800">
                            <tr>
                                <th class="px-2 py-1.5">Chapter</th>
                                <th class="px-2 py-1.5">Question</th>
                                <th class="px-2 py-1.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rose-50">
                            <tr v-for="item in correctionsPending" :key="item.id">
                                <td class="px-2 py-1.5 text-rose-900">{{ item.chapter_label }}</td>
                                <td class="px-2 py-1.5">
                                    <span v-if="item.question_number">Q{{ item.question_number }} · </span>
                                    {{ item.question_text || 'Question to fix' }}
                                    <span v-if="item.remark" class="block text-rose-700">{{ item.remark }}</span>
                                </td>
                                <td class="px-2 py-1.5 text-right">
                                    <button
                                        type="button"
                                        class="rounded bg-rose-700 px-2 py-0.5 text-[11px] font-medium text-white hover:bg-rose-800"
                                        @click="startCorrection(item.id)"
                                    >
                                        Correct →
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="border-b border-gray-100 px-3 py-2">
                        <h3 class="text-sm font-semibold text-gray-900">My assignments</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-xs">
                            <thead class="bg-gray-50 text-left uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-2 py-1.5">Class</th>
                                    <th class="px-2 py-1.5">Book</th>
                                    <th class="px-2 py-1.5">Ch No.</th>
                                    <th class="min-w-[120px] px-2 py-1.5">Chapter head</th>
                                    <th class="min-w-[160px] px-2 py-1.5">Chapter name</th>
                                    <th class="px-2 py-1.5">PDF</th>
                                    <th class="px-2 py-1.5">Status</th>
                                    <th class="px-2 py-1.5">Rate</th>
                                    <th class="px-2 py-1.5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="task in filteredTasks" :key="task.id">
                                    <td class="whitespace-nowrap px-2 py-1.5 font-medium text-gray-900">{{ task.chapter?.grade_name || '—' }}</td>
                                    <td class="px-2 py-1.5 text-gray-700">{{ task.chapter?.textbook_name || '—' }}</td>
                                    <td class="whitespace-nowrap px-2 py-1.5">{{ task.chapter?.chapter_number || '—' }}</td>
                                    <td class="px-2 py-1.5 text-gray-600">{{ task.chapter?.chapter_head_name || '—' }}</td>
                                    <td class="px-2 py-1.5 text-gray-800">{{ task.chapter?.title || '—' }}</td>
                                    <td class="px-2 py-1.5">
                                        <span
                                            class="inline-flex rounded-full px-1.5 py-0.5 text-[10px] font-semibold ring-1"
                                            :class="task.has_pdf ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-rose-50 text-rose-800 ring-rose-200'"
                                        >
                                            {{ task.has_pdf ? 'Yes' : 'Missing' }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <span class="inline-flex rounded-full px-1.5 py-0.5 text-[10px] font-medium ring-1" :class="statusTone(task.bucket)">
                                            {{ task.status_label }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-gray-600">
                                        {{ task.rate_description || formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-right">
                                        <button
                                            v-if="task.bucket === 'review_pending'"
                                            type="button"
                                            class="font-medium text-indigo-600 hover:underline"
                                            @click="startReview(task.id)"
                                        >
                                            Review →
                                        </button>
                                        <Link
                                            v-else-if="task.bucket === 'upload_pending'"
                                            :href="chapterHref(task)"
                                            class="font-medium text-indigo-600 hover:underline"
                                        >
                                            {{ task.status === 'pending_agreement' ? 'Agree →' : 'Upload →' }}
                                        </Link>
                                        <Link
                                            :href="route('content.tasks.show', task.id)"
                                            class="ml-2 text-gray-500 hover:underline"
                                        >
                                            Open
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="!filteredTasks.length">
                                    <td colspan="9" class="px-3 py-8 text-center text-gray-500">
                                        {{ tasks.length ? 'No tasks in this bucket.' : 'No assignments yet.' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
