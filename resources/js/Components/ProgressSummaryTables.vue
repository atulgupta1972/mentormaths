<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatDate } from '@/utils/dates';
import { formatScoreLabel } from '@/utils/scores';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    summary: {
        type: Object,
        default: null,
    },
    canDeassign: {
        type: Boolean,
        default: false,
    },
});

const deassignForm = useForm({});
const reviewRow = ref(null);
const copiedFeedback = ref(false);

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

const reviewCount = (row) => (row.review_items || []).length;

const reviewLabel = (row) => {
    const count = reviewCount(row);

    return count > 0 ? `${count} need review` : '—';
};

const hasQuestionDrillDown = (row) =>
    (row.review_items || []).some((item) => item.question_text || item.question_id);

const stats = computed(() => props.summary?.stats || {});
const hasSummary = computed(() => Boolean(props.summary));

const canRemoveAssignment = (row) =>
    props.canDeassign
    && row.assignment_id
    && ['assigned', 'in_progress'].includes(row.assignment_status);

const openReview = (row) => {
    if (reviewCount(row) === 0) {
        return;
    }

    reviewRow.value = row;
    copiedFeedback.value = false;
};

const closeReview = () => {
    reviewRow.value = null;
    copiedFeedback.value = false;
};

const feedbackText = computed(() => {
    const row = reviewRow.value;

    if (!row) {
        return '';
    }

    const lines = [
        `${row.set_code} — questions to review (${reviewCount(row)})`,
        detailLabel(row),
        '',
    ];

    (row.review_items || []).forEach((item, index) => {
        lines.push(`${index + 1}. ${item.label || `Q${item.number}`}`);

        if (item.question_text) {
            lines.push(`   Q: ${String(item.question_text).replace(/<[^>]+>/g, '')}`);
        }

        if (item.student_answer) {
            lines.push(`   Student: ${item.student_answer}`);
        }

        if (item.correct_answer) {
            lines.push(`   Correct: ${item.correct_answer}`);
        }

        if (item.help_asked_label) {
            lines.push(`   Help: ${item.help_asked_label}`);
        }

        lines.push('');
    });

    return lines.join('\n').trim();
});

const copyFeedbackNotes = async () => {
    if (!feedbackText.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(feedbackText.value);
        copiedFeedback.value = true;
        window.setTimeout(() => {
            copiedFeedback.value = false;
        }, 2000);
    } catch {
        // ignore
    }
};

