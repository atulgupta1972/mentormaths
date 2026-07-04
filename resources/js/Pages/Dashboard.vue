<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ExamPlanPanel from '@/Components/ExamPlanPanel.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    isAdmin: { type: Boolean, default: false },
    assignments: { type: Array, default: () => [] },
    activeYear: Object,
    selectedGrade: Object,
    examPlans: {
        type: Object,
        default: () => ({ upcoming: [], past: [] }),
    },
    syllabusChapters: { type: Array, default: () => [] },
    examTypeOptions: { type: Array, default: () => [] },
    stats: {
        type: Object,
        default: () => ({}),
    },
    students: { type: Array, default: () => [] },
});

const showManageExams = ref(false);
const expandedStudentId = ref(null);

const pendingAssignments = computed(() =>
    props.assignments.filter((a) => a.status !== 'green' && a.status !== 'green-late'),
);

const completedAssignments = computed(() =>
    props.assignments.filter((a) => a.status === 'green' || a.status === 'green-late'),
);

const formatDate = (d) => {
    if (!d) {
        return '';
    }

    return new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const formatTime = (seconds) => {
    if (!seconds) {
        return '';
    }

    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;

    return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
};

const scorePercent = (set) => {
    if (!set.latest_max_score) {
        return null;
    }

    return Math.round((set.latest_score / set.latest_max_score) * 100);
};

const setLabel = (set) => set.set_code || `Set ${set.set_number}`;

const daysUntil = (dateStr) => {
    if (!dateStr) {
        return null;
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const target = new Date(`${dateStr}T00:00:00`);
    const diff = Math.ceil((target - today) / (1000 * 60 * 60 * 24));

    if (diff < 0) {
        return `${Math.abs(diff)}d ago`;
    }
    if (diff === 0) {
        return 'Today';
    }
    if (diff === 1) {
        return 'Tomorrow';
    }

    return `In ${diff} days`;
};

const chapterList = (plan) => plan.chapter_names?.join(' · ') || '—';

const prepProgressPercent = (plan) => {
    if (!plan.prep_summary?.total) {
        return 0;
    }

    return Math.round((plan.prep_summary.completed / plan.prep_summary.total) * 100);
};

const toggleStudent = (studentId) => {
    expandedStudentId.value = expandedStudentId.value === studentId ? null : studentId;
};

const pendingBorderClass = (set) => {
    if (set.is_overdue) {
        return 'border-rose-300 bg-gradient-to-br from-rose-50 to-white ring-1 ring-rose-200';
    }
    if (set.status === 'yellow') {
        return 'border-amber-300 bg-gradient-to-br from-amber-50 to-white ring-1 ring-amber-200';
    }

    return 'border-sky-300 bg-gradient-to-br from-sky-50 to-white ring-1 ring-sky-200 hover:border-sky-400';
};

const pendingBadgeClass = (set) => {
    if (set.is_overdue) {
        return 'bg-rose-500 text-white';
    }
    if (set.status === 'yellow') {
        return 'bg-amber-500 text-white';
    }

    return 'bg-sky-500 text-white';
};

const pendingStatusLabel = (set) => {
    if (set.is_overdue) {
        return 'Overdue';
    }
    if (set.status === 'yellow') {
        return 'In progress';
    }

    return 'To do';
};

const pendingButtonClass = (set) => {
    if (set.is_overdue) {
        return 'bg-rose-600 hover:bg-rose-700';
    }

    return 'bg-indigo-600 hover:bg-indigo-700';
};

const pendingButtonLabel = (set) => {
    if (set.status === 'yellow') {
        return 'Continue';
    }
    if (set.is_overdue) {
        return 'Complete now';
    }

    return 'Start';
};

const adminSetStatusClass = (set) => {
    if (set.status === 'green' || set.status === 'green-late') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-900';
    }
    if (set.is_overdue) {
        return 'border-rose-200 bg-rose-50 text-rose-900';
    }
    if (set.status === 'yellow') {
        return 'border-amber-200 bg-amber-50 text-amber-900';
    }

    return 'border-sky-200 bg-sky-50 text-sky-900';
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Dashboard</h2>
                <p v-if="activeYear" class="text-sm text-gray-500">
                    {{ activeYear.name }}
                    <span v-if="selectedGrade"> · {{ selectedGrade.name }}</span>
                </p>
            </div>
        </template>

        <div class="py-5">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <!-- Admin dashboard -->
                <template v-if="isAdmin">
                    <div class="rounded-2xl bg-gradient-to-r from-indigo-600 via-violet-600 to-purple-600 p-6 text-white shadow-lg">
                        <p class="text-lg font-semibold">Welcome, {{ $page.props.auth.user.name }}</p>
                        <p class="mt-1 text-sm text-indigo-100">Plan · Practice · Perform — overview for all students</p>
                        <div class="mt-4 flex flex-wrap gap-3">
                            <Link :href="route('admin.classes.index')" class="rounded-lg bg-white/15 px-4 py-2 text-sm font-medium backdrop-blur hover:bg-white/25">
                                Classes
                            </Link>
                            <Link :href="route('admin.students.index')" class="rounded-lg bg-white/15 px-4 py-2 text-sm font-medium backdrop-blur hover:bg-white/25">
                                Students
                            </Link>
                            <Link :href="route('admin.practice-sets.index')" class="rounded-lg bg-white/15 px-4 py-2 text-sm font-medium backdrop-blur hover:bg-white/25">
                                Practice sets
                            </Link>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-xl border border-violet-200 bg-violet-50 p-4 text-center shadow-sm">
                            <p class="text-3xl font-bold text-violet-700">{{ stats.students_count || 0 }}</p>
                            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-violet-600">Students</p>
                        </div>
                        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-center shadow-sm">
                            <p class="text-3xl font-bold text-sky-700">{{ stats.upcoming_exams_count || 0 }}</p>
                            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-sky-600">Upcoming exams</p>
                        </div>
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-center shadow-sm">
                            <p class="text-3xl font-bold text-amber-700">{{ stats.pending_sets_count || 0 }}</p>
                            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-amber-600">Sets to do</p>
                        </div>
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-center shadow-sm">
                            <p class="text-3xl font-bold text-emerald-700">{{ stats.completed_sets_count || 0 }}</p>
                            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-emerald-600">Sets completed</p>
                        </div>
                    </div>

                    <div v-if="students.length === 0" class="rounded-xl bg-white p-10 text-center text-gray-500 shadow-sm">
                        No active students{{ selectedGrade ? ` in ${selectedGrade.name}` : '' }} for this year.
                    </div>

                    <section v-else class="space-y-4">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">All students</h3>

                        <div
                            v-for="student in students"
                            :key="student.student_id"
                            class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
                        >
                            <button
                                type="button"
                                class="flex w-full flex-wrap items-center justify-between gap-3 px-5 py-4 text-left hover:bg-gray-50"
                                @click="toggleStudent(student.student_id)"
                            >
                                <div>
                                    <Link
                                        :href="route('admin.students.show', student.student_id)"
                                        class="font-semibold text-indigo-600 hover:underline"
                                        @click.stop
                                    >
                                        {{ student.student_name }}
                                    </Link>
                                    <p class="text-sm text-gray-500">{{ student.class_name }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full bg-sky-100 px-3 py-1 text-xs font-medium text-sky-800">
                                        {{ student.upcoming_exams.length }} upcoming exam{{ student.upcoming_exams.length === 1 ? '' : 's' }}
                                    </span>
                                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800">
                                        {{ student.assignments_pending.length }} to do
                                    </span>
                                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-800">
                                        {{ student.assignments_completed.length }} done
                                    </span>
                                </div>
                            </button>

                            <div v-if="expandedStudentId === student.student_id" class="border-t bg-slate-50 px-5 py-4 space-y-5">
                                <div>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-sky-700">Upcoming exams</h4>
                                    <div v-if="student.upcoming_exams.length" class="mt-2 grid gap-3 lg:grid-cols-2">
                                        <div
                                            v-for="exam in student.upcoming_exams"
                                            :key="exam.id"
                                            class="rounded-xl bg-gradient-to-br from-sky-500 to-indigo-600 p-4 text-white shadow"
                                        >
                                            <div class="flex items-start justify-between gap-2">
                                                <div>
                                                    <p class="font-semibold">{{ exam.title }}</p>
                                                    <p class="text-xs text-sky-100">{{ exam.exam_type_label }}</p>
                                                </div>
                                                <span class="rounded-full bg-white/20 px-2 py-0.5 text-[10px] font-semibold uppercase">
                                                    {{ daysUntil(exam.exam_date) }}
                                                </span>
                                            </div>
                                            <p class="mt-2 text-sm font-medium">{{ formatDate(exam.exam_date) }}</p>
                                            <p class="mt-1 text-xs text-sky-100">{{ chapterList(exam) }}</p>
                                            <p v-if="exam.prep_summary?.total" class="mt-2 text-xs text-sky-100">
                                                Prep: {{ exam.prep_summary.completed }}/{{ exam.prep_summary.total }} sets done
                                            </p>
                                        </div>
                                    </div>
                                    <p v-else class="mt-2 text-sm text-gray-500">No upcoming exam plan.</p>
                                </div>

                                <div v-if="student.past_exams.length">
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Completed exams</h4>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="exam in student.past_exams"
                                            :key="`past-${exam.id}`"
                                            class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700"
                                        >
                                            {{ exam.title }} · {{ formatDate(exam.exam_date) }}
                                        </span>
                                    </div>
                                </div>

                                <div>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-amber-700">Practice / tests to do</h4>
                                    <div v-if="student.assignments_pending.length" class="mt-2 flex flex-wrap gap-2">
                                        <Link
                                            v-for="set in student.assignments_pending"
                                            :key="set.assignment_id"
                                            :href="route('admin.set-assignments.show', set.assignment_id)"
                                            class="rounded-lg border px-3 py-2 text-sm font-mono font-semibold transition hover:shadow"
                                            :class="adminSetStatusClass(set)"
                                        >
                                            {{ setLabel(set) }}
                                            <span class="ml-2 font-sans text-xs font-normal">{{ pendingStatusLabel(set) }}</span>
                                        </Link>
                                    </div>
                                    <p v-else class="mt-2 text-sm text-gray-500">All caught up on assigned sets.</p>
                                </div>

                                <div v-if="student.assignments_completed.length">
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Completed sets</h4>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <Link
                                            v-for="set in student.assignments_completed"
                                            :key="`done-${set.assignment_id}`"
                                            :href="route('admin.set-assignments.show', set.assignment_id)"
                                            class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-mono font-semibold text-emerald-900 hover:bg-emerald-100"
                                        >
                                            {{ setLabel(set) }}
                                            <span class="ml-2 font-sans text-xs">{{ set.latest_score }}/{{ set.latest_max_score }}</span>
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </template>

                <!-- Student dashboard -->
                <template v-else>
                    <!-- Welcome — single compact row -->
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gradient-to-r from-emerald-600 via-teal-600 to-cyan-600 px-4 py-3 text-white shadow">
                        <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                            <p class="text-base font-semibold whitespace-nowrap">Welcome, {{ $page.props.auth.user.name }}</p>
                            <span class="hidden text-emerald-100/70 sm:inline">·</span>
                            <p class="hidden text-xs text-emerald-100 sm:inline">Plan your exams · Practice your sets · Perform on test day</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="rounded-full bg-white/20 px-2.5 py-0.5">{{ stats.upcoming_exams || 0 }} exams</span>
                            <span class="rounded-full bg-amber-300/40 px-2.5 py-0.5">{{ stats.sets_todo || 0 }} to do</span>
                            <span class="rounded-full bg-violet-300/40 px-2.5 py-0.5">{{ stats.sets_done || 0 }} done</span>
                        </div>
                    </div>

                    <!-- Main row: exams (LHS) · to do (RHS) -->
                    <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
                        <!-- Upcoming exams — violet zone -->
                        <section class="rounded-xl border border-violet-200 bg-gradient-to-br from-violet-50 via-purple-50 to-fuchsia-50 p-4 shadow-sm">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-violet-900">
                                    Upcoming exams · {{ examPlans.upcoming?.length || 0 }}
                                </h3>
                                <button
                                    type="button"
                                    class="rounded-lg border-2 border-violet-700 bg-white px-3 py-1.5 text-sm font-bold tracking-wide text-violet-800 shadow-sm transition hover:bg-violet-700 hover:text-white"
                                    @click="showManageExams = !showManageExams"
                                >
                                    {{ showManageExams ? 'Hide planner' : 'Add / edit exams' }}
                                </button>
                            </div>

                            <div v-if="examPlans.upcoming?.length" class="space-y-3">
                                <div
                                    v-for="plan in examPlans.upcoming"
                                    :key="plan.id"
                                    class="overflow-hidden rounded-xl bg-gradient-to-br from-violet-600 via-purple-600 to-fuchsia-600 p-3.5 text-white shadow"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold">{{ plan.title }}</p>
                                            <p class="text-[10px] text-violet-100">{{ plan.exam_type_label }}</p>
                                        </div>
                                        <span class="shrink-0 rounded-full bg-white/20 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide">
                                            {{ daysUntil(plan.exam_date) }}
                                        </span>
                                    </div>
                                    <p class="mt-2 text-lg font-semibold">{{ formatDate(plan.exam_date) }}</p>
                                    <p class="mt-1 truncate text-xs text-violet-100" :title="chapterList(plan)">{{ chapterList(plan) }}</p>
                                    <div v-if="plan.prep_summary?.total" class="mt-2.5">
                                        <div class="flex justify-between text-[10px] text-violet-100">
                                            <span>Prep assigned</span>
                                            <span>{{ plan.prep_summary.completed }}/{{ plan.prep_summary.total }} done</span>
                                        </div>
                                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-white/20">
                                            <div
                                                class="h-full rounded-full bg-emerald-300 transition-all"
                                                :style="{ width: `${prepProgressPercent(plan)}%` }"
                                            />
                                        </div>
                                    </div>
                                    <ul v-if="plan.prep_assignments?.length" class="mt-2 space-y-0.5">
                                        <li
                                            v-for="prep in plan.prep_assignments"
                                            :key="prep.assignment_id"
                                            class="flex items-center justify-between rounded-md bg-white/10 px-2 py-0.5 text-[10px]"
                                        >
                                            <span class="font-mono font-semibold">{{ prep.set_code }}</span>
                                            <span>{{ prep.progress_label }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div v-else class="rounded-lg border border-dashed border-violet-300 bg-white/70 p-4 text-center text-xs text-violet-900">
                                No upcoming exams yet. Click <strong>Add / edit exams</strong> to add your test date.
                            </div>
                        </section>

                        <!-- Practice sets — amber/orange zone -->
                        <section class="rounded-xl border border-amber-200 bg-gradient-to-br from-amber-50 via-orange-50 to-rose-50 p-4 shadow-sm">
                            <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-amber-900">
                                Practice & tests · To do · {{ pendingAssignments.length }}
                            </h3>

                            <div v-if="pendingAssignments.length" class="space-y-2">
                                <div
                                    v-for="set in pendingAssignments"
                                    :key="set.assignment_id"
                                    class="flex items-center gap-3 rounded-xl border p-3 shadow-sm transition"
                                    :class="pendingBorderClass(set)"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide" :class="pendingBadgeClass(set)">
                                                {{ pendingStatusLabel(set) }}
                                            </span>
                                            <span class="text-[9px] font-semibold uppercase text-gray-500">
                                                {{ set.kind_label || (set.scope === 'chapter' ? 'Test' : 'Practice') }}
                                            </span>
                                        </div>
                                        <p class="mt-1 font-mono text-2xl font-bold tracking-wide text-gray-900">{{ setLabel(set) }}</p>
                                        <p v-if="set.target_date" class="text-[10px] font-medium" :class="set.is_overdue ? 'text-rose-600' : 'text-gray-600'">
                                            Due {{ formatDate(set.target_date) }}
                                        </p>
                                    </div>
                                    <Link
                                        :href="route('student.assignments.show', set.assignment_id)"
                                        class="shrink-0 rounded-lg px-3 py-2 text-xs font-semibold text-white shadow"
                                        :class="pendingButtonClass(set)"
                                    >
                                        {{ pendingButtonLabel(set) }}
                                    </Link>
                                </div>
                            </div>

                            <div v-else-if="completedAssignments.length" class="rounded-lg border border-dashed border-amber-300 bg-white/70 p-4 text-center text-xs text-amber-900">
                                All caught up — no pending sets right now.
                            </div>

                            <div v-else class="rounded-lg border border-dashed border-amber-300 bg-white/70 p-4 text-center text-xs text-amber-900">
                                No sets assigned yet. Your teacher will assign practice when you're ready.
                            </div>
                        </section>
                    </div>

                    <!-- Completed exams -->
                    <section v-if="examPlans.past?.length">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Completed exams · {{ examPlans.past.length }}
                        </h3>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <div
                                v-for="plan in examPlans.past"
                                :key="plan.id"
                                class="rounded-lg border border-gray-200 bg-gradient-to-br from-gray-50 to-slate-100 p-3"
                            >
                                <p class="text-sm font-semibold text-gray-900">{{ plan.title }}</p>
                                <p class="mt-0.5 text-xs text-gray-600">{{ formatDate(plan.exam_date) }}</p>
                                <p class="mt-1 truncate text-[10px] text-gray-500">{{ chapterList(plan) }}</p>
                            </div>
                        </div>
                    </section>

                    <section v-if="showManageExams" class="rounded-xl bg-white p-4 shadow-sm">
                        <ExamPlanPanel
                            :plans="[...(examPlans.upcoming || []), ...(examPlans.past || [])]"
                            :syllabus-chapters="syllabusChapters"
                            :exam-type-options="examTypeOptions"
                            context="student"
                        />
                    </section>

                    <!-- Completed sets — green zone -->
                    <section v-if="completedAssignments.length" class="rounded-xl border border-emerald-200 bg-gradient-to-br from-emerald-50 via-green-50 to-teal-50 p-4 shadow-sm">
                        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-emerald-800">
                            Completed · {{ completedAssignments.length }}
                        </h3>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <Link
                                v-for="set in completedAssignments"
                                :key="`done-${set.assignment_id}`"
                                :href="route('student.assignments.show', set.assignment_id)"
                                class="flex items-center gap-3 rounded-xl border-2 border-emerald-300 bg-gradient-to-br from-emerald-50 to-green-100 p-3 shadow-sm transition hover:border-emerald-500 hover:shadow-md"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="rounded-full bg-emerald-500 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-white">
                                            Done
                                        </span>
                                        <span class="text-[9px] font-semibold uppercase text-emerald-700">
                                            {{ set.kind_label || (set.scope === 'chapter' ? 'Test' : 'Practice') }}
                                        </span>
                                    </div>
                                    <p class="mt-1 font-mono text-xl font-bold tracking-wide text-emerald-900">{{ setLabel(set) }}</p>
                                    <p class="text-sm font-bold text-emerald-800">
                                        {{ set.latest_score }}/{{ set.latest_max_score }}
                                        <span v-if="scorePercent(set) !== null" class="text-xs"> ({{ scorePercent(set) }}%) </span>
                                    </p>
                                </div>
                                <span class="shrink-0 text-xs font-semibold text-emerald-700">View →</span>
                            </Link>
                        </div>
                    </section>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
