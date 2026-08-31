<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { hasRoute } from '@/utils/routes';

const props = defineProps({
    gradeLevel: { type: Object, required: true },
    activeYear: { type: Object, default: null },
    examFilter: { type: String, default: 'upcoming' },
    examPlanRows: { type: Array, default: () => [] },
    examPlanStats: { type: Object, default: () => ({}) },
    students_count: { type: Number, default: 0 },
});

const examFilter = ref(props.examFilter || 'upcoming');

const reloadExamFilter = () => {
    router.get(route('mentor.classes.show', props.gradeLevel.id), {
        exam_filter: examFilter.value,
    }, { preserveState: true, preserveScroll: true });
};

watch(examFilter, (value, oldValue) => {
    if (value === oldValue) {
        return;
    }
    reloadExamFilter();
});

const formatDate = (d) => {
    if (!d) {
        return '—';
    }

    return new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const pctLabel = (value) => (value == null ? '—' : `${value}%`);

const displayScorePct = (progress) => {
    if (! progress) {
        return null;
    }

    if (progress.score_pct != null) {
        return progress.score_pct;
    }

    const total = Number(progress.sums_total ?? 0);
    const attempted = Number(progress.sums_attempted ?? 0);
    const correct = Number(progress.sums_correct ?? 0);
    if (attempted > 0) {
        return Math.round((correct / attempted) * 100);
    }

    if (total > 0 && correct > 0) {
        return Math.round((correct / total) * 100);
    }

    return null;
};

const revisionLabel = (progress) => {
    if (!progress) {
        return '—';
    }

    const pending = Number(progress.revision_pending || 0);
    const openWrongs = Number(progress.open_wrongs || 0);

    if (pending <= 0 && openWrongs <= 0) {
        return 'Clear';
    }

    const parts = [];
    if (pending > 0) {
        parts.push(`${pending} pending`);
    }
    if (openWrongs > 0) {
        parts.push(`${openWrongs} wrong`);
    }

    return parts.join(' · ');
};

const studyPlanHref = (studentId) => {
    if (!hasRoute('admin.school-study-plan.index')) {
        return '#';
    }

    return route('admin.school-study-plan.index', { student_id: studentId });
};
</script>

<template>
    <Head :title="gradeLevel.name" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <Link :href="route('mentor.classes.index')" class="text-sm text-teal-700 hover:underline">
                    ← Classes
                </Link>
                <h2 class="mt-1 text-xl font-semibold text-gray-800">{{ gradeLevel.name }}</h2>
                <p v-if="activeYear" class="text-sm text-gray-500">
                    {{ activeYear.name }} · your students only
                </p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-teal-700">{{ students_count }}</p>
                        <p class="text-xs text-gray-500">Your students</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-amber-700">{{ examPlanStats.without_upcoming || 0 }}</p>
                        <p class="text-xs text-gray-500">No upcoming exam plan</p>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="border-b px-6 py-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="font-medium text-gray-900">Student progress</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    Completion %, score %, revision, login days, and time spent — only students enrolled under you.
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <InputLabel value="Show" class="sr-only" />
                                <select v-model="examFilter" class="rounded-md border-gray-300 text-sm">
                                    <option value="upcoming">Next upcoming exam</option>
                                    <option value="past">Most recent past exam</option>
                                    <option value="all">Next or latest past</option>
                                </select>
                            </div>
                        </div>
                        <div
                            v-if="examPlanStats.without_upcoming > 0"
                            class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                        >
                            {{ examPlanStats.without_upcoming }} student(s) have no upcoming exam plan yet.
                        </div>
                    </div>

                    <div v-if="!examPlanRows.length" class="px-6 py-8 text-center text-sm text-gray-500">
                        No students enrolled under you in {{ gradeLevel.name }} yet.
                        They appear when they register and select your coaching institute or mentor.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Student</th>
                                    <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Plan</th>
                                    <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Exam date</th>
                                    <th class="px-3 py-3 text-center text-xs uppercase text-gray-500">Completion %</th>
                                    <th class="px-3 py-3 text-center text-xs uppercase text-gray-500">Score %</th>
                                    <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Revision</th>
                                    <th class="px-3 py-3 text-center text-xs uppercase text-gray-500">Days logged</th>
                                    <th class="px-3 py-3 text-center text-xs uppercase text-gray-500">Hours spent</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr
                                    v-for="row in examPlanRows"
                                    :key="row.enrollment_id"
                                    :class="!row.has_upcoming && examFilter === 'upcoming' ? 'bg-amber-50/60' : ''"
                                >
                                    <td class="px-3 py-3">
                                        <Link
                                            :href="studyPlanHref(row.student_id)"
                                            class="font-medium text-teal-700 hover:underline"
                                            title="Open study plan"
                                        >
                                            {{ row.student_name }}
                                        </Link>
                                    </td>
                                    <td class="px-3 py-3">
                                        <template v-if="row.display_plan">
                                            <p class="font-medium text-gray-900">{{ row.display_plan.title }}</p>
                                            <p class="text-xs text-gray-500">{{ row.display_plan.exam_type_label }}</p>
                                        </template>
                                        <span v-else class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                            No plan
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3">
                                        {{ formatDate(row.display_plan?.exam_date) }}
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <span class="font-semibold tabular-nums text-sky-800">{{ pctLabel(row.progress?.completion_pct) }}</span>
                                        <p v-if="row.progress?.sets_total" class="text-[10px] text-gray-500">
                                            {{ row.progress.sets_done }}/{{ row.progress.sets_total }} sets
                                        </p>
                                    </td>
                                    <td class="px-3 py-3 text-center font-semibold tabular-nums text-emerald-800">
                                        {{ pctLabel(displayScorePct(row.progress)) }}
                                    </td>
                                    <td
                                        class="px-3 py-3 text-xs"
                                        :class="(row.progress?.revision_pending || row.progress?.open_wrongs) ? 'font-semibold text-orange-800' : 'text-emerald-700'"
                                    >
                                        {{ revisionLabel(row.progress) }}
                                    </td>
                                    <td class="px-3 py-3 text-center font-semibold tabular-nums text-slate-800">
                                        {{ row.progress?.days_logged ?? 0 }}
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <span class="font-semibold tabular-nums text-slate-800">{{ row.progress?.time_spent_hours ?? 0 }}h</span>
                                        <p class="text-[10px] text-gray-500">{{ row.progress?.time_spent_label || '—' }}</p>
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
