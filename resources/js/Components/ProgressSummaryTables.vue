<script setup>
import { formatDate } from '@/utils/dates';
import { formatScoreLabel } from '@/utils/scores';
import { computed } from 'vue';

const props = defineProps({
    summary: {
        type: Object,
        default: null,
    },
});

const detailLabel = (row) => {
    if (row.topic_name) {
        return row.topic_name;
    }

    if (row.display_title) {
        return row.display_title;
    }

    return row.kind_label || 'Practice';
};

const submittedDate = (row) => formatDate(row.submitted_at ? String(row.submitted_at).slice(0, 10) : null);

const targetDate = (row) => formatDate(row.target_date);

const scoreLabel = (row) => row.latest_score_label || formatScoreLabel(row.latest_score, row.latest_max_score);

const reviewLabel = (row) => {
    const count = (row.review_items || []).length;

    return count > 0 ? `${count} need review` : '—';
};

const stats = computed(() => props.summary?.stats || {});
const hasSummary = computed(() => Boolean(props.summary));
</script>

<template>
    <div v-if="hasSummary" class="space-y-6 rounded-lg border border-gray-200 bg-gray-50 p-4">
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-700">
            <span><strong>Completed:</strong> {{ stats.completed_count || 0 }}</span>
            <span><strong>Pending:</strong> {{ stats.pending_count || 0 }}</span>
            <span><strong>Overdue:</strong> {{ stats.overdue_count || 0 }}</span>
            <span v-if="stats.overall_score_label"><strong>Overall:</strong> {{ stats.overall_score_label }}</span>
        </div>

        <section v-if="summary.completed_by_chapter?.length">
            <h4 class="text-sm font-semibold text-gray-900">Completed work</h4>

            <div
                v-for="group in summary.completed_by_chapter"
                :key="`completed-${group.chapter_name}`"
                class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-white"
            >
                <p class="border-b border-gray-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-900">
                    {{ group.chapter_name }}
                </p>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2">Date</th>
                            <th class="px-3 py-2">Set</th>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Topic</th>
                            <th class="px-3 py-2">Score</th>
                            <th class="px-3 py-2">Review</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in group.rows" :key="`completed-row-${row.assignment_id}`">
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ submittedDate(row) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold text-gray-900">{{ row.set_code }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ row.kind_label }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ detailLabel(row) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-900">
                                {{ scoreLabel(row) }}
                                <span v-if="(row.latest_attempt_number || 0) > 1" class="text-xs text-gray-500">
                                    · Attempt {{ row.latest_attempt_number }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ reviewLabel(row) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="summary.overdue_by_chapter?.length">
            <h4 class="text-sm font-semibold text-rose-900">Overdue</h4>

            <div
                v-for="group in summary.overdue_by_chapter"
                :key="`overdue-${group.chapter_name}`"
                class="mt-3 overflow-x-auto rounded-lg border border-rose-200 bg-white"
            >
                <p class="border-b border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-900">
                    {{ group.chapter_name }}
                </p>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2">Set</th>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Topic</th>
                            <th class="px-3 py-2">Due date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in group.rows" :key="`overdue-row-${row.assignment_id}`">
                            <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold text-gray-900">{{ row.set_code }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ row.kind_label }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ detailLabel(row) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ targetDate(row) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="summary.pending_by_chapter?.length">
            <h4 class="text-sm font-semibold text-amber-900">Pending</h4>

            <div
                v-for="group in summary.pending_by_chapter"
                :key="`pending-${group.chapter_name}`"
                class="mt-3 overflow-x-auto rounded-lg border border-amber-200 bg-white"
            >
                <p class="border-b border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">
                    {{ group.chapter_name }}
                </p>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2">Set</th>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Topic</th>
                            <th class="px-3 py-2">Target date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in group.rows" :key="`pending-row-${row.assignment_id}`">
                            <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold text-gray-900">{{ row.set_code }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ row.kind_label }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ detailLabel(row) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ targetDate(row) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p
            v-if="!summary.completed_by_chapter?.length && !summary.pending_by_chapter?.length && !summary.overdue_by_chapter?.length"
            class="text-sm text-gray-500"
        >
            No assignments to show for this date.
        </p>
    </div>
</template>
