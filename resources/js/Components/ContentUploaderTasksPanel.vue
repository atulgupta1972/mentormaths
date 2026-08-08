<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    summary: { type: Object, default: () => ({ upload_pending: 0, review_pending: 0, total_active: 0 }) },
    uploadPending: { type: Array, default: () => [] },
    reviewPending: { type: Array, default: () => [] },
    compact: { type: Boolean, default: false },
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
    <section
        v-if="summary.total_active > 0 || uploadPending.length || reviewPending.length"
        class="rounded-xl border border-sky-200 bg-gradient-to-br from-sky-50 to-indigo-50 p-4 shadow-sm"
        :class="compact ? '' : 'space-y-4'"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-sky-900">Content upload tasks</h3>
                <p v-if="!compact" class="mt-1 text-sm text-sky-800">Upload chapter MCQs, then review options and explanations for each question.</p>
            </div>
            <Link :href="route('content.tasks.index')" class="text-sm font-medium text-indigo-700 hover:underline">
                All tasks →
            </Link>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">
                Upload pending: {{ summary.upload_pending }}
            </span>
            <span class="rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-900">
                Review pending: {{ summary.review_pending }}
            </span>
        </div>

        <div v-if="reviewPending.length" class="mt-4 space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-violet-800">Review pending</p>
            <div
                v-for="task in reviewPending"
                :key="`review-${task.id}`"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white/80 p-3 ring-1 ring-violet-200"
            >
                <div>
                    <p class="font-medium text-gray-900">
                        {{ task.chapter?.grade_name }} · Ch {{ task.chapter?.chapter_number }} — {{ task.chapter?.title }}
                    </p>
                    <p class="text-xs text-gray-500">{{ task.status_label }} · {{ formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}</p>
                </div>
                <PrimaryButton type="button" class="!py-2 !text-xs" @click="startReview(task.id)">
                    Review &amp; complete →
                </PrimaryButton>
            </div>
        </div>

        <div v-if="uploadPending.length" class="mt-4 space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Upload pending</p>
            <div
                v-for="task in uploadPending"
                :key="`upload-${task.id}`"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white/80 p-3 ring-1 ring-amber-200"
            >
                <div>
                    <p class="font-medium text-gray-900">
                        {{ task.chapter?.grade_name }} · Ch {{ task.chapter?.chapter_number }} — {{ task.chapter?.title }}
                    </p>
                    <p class="text-xs text-gray-500">{{ task.status_label }} · {{ formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}</p>
                </div>
                <Link :href="chapterHref(task)">
                    <SecondaryButton type="button" class="!py-2 !text-xs">
                        {{ task.status === 'pending_agreement' ? 'Agree & upload →' : 'Upload chapter →' }}
                    </SecondaryButton>
                </Link>
            </div>
        </div>
    </section>
</template>
