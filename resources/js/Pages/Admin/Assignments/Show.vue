<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    assignment: Object,
    attempts: Array,
});

const formatDate = (d) => {
    if (!d) return '—';
    const date = d.includes('T') ? new Date(d) : new Date(d + 'T00:00:00');
    return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
};

const formatTime = (seconds) => {
    if (!seconds) return '—';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m ? `${m}m ${s}s` : `${s}s`;
};

const timingLabel = (t) => (t === 'late' ? 'Delayed submission' : t === 'on_time' ? 'On time' : '—');
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
                        <p class="text-xs text-gray-500">Target date</p>
                        <p class="text-lg font-semibold">{{ formatDate(assignment.target_date) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Submitted</p>
                        <p class="text-lg font-semibold">
                            {{ assignment.submitted_at ? formatDate(assignment.submitted_at) : 'Not yet' }}
                        </p>
                        <p v-if="assignment.submission_timing === 'late'" class="text-sm text-amber-700">Delayed submission</p>
                        <p v-else-if="assignment.submitted_at" class="text-sm text-green-700">On time</p>
                    </div>
                </div>

                <div v-if="assignment.latest_score != null" class="rounded-lg bg-indigo-50 p-6 text-center">
                    <p class="text-3xl font-bold text-indigo-700">{{ assignment.latest_score }}/{{ assignment.latest_max_score }}</p>
                    <p class="text-sm text-gray-600">Latest score</p>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Attempt</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Score</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Submitted</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Timing</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="att in attempts" :key="att.id">
                                <td class="px-4 py-3">#{{ att.attempt_number }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="att.status === 'submitted'">{{ att.score }}/{{ att.max_score }}</span>
                                    <span v-else class="text-yellow-700">In progress</span>
                                </td>
                                <td class="px-4 py-3">{{ att.completed_at ? formatDate(att.completed_at) : '—' }}</td>
                                <td class="px-4 py-3">
                                    <span :class="att.submission_timing === 'late' ? 'text-amber-700' : 'text-green-700'">
                                        {{ timingLabel(att.submission_timing) }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="attempts.length === 0">
                                <td colspan="4" class="px-4 py-6 text-center text-gray-500">No attempts yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Link href="javascript:history.back()" class="text-sm text-indigo-600 hover:underline">← Back</Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