const deassign = (row) => {
    if (!confirm(`Remove ${row.set_code}? The student will no longer see this assignment.`)) {
        return;
    }

    deassignForm.delete(route('admin.set-assignments.destroy', row.assignment_id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div v-if="hasSummary" class="space-y-6 rounded-lg border border-gray-200 bg-gray-50 p-4">
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-700">
            <span><strong>Completed:</strong> {{ stats.completed_count || 0 }}</span>
            <span><strong>Pending:</strong> {{ stats.pending_count || 0 }}</span>
            <span><strong>Overdue:</strong> {{ stats.overdue_count || 0 }}</span>
            <span v-if="stats.overall_score_label"><strong>Overall:</strong> {{ stats.overall_score_label }}</span>
        </div>

        <div
            v-if="summary.engagement || summary.period_label"
            class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-700"
        >
            <span v-if="summary.period_label"><strong>Period:</strong> {{ summary.period_label }}</span>
            <span v-if="stats.time_spent_label"><strong>Time spent:</strong> {{ stats.time_spent_label }}</span>
            <span v-if="stats.total_days != null">
                <strong>Days logged in:</strong>
                {{ stats.days_logged_in || 0 }} / {{ stats.total_days || 0 }}
                <span class="text-gray-500">(not logged in: {{ stats.days_not_logged_in || 0 }})</span>
            </span>
        </div>

        <div
            v-if="summary.mentor_remark"
            class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950"
        >
            <strong>Mentor remark:</strong> {{ summary.mentor_remark }}
        </div>

        <section v-if="summary.completed_by_date?.length || summary.completed_by_chapter?.length">
            <h4 class="text-sm font-semibold text-gray-900">
                {{ summary.period_filtered ? 'Completed in this period' : 'Completed work' }}
            </h4>
            <p class="mt-1 text-xs text-gray-500">
                Click a review count to see the exact questions the student got wrong.
            </p>

            <div
                v-for="group in (summary.completed_by_date?.length ? summary.completed_by_date : summary.completed_by_chapter)"
                :key="`completed-${group.date || group.chapter_name}`"
                class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-white"
            >
                <p class="border-b border-gray-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-900">
                    {{ group.date_label || group.chapter_name }}
                </p>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th v-if="!summary.completed_by_date?.length" class="px-3 py-2">Date</th>
                            <th class="px-3 py-2">Set</th>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Topic</th>
                            <th class="px-3 py-2">Chapter</th>
                            <th class="px-3 py-2">Score</th>
                            <th class="px-3 py-2">Review</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in group.rows" :key="`completed-row-${row.assignment_id}-${row.latest_attempt_number || 1}`">
                            <td v-if="!summary.completed_by_date?.length" class="whitespace-nowrap px-3 py-2 text-gray-700">{{ submittedDate(row) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold text-gray-900">
                                <button
                                    v-if="reviewCount(row) > 0"
                                    type="button"
                                    class="text-left font-mono font-semibold text-indigo-700 hover:underline"
                                    @click="openReview(row)"
                                >
                                    {{ row.set_code }}
                                </button>
                                <span v-else>{{ row.set_code }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ row.kind_label }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ detailLabel(row) }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ row.chapter_name || '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-900">
                                {{ scoreLabel(row) }}
                                <span v-if="(row.latest_attempt_number || 0) > 1" class="text-xs text-gray-500">
                                    · Attempt {{ row.latest_attempt_number }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">
                                <button
                                    v-if="reviewCount(row) > 0"
                                    type="button"
                                    class="font-medium text-rose-700 hover:underline"
                                    @click="openReview(row)"
                                >
                                    {{ reviewLabel(row) }} →
                                </button>
                                <span v-else>—</span>
                            </td>
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
                            <th v-if="canDeassign" class="px-3 py-2">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in group.rows" :key="`overdue-row-${row.assignment_id}`">
                            <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold text-gray-900">{{ row.set_code }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ row.kind_label }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ detailLabel(row) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ targetDate(row) }}</td>
                            <td v-if="canDeassign" class="whitespace-nowrap px-3 py-2">
                                <DangerButton
                                    v-if="canRemoveAssignment(row)"
                                    type="button"
                                    class="!px-2 !py-1 !text-xs"
                                    :disabled="deassignForm.processing"
                                    @click="deassign(row)"
                                >
                                    Remove
                                </DangerButton>
                            </td>
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
                            <th v-if="canDeassign" class="px-3 py-2">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in group.rows" :key="`pending-row-${row.assignment_id}`">
                            <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold text-gray-900">{{ row.set_code }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ row.kind_label }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ detailLabel(row) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ targetDate(row) }}</td>
                            <td v-if="canDeassign" class="whitespace-nowrap px-3 py-2">
                                <DangerButton
                                    v-if="canRemoveAssignment(row)"
                                    type="button"
                                    class="!px-2 !py-1 !text-xs"
                                    :disabled="deassignForm.processing"
                                    @click="deassign(row)"
                                >
                                    Remove
                                </DangerButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p
            v-if="!summary.completed_by_date?.length && !summary.completed_by_chapter?.length && !summary.pending_by_chapter?.length && !summary.overdue_by_chapter?.length"
            class="text-sm text-gray-500"
        >
            {{ summary.period_filtered ? 'No work completed in this date range.' : 'No assignments to show for this date.' }}
        </p>

        <Modal :show="Boolean(reviewRow)" max-width="2xl" @close="closeReview">
            <div v-if="reviewRow" class="p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-rose-700">Questions to review</p>
                        <h3 class="mt-1 font-mono text-lg font-semibold text-gray-900">{{ reviewRow.set_code }}</h3>
                        <p class="text-sm text-gray-600">
                            {{ detailLabel(reviewRow) }}
                            <span v-if="reviewRow.chapter_name"> · {{ reviewRow.chapter_name }}</span>
                        </p>
                        <p class="mt-1 text-sm text-gray-700">
                            Score: {{ scoreLabel(reviewRow) }} · {{ reviewCount(reviewRow) }} need review
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <SecondaryButton type="button" @click="copyFeedbackNotes">
                            {{ copiedFeedback ? 'Copied!' : 'Copy for feedback' }}
                        </SecondaryButton>
                        <Link
                            v-if="reviewRow.assignment_id"
                            :href="route('admin.set-assignments.show', reviewRow.assignment_id)"
                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50"
                        >
                            Full attempt
                        </Link>
                    </div>
                </div>

                <div class="mt-4 max-h-[65vh] space-y-3 overflow-y-auto pr-1">
                    <div
                        v-for="(item, index) in reviewRow.review_items"
                        :key="`review-${item.question_id || index}`"
                        class="rounded-lg border border-rose-100 bg-rose-50/40 p-3"
                    >
                        <p class="text-xs font-semibold uppercase tracking-wide text-rose-800">
                            {{ item.label || `Q${item.number || index + 1}` }}
                        </p>
                        <div v-if="item.question_text" class="mt-2 text-sm text-gray-900">
                            <QuestionBody :question-text="item.question_text" :diagram-url="item.diagram_url" :compact="true" />
                        </div>
                        <p v-else-if="!hasQuestionDrillDown(reviewRow)" class="mt-2 text-sm text-gray-700">
                            {{ item.label }}
                        </p>
                        <dl class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                            <div v-if="item.student_answer">
                                <dt class="text-xs text-gray-500">Student answered</dt>
                                <dd class="font-medium text-rose-900">{{ item.student_answer }}</dd>
                            </div>
                            <div v-if="item.correct_answer">
                                <dt class="text-xs text-gray-500">Correct answer</dt>
                                <dd class="font-medium text-emerald-800">{{ item.correct_answer }}</dd>
                            </div>
                        </dl>
                        <p v-if="item.help_asked_label" class="mt-2 text-xs text-amber-800">
                            {{ item.help_asked_label }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex justify-end">
                    <SecondaryButton type="button" @click="closeReview">Close</SecondaryButton>
                </div>
            </div>
        </Modal>
    </div>
</template>
