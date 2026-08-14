<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ExamPlanPanel from '@/Components/ExamPlanPanel.vue';
import StudentAssignmentGroupTable from '@/Components/StudentAssignmentGroupTable.vue';
import PendingWorkEmailPanel from '@/Components/PendingWorkEmailPanel.vue';
import ClassCoveragePanel from '@/Components/ClassCoveragePanel.vue';
import ContentUploadGuidePanel from '@/Components/ContentUploadGuidePanel.vue';
import ContentUploaderTasksPanel from '@/Components/ContentUploaderTasksPanel.vue';
import StudentWeeklyReportEmailsPanel from '@/Components/StudentWeeklyReportEmailsPanel.vue';
import { formatScoreLabel } from '@/utils/scores';
import { formatDate, formatDateTime, formatTime as formatDuration } from '@/utils/dates';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';

const page = usePage();

const isContentUploader = computed(() => page.props.auth?.isContentUploader ?? false);

const props = defineProps({
    isAdmin: { type: Boolean, default: false },
    classCoverage: {
        type: Object,
        default: () => ({ chapters: [], under_study_chapter_id: null, availability_columns: [] }),
    },
    studyPlanContext: {
        type: Object,
        default: () => ({ grade_name: null, board_name: null }),
    },
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
    helpRequests: { type: Array, default: () => [] },
    contentPublishQueue: { type: Array, default: () => [] },
    resolutionItems: { type: Array, default: () => [] },
    resolutionCount: { type: Number, default: 0 },
    weeklyReportEmails: { type: String, default: '' },
    contentUploaderTasks: { type: Object, default: null },
    mailSettings: { type: Object, default: null },
    gradeLevels: { type: Array, default: () => [] },
});

const showManageExams = ref(false);
const showHelpRequests = ref(false);
const expandedStudentId = ref(null);
const highlightedExamPlanId = ref(null);

const studyPlanSubtitle = computed(() => {
    const parts = [props.studyPlanContext?.grade_name, props.studyPlanContext?.board_name].filter(Boolean);

    return parts.length ? parts.join(' · ') : 'Your class syllabus';
});

const underStudyChapter = computed(() => {
    const id = props.classCoverage?.under_study_chapter_id;
    if (!id) return null;
    return (props.classCoverage.chapters || []).find((c) => c.id === id) || null;
});

const studiedChapterRows = computed(() => (props.classCoverage?.chapters || []).filter((c) => c.studied));

const underStudyChapterRows = computed(() => (props.classCoverage?.chapters || []).filter((c) => c.under_study));

const allExamPlans = computed(() => [
    ...(props.examPlans.upcoming || []),
    ...(props.examPlans.past || []),
]);

const openExamMarks = async (planId) => {
    showManageExams.value = true;
    highlightedExamPlanId.value = planId;

    await nextTick();

    document.getElementById(`exam-plan-${planId}`)?.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
    });
};

onMounted(() => {
    if (allExamPlans.value.some((plan) => !plan.has_marks)) {
        showManageExams.value = true;
    }
});

const sortByDateKey = (rows, key) => rows.slice().sort((a, b) => {
    const left = a[key] ?? '9999-12-31';
    const right = b[key] ?? '9999-12-31';

    return String(left).localeCompare(String(right));
});

const checkingAssignments = computed(() =>
    sortByDateKey(
        props.assignments.filter((a) => a.status === 'checking'),
        'submitted_at',
    ),
);

const pendingAssignments = computed(() =>
    sortByDateKey(
        props.assignments.filter((a) =>
            a.status !== 'green'
            && a.status !== 'green-late'
            && a.status !== 'checking'
            && !a.is_catch_up),
        'target_date',
    ),
);

const pendingCatchUpAssignments = computed(() =>
    sortByDateKey(
        props.assignments.filter((a) =>
            a.status !== 'green'
            && a.status !== 'green-late'
            && a.is_catch_up),
        'target_date',
    ),
);

const completedAssignments = computed(() =>
    sortByDateKey(
        props.assignments.filter((a) => a.status === 'green' || a.status === 'green-late'),
        'submitted_at',
    ),
);

