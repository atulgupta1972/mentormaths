<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDate, formatDateTime, formatTime } from '@/utils/dates';
import { formatScoreLabel } from '@/utils/scores';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    assignment: Object,
    attempts: Array,
    latestResult: { type: Object, default: null },
});

const timingLabel = (t) => (t === 'late' ? 'Delayed submission' : t === 'on_time' ? 'On time' : '—');

const outcomeClass = (outcome) => {
    if (outcome === 'correct') {
        return 'bg-green-50 text-green-800';
    }

    if (outcome === 'gave_up') {
        return 'bg-rose-50 text-rose-800';
    }

    if (outcome === 'corrected_after_help') {
        return 'bg-amber-50 text-amber-800';
    }

    return 'bg-red-50 text-red-800';
};
</script>

<template>
    <Head :title="`${assignment.set_code} — ${assignment.student_name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">
                    <span class="font-mono text-indigo-600">{{ assignment.set_code }}</span>
                    · {{ assignment.student_name }}
                </h2>
                <p class="text-sm text-gray-500">{{ assignment.display_title }}</p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Chapter</p>
                        <p class="text-lg font-semibold">{{ assignment.chapter_name || '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Topic</p>
                        <p class="text-lg font-semibold">{{ assignment.topic_name || '—' }}</p>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Target date</p>
                        <p class="text-lg font-semibold">{{ formatDate(assignment.target_date) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Submitted</p>
                        <p class="text-lg font-semibold">
                            {{ assignment.submitted_at ? formatDateTime(assignment.submitted_at) : 'Not yet' }}
                        </p>
                        <p v-if="assignment.submission_timing === 'late'" class="text-sm text-amber-700">Delayed submission</p>
                        <p v-else-if="assignment.submitted_at" class="text-sm text-green-700">On time</p>
                    </div>
                </div>

                <div v-if="assignment.latest_score != null" class="rounded-lg bg-indigo-50 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p class="text-3xl font-bold text-indigo-700">{{ assignment.latest_score_label || formatScoreLabel(assignment.latest_score, assignment.latest_max_score) }}</p>
                            <p class="text-sm text-gray-600">Latest score</p>
                        </div>
                        <div v-if="assignment.latest_time_seconds" class="text-right">
                            <p class="text-xl font-semibold text-indigo-700">{{ formatTime(assignment.latest_time_seconds) }}</p>
                            <p class="text-sm text-gray-600">Time taken</p>
                        </div>
                    </div>
                </div>

                <div v-if="latestResult" class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="border-b bg-gray-50 px-4 py-3">
                        <h3 class="text-sm font-semibold text-gray-800">Question breakdown</h3>
                        <p v-if="latestResult.is_guided" class="mt-1 text-xs text-gray-500">
                            {{ latestResult.first_try_correct }} first try ·
                            {{ latestResult.corrected_after_help }} after help ·
                            {{ latestResult.given_up }} given up
                        </p>
                        <p v-else class="mt-1 text-xs text-gray-500">
                            {{ latestResult.wrong_questions.length }} need review ·
                            {{ latestResult.questions.length - latestResult.wrong_questions.length }} correct
                        </p>
                    </div>
                    <div class="divide-y">
                        <div
                            v-for="question in latestResult.questions"
                            :key="question.number"
                            class="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
                            :class="outcomeClass(question.outcome)"
                        >
                            <div>
                                <p class="font-semibold">Q{{ question.number }} · {{ question.outcome_label }}</p>
                                <p v-if="question.topic_name" class="mt-1 text-sm">
                                    Topic: {{ question.topic_name }}
                                    <span v-if="question.chapter_name">({{ question.chapter_name }})</span>
                                </p>
                                <p v-else-if="question.chapter_name" class="mt-1 text-sm">
                                    Chapter: {{ question.chapter_name }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Attempt</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Score</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Time</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Submitted</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Timing</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="att in attempts" :key="att.id">
                                <td class="px-4 py-3">#{{ att.attempt_number }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="att.status === 'submitted'">{{ formatScoreLabel(att.score, att.max_score) }}</span>
                                    <span v-else class="text-yellow-700">In progress</span>
                                </td>
                                <td class="px-4 py-3">{{ formatTime(att.time_seconds) }}</td>
                                <td class="px-4 py-3">{{ att.completed_at ? formatDateTime(att.completed_at) : '—' }}</td>
                                <td class="px-4 py-3">
                                    <span :class="att.submission_timing === 'late' ? 'text-amber-700' : 'text-green-700'">
                                        {{ timingLabel(att.submission_timing) }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="attempts.length === 0">
                                <td colspan="5" class="px-4 py-6 text-center text-gray-500">No attempts yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Link href="javascript:history.back()" class="text-sm text-indigo-600 hover:underline">← Back</Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
