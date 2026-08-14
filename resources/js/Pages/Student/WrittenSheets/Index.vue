<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatScoreLabel } from '@/utils/scores';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    buckets: { type: Object, required: true },
    counts: { type: Object, required: true },
});

const setLabel = (set) => set.set_code || `Set ${set.set_number}`;

const statusLabel = (set) => {
    if (set.status === 'checking') {
        return 'Under review';
    }
    if (set.status === 'green' || set.status === 'green-late') {
        return set.latest_score_label || formatScoreLabel(set.latest_score, set.latest_max_score) || 'Done';
    }
    if (set.is_overdue) {
        return 'Upload overdue';
    }
    if (set.written_submission_status === 'failed') {
        return 'Upload again / ask teacher';
    }

    return 'Upload photos';
};

const chipClass = (set) => {
    if (set.status === 'checking') {
        return 'border-violet-300 bg-violet-50 text-violet-950';
    }
    if (set.status === 'green' || set.status === 'green-late') {
        return 'border-emerald-300 bg-emerald-50 text-emerald-950';
    }
    if (set.is_overdue || set.written_submission_status === 'failed') {
        return 'border-rose-300 bg-rose-50 text-rose-950';
    }

    return 'border-amber-300 bg-amber-50 text-amber-950';
};
</script>

<template>
    <Head title="My written tests" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">My written tests</h2>
                <p class="text-sm text-gray-500">
                    Upload photos after you finish, then track under-review and scores here.
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-amber-50 px-3 py-3 ring-1 ring-amber-200">
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Upload pending</p>
                        <p class="mt-1 text-2xl font-semibold text-amber-950">{{ counts.upload_pending }}</p>
                    </div>
                    <div class="rounded-lg bg-violet-50 px-3 py-3 ring-1 ring-violet-200">
                        <p class="text-xs font-semibold uppercase tracking-wide text-violet-800">Under review</p>
                        <p class="mt-1 text-2xl font-semibold text-violet-950">{{ counts.under_review }}</p>
                    </div>
                    <div class="rounded-lg bg-emerald-50 px-3 py-3 ring-1 ring-emerald-200">
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Done</p>
                        <p class="mt-1 text-2xl font-semibold text-emerald-950">{{ counts.done }}</p>
                    </div>
                </div>

                <section
                    v-for="section in [
                        { key: 'upload_pending', title: 'Upload pending', rows: buckets.upload_pending },
                        { key: 'under_review', title: 'Under review', rows: buckets.under_review },
                        { key: 'done', title: 'Done', rows: buckets.done },
                    ]"
                    :key="section.key"
                    class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200"
                >
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-600">
                        {{ section.title }} · {{ section.rows.length }}
                    </h3>
                    <div v-if="section.rows.length" class="mt-3 flex flex-wrap gap-2">
                        <Link
                            v-for="set in section.rows"
                            :key="set.assignment_id"
                            :href="route('student.written-assignments.show', set.assignment_id)"
                            class="rounded-md border px-3 py-2 text-sm font-semibold shadow-sm hover:bg-white"
                            :class="chipClass(set)"
                        >
                            <span class="font-mono">{{ setLabel(set) }}</span>
                            <span class="mt-0.5 block text-xs font-medium opacity-80">{{ statusLabel(set) }}</span>
                        </Link>
                    </div>
                    <p v-else class="mt-2 text-sm text-gray-500">Nothing in this bucket.</p>
                </section>

                <p v-if="!counts.total" class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-8 text-center text-sm text-gray-500">
                    No written tests assigned yet.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