const chapterOrder = computed(() => props.syllabusChapters.map((chapter) => chapter.name));

const sortChapterGroups = (groups) => groups.sort((left, right) => {
    const leftIndex = chapterOrder.value.indexOf(left.chapter_name);
    const rightIndex = chapterOrder.value.indexOf(right.chapter_name);

    if (leftIndex === -1 && rightIndex === -1) {
        return left.chapter_name.localeCompare(right.chapter_name);
    }

    if (leftIndex === -1) {
        return 1;
    }

    if (rightIndex === -1) {
        return -1;
    }

    return leftIndex - rightIndex;
});

const groupAssignmentsByChapter = (rows) => {
    const grouped = rows.reduce((acc, set) => {
        const chapterName = set.chapter_name || 'Other';

        if (!acc[chapterName]) {
            acc[chapterName] = [];
        }

        acc[chapterName].push(set);

        return acc;
    }, {});

    return sortChapterGroups(
        Object.entries(grouped).map(([chapter_name, sets]) => ({
            chapter_name,
            sets: sortByDateKey(sets, sets[0]?.submitted_at ? 'submitted_at' : 'target_date'),
        })),
    );
};

const pendingByChapter = computed(() => groupAssignmentsByChapter(pendingAssignments.value));
const pendingCatchUpByChapter = computed(() => groupAssignmentsByChapter(pendingCatchUpAssignments.value));
const checkingByChapter = computed(() => groupAssignmentsByChapter(checkingAssignments.value));
const completedByChapter = computed(() => groupAssignmentsByChapter(completedAssignments.value));

const topicLabel = (set) => {
    if (set.topic_name) {
        return set.topic_name;
    }

    if (set.scope === 'chapter') {
        return set.kind_label?.includes('Test') ? 'Chapter test' : 'Chapter practice';
    }

    return '—';
};

const formatTime = (seconds) => formatDuration(seconds) === '—' ? '' : formatDuration(seconds);

const completedAssignmentHref = (set) => {
    if (set.delivery_mode === 'written') {
        return route('student.written-assignments.show', set.assignment_id);
    }

    return set.latest_attempt_id
        ? route('student.attempts.result', set.latest_attempt_id)
        : route('student.assignments.show', set.assignment_id);
};

const completedLinkLabel = (set) => {
    if (set.delivery_mode === 'written' && set.written_submission_status === 'graded') {
        return 'View / re-upload';
    }

    return 'Open';
};

const assignmentHref = (set) => (
    set.delivery_mode === 'written'
        ? route('student.written-assignments.show', set.assignment_id)
        : route('student.assignments.show', set.assignment_id)
);

const setLabel = (set) => set.set_code || `Set ${set.set_number}`;

const daysUntilExam = (dateStr) => {
    if (!dateStr) {
        return null;
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const target = new Date(`${dateStr}T00:00:00`);

    return Math.ceil((target - today) / (1000 * 60 * 60 * 24));
};

const daysUntil = (dateStr) => {
    const diff = daysUntilExam(dateStr);

    if (diff === null) {
        return null;
    }
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

const upcomingExamCardClass = (plan) => {
    const days = daysUntilExam(plan.exam_date);

    if (days === null || days < 0) {
        return 'border-2 border-slate-800 bg-gradient-to-br from-violet-600 via-purple-600 to-fuchsia-600';
    }
    if (days <= 7) {
        return 'border-[3px] border-rose-950 bg-gradient-to-br from-rose-500 via-orange-500 to-amber-500 shadow-lg shadow-rose-950/25';
    }

    return 'border-[3px] border-amber-950 bg-gradient-to-br from-amber-400 via-orange-500 to-rose-500 shadow-md shadow-amber-950/20';
};

const chapterList = (plan) => plan.chapter_names?.join(' · ') || '—';

const prepProgressPercent = (plan) => {
    if (!plan.prep_summary?.total) {
        return 0;
    }

    return Math.round((plan.prep_summary.completed / plan.prep_summary.total) * 100);
};

const prepAssignmentsByChapter = (plan) => {
    const assignments = plan.prep_assignments || [];

    if (!assignments.length) {
        return [];
    }

    const grouped = assignments.reduce((acc, prep) => {
        const key = prep.chapter_label || 'Other';

        if (!acc[key]) {
            acc[key] = [];
        }

        acc[key].push(prep);

        return acc;
    }, {});

    const planChapterOrder = (plan.chapters || []).map(
        (chapter) => chapter.label || `Ch ${chapter.chapter_number} — ${chapter.name}`,
    );

    return Object.entries(grouped)
        .map(([chapter_label, sets]) => ({
            chapter_label,
            sets: sets.slice().sort((left, right) => (left.set_number || 0) - (right.set_number || 0)),
            pending_count: sets.filter((set) => set.assignment_status !== 'completed').length,
        }))
        .sort((left, right) => {
            const leftIndex = planChapterOrder.indexOf(left.chapter_label);
            const rightIndex = planChapterOrder.indexOf(right.chapter_label);

            if (leftIndex === -1 && rightIndex === -1) {
                return left.chapter_label.localeCompare(right.chapter_label);
            }

            if (leftIndex === -1) {
                return 1;
            }

            if (rightIndex === -1) {
                return -1;
            }

            return leftIndex - rightIndex;
        });
};

const prepStatusClass = (prep) => {
    if (prep.assignment_status === 'completed') {
        return prep.submission_timing === 'late'
            ? 'bg-amber-100 text-amber-900'
            : 'bg-emerald-100 text-emerald-800';
    }

    if (prep.is_overdue) {
        return 'bg-rose-100 text-rose-800';
    }

    if (prep.assignment_status === 'in_progress') {
        return 'bg-amber-100 text-amber-900';
    }

    return 'bg-sky-100 text-sky-800';
};

const prepAssignmentHref = (prep) => (
    prep.delivery_mode === 'written'
        ? route('student.written-assignments.show', prep.assignment_id)
        : route('student.assignments.show', prep.assignment_id)
);

const toggleStudent = (studentId) => {
    expandedStudentId.value = expandedStudentId.value === studentId ? null : studentId;
};

const toggleHelpRequests = () => {
    if (!props.helpRequests.length) {
        return;
    }

    showHelpRequests.value = !showHelpRequests.value;
};

const studentsByClass = computed(() => {
    const groups = {};

    for (const student of props.students) {
        const key = student.class_name || 'Other';
        if (!groups[key]) {
            groups[key] = [];
        }
        groups[key].push(student);
    }

    return Object.entries(groups).sort(([a], [b]) => a.localeCompare(b, undefined, { numeric: true }));
});

const studentSummary = (student) => {
    const parts = [
        `${student.upcoming_exams.length} exam${student.upcoming_exams.length === 1 ? '' : 's'}`,
        `${student.assignments_pending.length} todo`,
        `${student.assignments_completed.length} done`,
    ];

    if (student.help_requests_count > 0) {
        parts.push(`${student.help_requests_count} need help`);
    }

    return parts.join(' · ');
};

const formatHelpDate = (value) => {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
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
    if (set.delivery_mode === 'written') {
        if (set.written_submission_status === 'processing') {
            return 'Checking…';
        }

        if (set.written_submission_status === 'uploaded') {
            return 'Uploaded';
        }

        if (set.written_submission_status === 'failed') {
            return 'View / upload';
        }

        return set.written_submission_status === 'graded' ? 'View / re-upload' : 'Upload work';
    }

    if (set.status === 'yellow') {
        return 'Continue';
    }
    if (set.is_overdue) {
        return 'Complete now';
    }

    return 'Start';
};

const adminAssignmentHref = (set, studentId) => {
    if (set.delivery_mode === 'written' && set.practice_set_id) {
        return route('admin.written-sheets.show', {
            worksheet: set.practice_set_id,
            student_id: studentId,
            assignment_id: set.assignment_id,
        });
    }

    return route('admin.set-assignments.show', set.assignment_id);
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
    <Head :title="isAdmin ? 'Dashboard' : 'My Study Plan'" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ isAdmin ? 'Dashboard' : 'My Study Plan' }}
                </h2>
                <p v-if="isAdmin && activeYear" class="text-sm text-gray-500">
                    {{ activeYear.name }}
                    <span v-if="selectedGrade"> · {{ selectedGrade.name }}</span>
                </p>
                <p v-else-if="!isAdmin" class="text-sm text-gray-500">
                    {{ studyPlanSubtitle }}
                    <span v-if="activeYear"> · {{ activeYear.name }}</span>
                </p>
            </div>
        </template>

        <div class="py-5">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <!-- Admin dashboard -->
                <template v-if="isAdmin">
                    <div class="rounded-xl bg-gradient-to-r from-indigo-600 via-violet-600 to-purple-600 px-4 py-3 text-white shadow">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold">Welcome, {{ $page.props.auth.user.name }}</p>
                                <p class="text-[11px] text-indigo-100">Plan · Practice · Perform</p>
                            </div>
                            <div class="flex flex-wrap gap-1.5 text-[11px]">
                                <Link :href="route('admin.classes.index')" class="rounded-md bg-white/15 px-2.5 py-1 font-medium hover:bg-white/25">
                                    Classes
                                </Link>
                                <Link :href="route('admin.students.index')" class="rounded-md bg-white/15 px-2.5 py-1 font-medium hover:bg-white/25">
                                    Students
                                </Link>
                                <Link :href="route('admin.practice-sets.index')" class="rounded-md bg-white/15 px-2.5 py-1 font-medium hover:bg-white/25">
                                    Sets
                                </Link>
                            </div>
                        </div>
                    </div>

                    <PendingWorkEmailPanel
                        v-if="mailSettings"
                        compact
                        :mail-settings="mailSettings"
                        :active-year="activeYear"
                        :selected-grade="selectedGrade"
                        :grade-levels="gradeLevels"
                    />

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                        <div class="rounded-lg border border-violet-200 bg-violet-50 px-2 py-2.5 text-center shadow-sm">
                            <p class="text-2xl font-extrabold leading-none text-violet-700">{{ stats.students_count || 0 }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-violet-700">Students</p>
                        </div>
                        <div class="rounded-lg border border-sky-200 bg-sky-50 px-2 py-2.5 text-center shadow-sm">
                            <p class="text-2xl font-extrabold leading-none text-sky-700">{{ stats.upcoming_exams_count || 0 }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-sky-700">Exams</p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg border px-2 py-2.5 text-center shadow-sm transition"
                            :class="showHelpRequests
                                ? 'border-rose-500 bg-rose-100 ring-2 ring-rose-400'
                                : 'border-rose-200 bg-rose-50 hover:border-rose-400'"
                            :disabled="!helpRequests.length"
                            :title="helpRequests.length ? 'Click to show or hide help requests' : 'No help requests'"
                            @click="toggleHelpRequests"
                        >
                            <p class="text-2xl font-extrabold leading-none text-rose-700">{{ stats.help_requests_count || 0 }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-rose-700">
                                Need help
                                <span v-if="helpRequests.length" class="ml-0.5">{{ showHelpRequests ? '▲' : '▼' }}</span>
                            </p>
                        </button>
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-2 py-2.5 text-center shadow-sm">
                            <p class="text-2xl font-extrabold leading-none text-amber-700">{{ stats.pending_sets_count || 0 }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-amber-700">To do</p>
                        </div>
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-2.5 text-center shadow-sm">
                            <p class="text-2xl font-extrabold leading-none text-emerald-700">{{ stats.completed_sets_count || 0 }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-emerald-700">Done</p>
                        </div>
                    </div>

                    <section
                        v-if="contentPublishQueue.length"
                        class="rounded-xl border border-amber-200 bg-gradient-to-br from-amber-50 to-orange-50 p-4 shadow-sm"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-amber-950">
                                Content ready to publish · {{ contentPublishQueue.length }}
                            </p>
                            <Link :href="route('admin.content-tasks.index')" class="text-xs font-medium text-indigo-600 hover:underline">
                                All content tasks →
                            </Link>
                        </div>
                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="item in contentPublishQueue"
                                :key="item.id"
                                class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white/80 px-3 py-2 text-sm"
                            >
                                <span>
                                    {{ item.grade_name }} · Ch {{ item.chapter_number }} — {{ item.chapter_title }}
                                    <span class="text-gray-500">· {{ item.assignee_name }}</span>
                                </span>
                                <Link :href="route('admin.content-tasks.show', item.id)" class="text-indigo-600 hover:underline">
                                    Review →
                                </Link>
                            </li>
                        </ul>
                    </section>

                    <section
                        v-if="showHelpRequests && helpRequests.length"
                        class="rounded-xl border border-rose-200 bg-gradient-to-br from-rose-50 via-orange-50 to-amber-50 p-4 shadow-sm"
                    >
                        <h3 class="text-sm font-bold uppercase tracking-wide text-rose-900">
                            Students asked for help · {{ helpRequests.length }}
                        </h3>
                        <p class="mt-1 text-xs text-rose-800">
                            These sums were given up during guided practice. Explain in class, then the student retries from their dashboard.
                        </p>
                        <div class="mt-3 space-y-2">
                            <div
                                v-for="item in helpRequests"
                                :key="item.id"
                                class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-rose-200 bg-white p-3 shadow-sm"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Link
                                            :href="route('admin.students.show', item.student_id)"
                                            class="text-sm font-bold text-indigo-700 hover:underline"
                                        >
                                            {{ item.student_name }}
                                        </Link>
                                        <span v-if="item.class_name" class="text-xs text-gray-500">{{ item.class_name }}</span>
                                        <span v-if="item.set_code" class="font-mono text-xs font-semibold text-indigo-600">{{ item.set_code }}</span>
                                    </div>
                                    <p class="mt-1 line-clamp-2 text-sm text-gray-800">{{ item.question_text }}</p>
                                </div>
                                <p class="shrink-0 text-xs text-gray-500">{{ formatHelpDate(item.gave_up_at) }}</p>
                            </div>
                        </div>
                    </section>

                    <div v-if="students.length === 0" class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm">
                        No active students{{ selectedGrade ? ` in ${selectedGrade.name}` : '' }} for this year.
                    </div>

                    <section v-else class="space-y-3">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-gray-800">All students · by class</h3>

                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <div
                                v-for="[className, classStudents] in studentsByClass"
                                :key="className"
                                class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm"
                            >
                                <p class="mb-2 border-b border-gray-100 pb-1.5 text-xs font-extrabold uppercase tracking-wide text-indigo-800">
                                    {{ className }}
                                    <span class="font-bold normal-case text-gray-600">({{ classStudents.length }})</span>
                                </p>

                                <div class="space-y-2">
                                    <div
                                        v-for="student in classStudents"
                                        :key="student.student_id"
                                        class="overflow-hidden rounded-lg border border-gray-200 bg-slate-50"
                                    >
                                        <button
                                            type="button"
                                            class="flex w-full items-start justify-between gap-2 px-3 py-2.5 text-left hover:bg-slate-100"
                                            @click="toggleStudent(student.student_id)"
                                        >
                                            <div class="min-w-0">
                                                <Link
                                                    :href="route('admin.students.show', student.student_id)"
                                                    class="block truncate text-base font-bold leading-tight text-indigo-700 hover:underline"
                                                    @click.stop
                                                >
                                                    {{ student.student_name }}
                                                </Link>
                                                <p class="mt-1 text-xs font-semibold leading-snug text-gray-700">
                                                    {{ studentSummary(student) }}
                                                </p>
                                                <p
                                                    v-if="student.help_requests_count > 0"
                                                    class="mt-1 text-xs font-bold text-rose-700"
                                                >
                                                    {{ student.help_requests_count }} sum{{ student.help_requests_count === 1 ? '' : 's' }} need teacher help
                                                </p>
                                            </div>
                                            <span class="shrink-0 pt-1 text-xs font-bold text-gray-500">
                                                {{ expandedStudentId === student.student_id ? '▲' : '▼' }}
                                            </span>
                                        </button>

                                        <div v-if="expandedStudentId === student.student_id" class="space-y-3 border-t border-gray-100 bg-white px-2.5 py-2.5">
                                <ExamPlanPanel
                                    :plans="student.exam_plans || []"
                                    :syllabus-chapters="student.syllabus_chapters || []"
                                    :exam-type-options="examTypeOptions"
                                    :student-id="student.student_id"
                                    context="admin"
                                    compact
                                />

                                <div v-if="student.help_requests?.length">
                                    <h4 class="text-[10px] font-semibold uppercase tracking-wide text-rose-700">Asked for help</h4>
                                    <ul class="mt-1 space-y-1">
                                        <li
                                            v-for="item in student.help_requests"
                                            :key="item.id"
                                            class="rounded border border-rose-200 bg-rose-50 px-2 py-1.5 text-[11px] text-gray-800"
                                        >
                                            <span v-if="item.set_code" class="font-mono font-semibold text-indigo-700">{{ item.set_code }}</span>
                                            <span class="block line-clamp-2">{{ item.question_text }}</span>
                                        </li>
                                    </ul>
                                </div>

                                <div>
                                    <h4 class="text-[10px] font-semibold uppercase tracking-wide text-amber-700">Sets to do</h4>
                                    <div v-if="student.assignments_pending.length" class="mt-1 flex flex-wrap gap-1">
                                        <Link
                                            v-for="set in student.assignments_pending"
                                            :key="set.assignment_id"
                                            :href="adminAssignmentHref(set, student.student_id)"
                                            class="rounded border px-2 py-1 text-[11px] font-mono font-semibold"
                                            :class="adminSetStatusClass(set)"
                                        >
                                            {{ setLabel(set) }}
                                        </Link>
                                    </div>
                                    <p v-else class="mt-1 text-[11px] text-gray-500">All caught up.</p>
                                </div>

                                <div v-if="student.assignments_completed.length">
                                    <h4 class="text-[10px] font-semibold uppercase tracking-wide text-emerald-700">Sets done</h4>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <Link
                                            v-for="set in student.assignments_completed"
                                            :key="`done-${set.assignment_id}`"
                                            :href="adminAssignmentHref(set, student.student_id)"
                                            class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] font-mono font-semibold text-emerald-900"
                                        >
                                            {{ setLabel(set) }}
                                            <span class="font-sans">{{ set.latest_score_label || formatScoreLabel(set.latest_score, set.latest_max_score) }}</span>
                                        </Link>
                                    </div>
                                </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </template>

                <!-- Student / teacher / uploader dashboard -->
                <template v-else>
                    <ContentUploadGuidePanel v-if="isContentUploader" variant="uploader" />

                    <ContentUploaderTasksPanel
                        v-if="contentUploaderTasks?.summary?.total_active"
                        class="mb-4"
                        compact
                        :summary="contentUploaderTasks.summary"
                        :upload-pending="contentUploaderTasks.uploadPending"
                        :review-pending="contentUploaderTasks.reviewPending"
                    />

                    <!-- School study plan — primary student landing -->
                    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div v-if="underStudyChapter" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-950">Please complete</p>
                            <p class="mt-1 text-sm text-amber-900">
                                Continue your current chapter:
                                <span class="font-semibold">{{ underStudyChapter.label }} — {{ underStudyChapter.name }}</span>
                            </p>
                        </div>

                        <div
                            v-else-if="classCoverage.chapters?.length && studiedChapterRows.length === 0"
                            class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3"
                        >
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-900">Please start</p>
                            <p class="mt-1 text-sm text-slate-700">
                                Mark one chapter as <span class="font-semibold">Under study</span> below so we know what you are learning in school.
                            </p>
                        </div>

                        <ClassCoveragePanel
                            :class-coverage="classCoverage"
                            :upcoming-exams="examPlans.upcoming || []"
                        />

                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-semibold text-emerald-800">
                                {{ studiedChapterRows.length }} studied
                            </span>
                            <span
                                v-if="underStudyChapterRows.length"
                                class="rounded-full bg-amber-50 px-3 py-1 text-[11px] font-semibold text-amber-800"
                            >
                                {{ underStudyChapterRows.length }} under study
                            </span>
                        </div>
                    </section>

                    <div class="border-t border-slate-200 pt-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Practice, exams & assignments
                        </h3>
                    </div>

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
                            <Link
                                :href="route('student.resolutions.history')"
                                class="rounded-full bg-white/20 px-2.5 py-0.5 underline decoration-white/50 underline-offset-2 hover:bg-white/30"
                            >
                                Help history
                            </Link>
                        </div>
                    </div>

                    <StudentWeeklyReportEmailsPanel :initial-emails="weeklyReportEmails" />

                    <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                        {{ page.props.flash.success }}
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
                                    class="overflow-hidden rounded-xl p-3.5 text-white shadow"
                                    :class="upcomingExamCardClass(plan)"
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
                                    <div v-if="plan.chapter_names?.length" class="mt-2 rounded-lg bg-white/10 p-2">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-violet-100">Chapters / topics</p>
                                        <ul class="mt-1 space-y-0.5 text-xs leading-snug text-white">
                                            <li v-for="(name, index) in plan.chapter_names" :key="index">
                                                {{ name }}
                                            </li>
                                        </ul>
                                    </div>
                                    <p v-else class="mt-1 text-xs text-violet-100">No chapters selected yet.</p>
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
                                    <div v-if="prepAssignmentsByChapter(plan).length" class="mt-3 space-y-2">
                                        <StudentAssignmentGroupTable
                                            :groups="prepAssignmentsByChapter(plan)"
                                            variant="prep"
                                            chapter-field="chapter_label"
                                            count-suffix="prep sets"
                                        />
                                    </div>
                                    <p
                                        v-if="plan.has_marks"
                                        class="mt-2 text-sm font-semibold text-emerald-200"
                                    >
                                        Result: {{ plan.marks_score_label }}
                                    </p>
                                    <button
                                        v-else
                                        type="button"
                                        class="mt-2 rounded-md bg-white/20 px-2.5 py-1 text-xs font-semibold hover:bg-white/30"
                                        @click="openExamMarks(plan.id)"
                                    >
                                        Enter school test marks
                                    </button>
                                </div>
                            </div>

                            <div v-else class="rounded-lg border border-dashed border-violet-300 bg-white/70 p-4 text-center text-xs text-violet-900">
                                No upcoming exams yet. Click <strong>Add / edit exams</strong> to add your test date.
                            </div>

                            <div v-if="showManageExams" class="mt-4 rounded-lg border border-violet-200 bg-white p-4 shadow-sm">
                                <ExamPlanPanel
                                    :plans="allExamPlans"
                                    :syllabus-chapters="syllabusChapters"
                                    :exam-type-options="examTypeOptions"
                                    :highlight-plan-id="highlightedExamPlanId"
                                    :auto-open-create="!allExamPlans.length"
                                    context="student"
                                    compact
                                />
                            </div>
                        </section>

                        <!-- Help queue — rose zone -->
                        <section
                            v-if="resolutionItems.length"
                            class="rounded-xl border border-rose-200 bg-gradient-to-br from-rose-50 via-orange-50 to-amber-50 p-4 shadow-sm"
                        >
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-rose-900">
                                    Asked for help · {{ resolutionCount }}
                                </h3>
                                <div class="flex flex-wrap items-center gap-2">
                                    <Link
                                        :href="route('student.resolutions.history')"
                                        class="text-xs font-semibold text-rose-700 underline decoration-rose-300 underline-offset-2 hover:text-rose-900"
                                    >
                                        History
                                    </Link>
                                    <Link
                                        :href="route('student.resolutions.clear-all')"
                                        class="rounded-lg border border-rose-300 bg-white px-2.5 py-1 text-xs font-semibold text-rose-800 hover:bg-rose-50"
                                    >
                                        Clear all
                                    </Link>
                                </div>
                            </div>
                            <p class="mb-3 text-xs text-rose-800">
                                After your teacher explains these, answer each one correctly to clear it. Use <strong>Clear all</strong> to work through them one by one.
                            </p>
                            <div class="space-y-2">
                                <div
                                    v-for="item in resolutionItems"
                                    :key="item.id"
                                    class="flex items-start gap-3 rounded-xl border border-rose-200 bg-white p-3 shadow-sm"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p v-if="item.set_code" class="font-mono text-sm font-semibold text-indigo-600">{{ item.set_code }}</p>
                                        <p class="mt-1 line-clamp-2 text-sm text-gray-800">{{ item.question_text }}</p>
                                        <p v-if="item.gave_up_at" class="mt-1 text-[10px] text-gray-500">
                                            Asked {{ formatDateTime(item.gave_up_at) }}
                                        </p>
                                    </div>
                                    <Link
                                        :href="route('student.resolutions.show', item.id)"
                                        class="shrink-0 rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700"
                                    >
                                        Answer
                                    </Link>
                                </div>
                            </div>
                        </section>

                        <!-- Catch-up sets -->
                        <section
                            v-if="pendingCatchUpAssignments.length"
                            class="rounded-xl border border-sky-200 bg-gradient-to-br from-sky-50 via-cyan-50 to-teal-50 p-4 shadow-sm"
                        >
                            <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-sky-900">
                                Catch-up Sets · {{ pendingCatchUpAssignments.length }}
                            </h3>
                            <p class="mb-3 text-xs text-sky-800">
                                Extra practice on sums you needed help with — new numbers, same skills.
                            </p>
                            <div v-if="pendingCatchUpByChapter.length">
                                <StudentAssignmentGroupTable
                                    :groups="pendingCatchUpByChapter"
                                    variant="catchup"
                                    count-suffix="pending"
                                />
                            </div>
                        </section>

                        <!-- Practice sets — amber/orange zone -->
                        <section class="rounded-xl border border-amber-200 bg-gradient-to-br from-amber-50 via-orange-50 to-rose-50 p-4 shadow-sm">
                            <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-amber-900">
                                Practice & tests · Pending · {{ pendingAssignments.length }}
                            </h3>

                            <StudentAssignmentGroupTable
                                v-if="pendingAssignments.length"
                                :groups="pendingByChapter"
                                variant="pending"
                                count-suffix="pending"
                            />

                            <div v-else-if="completedAssignments.length" class="rounded-lg border border-dashed border-amber-300 bg-white/70 p-4 text-center text-xs text-amber-900">
                                All caught up — no pending sets right now.
                            </div>

                            <div v-else class="rounded-lg border border-dashed border-amber-300 bg-white/70 p-4 text-center text-xs text-amber-900">
                                No sets assigned yet. Your teacher will assign practice when you're ready.
                            </div>
                        </section>
                    </div>

                    <!-- Submitted — AI checking -->
                    <section
                        v-if="checkingAssignments.length"
                        class="rounded-xl border border-violet-200 bg-gradient-to-br from-violet-50 via-indigo-50 to-sky-50 p-4 shadow-sm"
                    >
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-violet-900">
                            Submitted — being checked · {{ checkingAssignments.length }}
                        </h3>
                        <p class="mb-3 text-xs text-violet-800">
                            We will email you when checking is finished. You can continue with other work below.
                        </p>
                        <StudentAssignmentGroupTable
                            :groups="checkingByChapter"
                            variant="checking"
                            count-suffix="checking"
                        />
                    </section>

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
                                <p
                                    v-if="plan.has_marks || plan.marks_score_label"
                                    class="mt-2 text-sm font-semibold text-emerald-700"
                                >
                                    {{ plan.marks_score_label }}
                                </p>
                                <button
                                    v-else
                                    type="button"
                                    class="mt-2 text-xs font-semibold text-amber-800 underline decoration-amber-400 underline-offset-2 hover:text-amber-950"
                                    @click="openExamMarks(plan.id)"
                                >
                                    Enter school test marks
                                </button>
                                <p class="mt-1 truncate text-[10px] text-gray-500">{{ chapterList(plan) }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- Completed sets — green zone -->
                    <section v-if="completedAssignments.length" class="rounded-xl border border-emerald-200 bg-gradient-to-br from-emerald-50 via-green-50 to-teal-50 p-4 shadow-sm">
                        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-emerald-800">
                            Completed · {{ completedAssignments.length }}
                        </h3>
                        <StudentAssignmentGroupTable
                            :groups="completedByChapter"
                            variant="completed"
                            count-suffix="done"
                        />
                    </section>

                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
