<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StudentPendingWorkPanel from '@/Components/StudentPendingWorkPanel.vue';
import StudentAssignmentGroupTable from '@/Components/StudentAssignmentGroupTable.vue';
import PendingWorkEmailPanel from '@/Components/PendingWorkEmailPanel.vue';
import ClassCoveragePanel from '@/Components/ClassCoveragePanel.vue';
import ContentUploadGuidePanel from '@/Components/ContentUploadGuidePanel.vue';
import ContentUploaderTasksPanel from '@/Components/ContentUploaderTasksPanel.vue';
import HelpRequestUploaderReturn from '@/Components/HelpRequestUploaderReturn.vue';
import QuestionIssueReportActions from '@/Components/QuestionIssueReportActions.vue';
import StudentWeeklyReportEmailsPanel from '@/Components/StudentWeeklyReportEmailsPanel.vue';
import { formatScoreLabel } from '@/utils/scores';
import { formatDate, formatDateTime } from '@/utils/dates';
import { hasRoute, safeRoute } from '@/utils/routes';
import { Head, Link, useForm, usePage, Deferred, router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

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
    resumeItems: { type: Array, default: () => [] },
    latestWorkGroups: { type: Array, default: () => [] },
    olderPendingGroups: { type: Array, default: () => [] },
    followUpItems: { type: Array, default: () => [] },
    activeYear: Object,
    selectedGrade: Object,
    upcomingExams: { type: Array, default: () => [] },
    stats: {
        type: Object,
        default: () => ({}),
    },
    students: { type: Array, default: () => [] },
    helpRequests: { type: Array, default: () => [] },
    questionIssueReports: { type: Array, default: () => [] },
    questionIssueReportsSentToUploader: { type: Array, default: () => [] },
    contentPublishQueue: { type: Array, default: () => [] },
    contentRecheckQueue: { type: Array, default: () => [] },
    lockedAttempts: { type: Array, default: () => [] },
    resolutionItems: { type: Array, default: () => [] },
    resolutionCount: { type: Number, default: 0 },
    weeklyReportEmails: { type: String, default: '' },
    contentUploaderTasks: { type: Object, default: null },
    mailSettings: { type: Object, default: null },
    gradeLevels: { type: Array, default: () => [] },
    loadError: { type: String, default: null },
});

const showHelpRequests = ref(false);
const showQuestionIssues = ref(false);
const questionIssuesSection = ref(null);
const helpRequestsSection = ref(null);
const unlockingAttemptId = ref(null);
const unlockForm = useForm({});
const returningTaskId = ref(null);
const geminiQueueFilter = ref('pending');
const returnForm = useForm({
    reason: 'Please re-check every question. Do not delete any. Correct a sum only if it is wrong.',
});

const sendBackForRecheck = (item) => {
    const chapter = `${item.grade_name || ''} Ch ${item.chapter_number} — ${item.chapter_title}`.trim();
    if (!window.confirm(`Send ${chapter} back to ${item.assignee_name || 'the uploader'} to re-check?\n\nQuestions stay live. They cannot delete after publish.`)) {
        return;
    }

    returningTaskId.value = item.id;
    returnForm.reason = 'Please re-check every question. Do not delete any. Correct a sum only if it is wrong.';
    returnForm.post(route('admin.content-tasks.return-for-reverification', item.id), {
        preserveScroll: true,
        onFinish: () => {
            returningTaskId.value = null;
        },
    });
};

const geminiPendingItems = computed(() =>
    (props.contentRecheckQueue || []).filter((item) =>
        item.gemini_progress?.can_gemini && (item.gemini_progress.pending ?? 0) > 0,
    ),
);

const geminiDoneItems = computed(() =>
    (props.contentRecheckQueue || []).filter((item) =>
        item.gemini_progress?.can_gemini
        && (item.gemini_progress.pending ?? 0) === 0
        && (item.gemini_progress.total ?? 0) > 0,
    ),
);

const filteredGeminiQueue = computed(() => {
    if (geminiQueueFilter.value === 'done') {
        return geminiDoneItems.value;
    }

    return geminiPendingItems.value;
});

const unlockLockedAttempt = (row) => {
    if (!window.confirm(`Unlock ${row.student_name}? They can continue ${row.set_code || 'this set'}.`)) {
        return;
    }

    unlockingAttemptId.value = row.attempt_id;
    unlockForm.post(route('admin.set-attempts.unlock', row.attempt_id), {
        preserveScroll: true,
        onFinish: () => {
            unlockingAttemptId.value = null;
        },
    });
};

const studyPlanSubtitle = computed(() => {
    const parts = [props.studyPlanContext?.grade_name, props.studyPlanContext?.board_name].filter(Boolean);

    return parts.length ? parts.join(' · ') : 'Your class syllabus';
});

const studyStatusOverrides = ref({});

const coverageChapters = computed(() => {
    const base = props.classCoverage?.chapters || [];
    const overrides = studyStatusOverrides.value;

    if (! Object.keys(overrides).length) {
        return base;
    }

    return base.map((chapter) => {
        const patch = overrides[chapter.id];

        return patch ? { ...chapter, ...patch } : chapter;
    });
});

const underStudyChapter = computed(() => {
    const fromMerged = coverageChapters.value.find((chapter) => chapter.under_study);

    if (fromMerged) {
        return fromMerged;
    }

    const id = props.classCoverage?.under_study_chapter_id;
    if (!id) return null;
    return (props.classCoverage.chapters || []).find((c) => c.id === id) || null;
});

const studiedChapterRows = computed(() => coverageChapters.value.filter((c) => c.studied));

const underStudyChapterRows = computed(() => coverageChapters.value.filter((c) => c.under_study));

const resumeItems = computed(() => props.resumeItems || []);
const latestWorkGroups = computed(() => props.latestWorkGroups || []);
const olderPendingGroups = computed(() => props.olderPendingGroups || []);
const followUpItems = computed(() => props.followUpItems || []);

const latestWorkCount = computed(() =>
    latestWorkGroups.value.reduce((count, group) => count + (group.items?.length ?? 0), 0),
);

const olderPendingCount = computed(() =>
    olderPendingGroups.value.reduce((count, group) => count + (group.items?.length ?? 0), 0),
);

const correctingWorksheetId = ref(null);

const startCorrectionPractice = (item) => {
    if (! item.practice_set_id || correctingWorksheetId.value) {
        return;
    }

    correctingWorksheetId.value = item.practice_set_id;

    router.post(route('student.worksheets.correction-practice', item.practice_set_id), {
        assignment_id: item.assignment_id || null,
    }, {
        onFinish: () => {
            correctingWorksheetId.value = null;
        },
    });
};

const followUpActionLabel = (item) => {
    if ((item.pending_remedial ?? 0) > 0 && (item.pending_remedial ?? 0) === (item.pending ?? 0)) {
        return 'Correct now';
    }

    if ((item.pending ?? 0) > 0) {
        return `Continue (${item.pending} left)`;
    }

    return 'Correct now';
};

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

const pendingCatchUpAssignments = computed(() =>
    sortByDateKey(
        props.assignments.filter((a) =>
            a.status !== 'green'
            && a.status !== 'green-late'
            && a.is_catch_up),
        'target_date',
    ),
);

const chapterOrder = computed(() =>
    (props.classCoverage?.chapters || []).map((chapter) => chapter.name),
);

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

const pendingCatchUpByChapter = computed(() => groupAssignmentsByChapter(pendingCatchUpAssignments.value));
const checkingByChapter = computed(() => groupAssignmentsByChapter(checkingAssignments.value));

const assignmentHref = (set) => (
    set.delivery_mode === 'written'
        ? route('student.written-assignments.show', set.assignment_id)
        : route('student.assignments.show', set.assignment_id)
);

const setLabel = (set) => set.set_code || `Set ${set.set_number}`;

const toggleHelpRequests = async () => {
    if (! props.helpRequests.length && ! (props.stats?.help_requests_count > 0)) {
        return;
    }

    showHelpRequests.value = ! showHelpRequests.value;
    if (showHelpRequests.value) {
        showQuestionIssues.value = false;
        await nextTick();
        helpRequestsSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};

const openQuestionIssueCount = computed(() =>
    (props.questionIssueReports?.length || 0)
    + (props.questionIssueReportsSentToUploader?.length || 0)
    || (props.stats?.question_issue_reports_count || 0),
);

const toggleQuestionIssues = async () => {
    if (! openQuestionIssueCount.value) {
        return;
    }

    showQuestionIssues.value = ! showQuestionIssues.value;
    if (showQuestionIssues.value) {
        showHelpRequests.value = false;
        await nextTick();
        questionIssuesSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};

const formatIssueDate = (value) => {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString('en-IN', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
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
                    <div
                        v-if="loadError"
                        class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
                    >
                        <p class="font-semibold">Dashboard could not load some data.</p>
                        <p class="mt-1 font-mono text-xs break-all">{{ loadError }}</p>
                    </div>
                    <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                        {{ page.props.flash.success }}
                    </div>
                    <div v-if="page.props.flash?.error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                        {{ page.props.flash.error }}
                    </div>

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
                                <Link
                                    v-if="hasRoute('admin.basics-drill.index')"
                                    :href="safeRoute('admin.basics-drill.index')"
                                    class="rounded-md bg-white/15 px-2.5 py-1 font-medium hover:bg-white/25"
                                >
                                    Nightly drills
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

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-7">
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
                            :class="showQuestionIssues
                                ? 'border-amber-500 bg-amber-100 ring-2 ring-amber-400'
                                : 'border-amber-200 bg-amber-50 hover:border-amber-400'"
                            :disabled="!openQuestionIssueCount"
                            :title="openQuestionIssueCount ? 'Click to show or hide misprint reports' : 'No misprint reports'"
                            @click="toggleQuestionIssues"
                        >
                            <p class="text-2xl font-extrabold leading-none text-amber-800">{{ stats.question_issue_reports_count || openQuestionIssueCount || 0 }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-amber-800">
                                Misprints
                                <span v-if="openQuestionIssueCount" class="ml-0.5">{{ showQuestionIssues ? '▲' : '▼' }}</span>
                            </p>
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border px-2 py-2.5 text-center shadow-sm transition"
                            :class="showHelpRequests
                                ? 'border-rose-500 bg-rose-100 ring-2 ring-rose-400'
                                : 'border-rose-200 bg-rose-50 hover:border-rose-400'"
                            :disabled="!(helpRequests.length || stats.help_requests_count)"
                            :title="(helpRequests.length || stats.help_requests_count) ? 'Click to show or hide help requests' : 'No help requests'"
                            @click="toggleHelpRequests"
                        >
                            <p class="text-2xl font-extrabold leading-none text-rose-700">{{ stats.help_requests_count || 0 }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-rose-700">
                                Need help
                                <span v-if="helpRequests.length" class="ml-0.5">{{ showHelpRequests ? '▲' : '▼' }}</span>
                            </p>
                        </button>
                        <div
                            class="rounded-lg border px-2 py-2.5 text-center shadow-sm"
                            :class="(stats.locked_attempts_count || lockedAttempts.length)
                                ? 'border-orange-400 bg-orange-50'
                                : 'border-orange-200 bg-orange-50/60'"
                        >
                            <p class="text-2xl font-extrabold leading-none text-orange-700">{{ stats.locked_attempts_count ?? lockedAttempts.length ?? 0 }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-orange-700">Locked</p>
                        </div>
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-2 py-2.5 text-center shadow-sm">
                            <p class="text-2xl font-extrabold leading-none text-amber-700">{{ stats.pending_sets_count || 0 }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-amber-700">To do</p>
                        </div>
                        <div class="rounded-lg border border-violet-200 bg-violet-50 px-2 py-2.5 text-center shadow-sm">
                            <p class="text-2xl font-extrabold leading-none text-violet-700">{{ stats.under_review_sets_count || 0 }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-violet-700">Under review</p>
                        </div>
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-2.5 text-center shadow-sm">
                            <p class="text-2xl font-extrabold leading-none text-emerald-700">{{ stats.completed_sets_count || 0 }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-emerald-700">Done</p>
                        </div>
                    </div>

                    <section
                        v-if="showQuestionIssues"
                        ref="questionIssuesSection"
                        class="scroll-mt-4 space-y-4"
                    >
                        <div class="rounded-xl border-2 border-amber-400 bg-gradient-to-br from-amber-50 via-orange-50 to-yellow-50 p-4 shadow-md">
                            <h3 class="text-sm font-bold uppercase tracking-wide text-amber-950">
                                Misprint / incomplete sums · pending · {{ questionIssueReports.length }}
                            </h3>
                            <p v-if="!questionIssueReports.length" class="mt-2 text-sm text-amber-900">
                                No pending reports — check yourself or send to uploader from new student flags here.
                            </p>
                            <template v-else>
                                <p class="mt-1 text-xs text-amber-900">
                                    Open to check. Student reports are auto-sent to the uploader when a chapter assignee exists
                                    (they edit via Correct →). You can still Edit yourself, mark Fixed, or choose
                                    <span class="font-semibold">Question is correct — please re-attempt</span>.
                                </p>
                                <div class="mt-3 space-y-2">
                                    <div
                                        v-for="item in questionIssueReports"
                                        :key="item.id"
                                        class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-amber-200 bg-white p-3 shadow-sm"
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
                                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-900">
                                                    {{ item.context_label }}
                                                </span>
                                                <Link
                                                    v-if="item.set_code && (item.check_url || item.set_url)"
                                                    :href="item.check_url || item.set_url"
                                                    class="font-mono text-xs font-semibold text-indigo-600 hover:underline"
                                                >
                                                    {{ item.set_code }}
                                                </Link>
                                            </div>
                                            <p class="mt-1 line-clamp-2 text-sm text-gray-800">{{ item.question_text }}</p>
                                        </div>
                                        <div class="flex shrink-0 flex-col items-end gap-1">
                                            <p class="text-xs text-gray-500">{{ formatIssueDate(item.reported_at) }}</p>
                                            <QuestionIssueReportActions :item="item" compact />
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="rounded-xl border-2 border-violet-400 bg-gradient-to-br from-violet-50 via-indigo-50 to-slate-50 p-4 shadow-md">
                            <h3 class="text-sm font-bold uppercase tracking-wide text-violet-950">
                                Sent to uploader · {{ questionIssueReportsSentToUploader.length }}
                            </h3>
                            <p v-if="!questionIssueReportsSentToUploader.length" class="mt-2 text-sm text-violet-900">
                                Nothing waiting on an uploader right now.
                            </p>
                            <template v-else>
                                <p class="mt-1 text-xs text-violet-900">
                                    Uploader was emailed to fix these. If still wrong (e.g. diagram), use
                                    <span class="font-semibold">Resend to uploader</span> with a note, or mark Fixed when done.
                                </p>
                                <div class="mt-3 space-y-2">
                                    <div
                                        v-for="item in questionIssueReportsSentToUploader"
                                        :key="'sent-'+item.id"
                                        class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-violet-200 bg-white p-3 shadow-sm"
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
                                                <span class="rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-violet-900">
                                                    {{ item.context_label }}
                                                </span>
                                                <span
                                                    v-if="item.uploader_name"
                                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-700"
                                                >
                                                    → {{ item.uploader_name }}
                                                </span>
                                                <Link
                                                    v-if="item.set_code && (item.check_url || item.set_url)"
                                                    :href="item.check_url || item.set_url"
                                                    class="font-mono text-xs font-semibold text-indigo-600 hover:underline"
                                                >
                                                    {{ item.set_code }}
                                                </Link>
                                            </div>
                                            <p class="mt-1 line-clamp-2 text-sm text-gray-800">{{ item.question_text }}</p>
                                        </div>
                                        <div class="flex shrink-0 flex-col items-end gap-1">
                                            <p class="text-xs text-gray-500">{{ formatIssueDate(item.reported_at) }}</p>
                                            <QuestionIssueReportActions :item="item" compact />
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </section>

                    <section
                        v-if="showHelpRequests"
                        ref="helpRequestsSection"
                        class="scroll-mt-4 rounded-xl border-2 border-rose-400 bg-gradient-to-br from-rose-50 via-orange-50 to-amber-50 p-4 shadow-md"
                    >
                        <h3 class="text-sm font-bold uppercase tracking-wide text-rose-900">
                            Students asked for help · {{ helpRequests.length }}
                        </h3>
                        <p v-if="!helpRequests.length" class="mt-2 text-sm text-rose-900">
                            No pending help requests to show.
                        </p>
                        <template v-else>
                            <p class="mt-1 text-xs text-rose-800">
                                Edit question if needed, then tap <span class="font-semibold">I have corrected</span> — student retries. No need to send to uploader unless you can’t fix it yourself.
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
                                            <Link
                                                v-if="item.set_code && (item.check_url || item.set_url)"
                                                :href="item.check_url || item.set_url"
                                                class="font-mono text-xs font-semibold text-indigo-600 hover:underline"
                                            >
                                                {{ item.set_code }}
                                            </Link>
                                        </div>
                                        <p class="mt-1 line-clamp-2 text-sm text-gray-800">{{ item.question_text }}</p>
                                    </div>
                                    <div class="flex shrink-0 flex-col items-end gap-1">
                                        <p class="text-xs text-gray-500">{{ formatHelpDate(item.gave_up_at) }}</p>
                                        <Link
                                            v-if="item.edit_url"
                                            :href="item.edit_url"
                                            class="text-xs font-semibold text-indigo-700 hover:underline"
                                        >
                                            Edit question
                                        </Link>
                                        <HelpRequestUploaderReturn :item="item" compact />
                                    </div>
                                </div>
                            </div>
                        </template>
                    </section>

                    <section
                        v-if="lockedAttempts.length"
                        class="rounded-xl border border-orange-300 bg-gradient-to-br from-orange-50 to-amber-50 p-4 shadow-sm"
                    >
                        <p class="text-sm font-semibold text-orange-950">
                            Locked students · {{ lockedAttempts.length }}
                        </p>
                        <p class="mt-0.5 text-xs text-orange-900/80">
                            These attempts locked after too many tab/app switches. Unlock so the student can continue.
                        </p>
                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="row in lockedAttempts"
                                :key="row.attempt_id"
                                class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white/90 px-3 py-2.5 text-sm shadow-sm"
                            >
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold text-gray-900">{{ row.student_name }}</span>
                                        <span v-if="row.class_name" class="text-xs text-gray-500">{{ row.class_name }}</span>
                                        <span class="rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-orange-800">
                                            {{ row.kind_label }}
                                        </span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-gray-600">
                                        <span class="font-mono font-semibold text-indigo-700">{{ row.set_code }}</span>
                                        · attempt #{{ row.attempt_number }}
                                        · leaves {{ row.tab_leave_count }}/{{ row.tab_leave_lock_limit }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <Link
                                        v-if="row.assignment_id"
                                        :href="route('admin.set-assignments.show', row.assignment_id)"
                                        class="text-xs font-medium text-indigo-600 hover:underline"
                                    >
                                        Open
                                    </Link>
                                    <button
                                        type="button"
                                        class="rounded-md bg-orange-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-800 disabled:opacity-50"
                                        :disabled="unlockingAttemptId === row.attempt_id || unlockForm.processing"
                                        @click="unlockLockedAttempt(row)"
                                    >
                                        {{ unlockingAttemptId === row.attempt_id ? 'Unlocking…' : 'Unlock' }}
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <Deferred :data="['contentPublishQueue', 'contentRecheckQueue']">
                        <template #fallback>
                            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-600">
                                Loading content queues…
                            </div>
                        </template>

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
                                <div class="flex flex-wrap items-center gap-3">
                                    <button
                                        type="button"
                                        class="text-amber-800 hover:underline disabled:opacity-50"
                                        :disabled="returningTaskId === item.id"
                                        @click="sendBackForRecheck(item)"
                                    >
                                        {{ returningTaskId === item.id ? 'Sending…' : 'Send back to check' }}
                                    </button>
                                    <Link :href="route('admin.content-tasks.show', item.id)" class="text-indigo-600 hover:underline">
                                        Review →
                                    </Link>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <section
                        v-if="contentRecheckQueue.length"
                        class="rounded-xl border border-indigo-200 bg-gradient-to-br from-indigo-50 to-violet-50 p-4 shadow-sm"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-indigo-950">
                                Gemini answer check · published chapters
                            </p>
                            <Link :href="route('admin.content-tasks.index')" class="text-xs font-medium text-indigo-600 hover:underline">
                                Content matrix →
                            </Link>
                        </div>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <button
                                type="button"
                                class="rounded-lg px-3 py-2 text-left ring-1 transition"
                                :class="geminiQueueFilter === 'pending'
                                    ? 'bg-amber-600 text-white ring-amber-600'
                                    : 'bg-white text-amber-950 ring-amber-200 hover:bg-amber-50'"
                                @click="geminiQueueFilter = 'pending'"
                            >
                                <p class="text-xs font-semibold uppercase tracking-wide opacity-80">Pending</p>
                                <p class="text-xl font-bold">{{ stats.gemini_pending_count ?? geminiPendingItems.length }}</p>
                            </button>
                            <button
                                type="button"
                                class="rounded-lg px-3 py-2 text-left ring-1 transition"
                                :class="geminiQueueFilter === 'done'
                                    ? 'bg-emerald-600 text-white ring-emerald-600'
                                    : 'bg-white text-emerald-950 ring-emerald-200 hover:bg-emerald-50'"
                                @click="geminiQueueFilter = 'done'"
                            >
                                <p class="text-xs font-semibold uppercase tracking-wide opacity-80">Done</p>
                                <p class="text-xl font-bold">{{ stats.gemini_done_count ?? geminiDoneItems.length }}</p>
                            </button>
                        </div>
                        <ul v-if="filteredGeminiQueue.length" class="mt-3 space-y-2">
                            <li
                                v-for="item in filteredGeminiQueue"
                                :key="`gemini-${item.id}`"
                                class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white/90 px-3 py-2 text-sm"
                            >
                                <span>
                                    {{ item.grade_name }} · Ch {{ item.chapter_number }} — {{ item.chapter_title }}
                                    <span class="text-gray-500">· {{ item.assignee_name }}</span>
                                    <span
                                        v-if="item.gemini_progress?.can_gemini"
                                        class="ml-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="item.gemini_progress.pending === 0 ? 'bg-emerald-100 text-emerald-900' : 'bg-amber-100 text-amber-900'"
                                    >
                                        {{ item.gemini_progress.verified }}/{{ item.gemini_progress.total }}
                                    </span>
                                </span>
                                <Link
                                    :href="route('admin.content-tasks.show', item.id)"
                                    class="shrink-0 text-xs font-semibold text-indigo-700 hover:underline"
                                >
                                    {{ item.gemini_progress?.pending > 0 ? 'Gemini check →' : 'Open →' }}
                                </Link>
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-sm text-indigo-900">
                            No chapters in this Gemini bucket.
                        </p>
                    </section>
                    </Deferred>

                    <section class="space-y-3">
                        <div>
                            <h3 class="text-sm font-bold uppercase tracking-wide text-gray-800">Classes</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Open a class to see student progress, then click a student to go to their study plan.
                            </p>
                        </div>

                        <div v-if="gradeLevels.length === 0" class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm">
                            No active classes yet.
                        </div>

                        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <Link
                                v-for="grade in gradeLevels"
                                :key="grade.id"
                                :href="safeRoute('admin.classes.show', grade.id, `/admin/classes/${grade.id}`)"
                                class="rounded-xl border border-gray-200 bg-white px-4 py-4 shadow-sm transition hover:border-indigo-300 hover:shadow-md"
                            >
                                <p class="text-lg font-bold text-gray-900">{{ grade.name }}</p>
                                <p class="mt-1 text-sm text-gray-600">
                                    {{ grade.students_count || 0 }} student{{ (grade.students_count || 0) === 1 ? '' : 's' }}
                                </p>
                                <p class="mt-1 text-xs font-medium text-indigo-700">View students →</p>
                            </Link>
                        </div>
                    </section>
                </template>

                <!-- Student / teacher / uploader dashboard -->
                <template v-else>
                    <div
                        v-if="loadError"
                        class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
                    >
                        <p class="font-semibold">We could not load all of your dashboard.</p>
                        <p class="mt-1">{{ loadError }}</p>
                    </div>

                    <ContentUploadGuidePanel v-if="isContentUploader" variant="uploader" />

                    <ContentUploaderTasksPanel
                        v-if="contentUploaderTasks?.summary?.total_active || contentUploaderTasks?.correctionsPending?.length || contentUploaderTasks?.geminiPending?.length"
                        class="mb-4"
                        compact
                        :summary="contentUploaderTasks.summary"
                        :upload-pending="contentUploaderTasks.uploadPending"
                        :review-pending="contentUploaderTasks.reviewPending"
                        :corrections-pending="contentUploaderTasks.correctionsPending"
                        :gemini-pending="contentUploaderTasks.geminiPending"
                        :gemini-done="contentUploaderTasks.geminiDone"
                    />

                    <!-- Latest in-progress work — finish this first -->
                    <section
                        v-if="latestWorkCount"
                        class="rounded-xl border-2 border-sky-400 bg-gradient-to-br from-sky-50 via-cyan-50 to-white p-4 shadow-md"
                    >
                        <h3 class="text-sm font-bold uppercase tracking-wide text-sky-950">
                            Continue now · {{ latestWorkCount }}
                        </h3>
                        <p class="mt-1 text-xs text-sky-900">
                            Your most recent chapter — pick up where you stopped.
                        </p>
                        <div class="mt-3">
                            <StudentPendingWorkPanel
                                :groups="latestWorkGroups"
                                variant="latest"
                                :chapter-order="chapterOrder"
                            />
                        </div>
                    </section>

                    <!-- Older in-progress — other chapters left mid-way -->
                    <section
                        v-if="olderPendingCount"
                        class="rounded-xl border border-slate-300 bg-slate-50 p-4 shadow-sm"
                    >
                        <h3 class="text-sm font-bold uppercase tracking-wide text-slate-800">
                            Left mid-way earlier · {{ olderPendingCount }}
                        </h3>
                        <p class="mt-1 text-xs text-slate-600">
                            You started these in other chapters and closed before finishing — grouped by chapter.
                        </p>
                        <div class="mt-3">
                            <StudentPendingWorkPanel
                                :groups="olderPendingGroups"
                                variant="older"
                                :chapter-order="chapterOrder"
                            />
                        </div>
                    </section>

                    <!-- Recently finished — correct wrongs or review latest score -->
                    <section
                        v-if="followUpItems.length"
                        class="rounded-xl border-2 border-violet-400 bg-gradient-to-br from-violet-50 via-fuchsia-50 to-white p-4 shadow-md"
                    >
                        <h3 class="text-sm font-bold uppercase tracking-wide text-violet-950">
                            Recent sets · {{ followUpItems.length }}
                        </h3>
                        <p class="mt-1 text-xs text-violet-900">
                            Your latest finished work is here. Correct wrong sums to reach 100% — no need to open the chapter again.
                        </p>
                        <div class="mt-3 space-y-2">
                            <div
                                v-for="item in followUpItems"
                                :key="`follow-up-${item.assignment_id}`"
                                class="rounded-lg border border-violet-200 bg-white px-3 py-2.5 shadow-sm"
                            >
                                <p v-if="item.chapter_name" class="text-[11px] font-bold uppercase tracking-wide text-slate-700">
                                    {{ item.chapter_name }}
                                    <span v-if="item.topic_name" class="font-medium normal-case tracking-normal text-slate-500">
                                        · {{ item.topic_name }}
                                    </span>
                                </p>
                                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-sm font-bold text-slate-900">
                                        {{ item.set_code || 'Set' }}
                                    </span>
                                    <span
                                        v-if="item.score_label"
                                        class="rounded bg-violet-100 px-2 py-0.5 text-[11px] font-bold text-violet-950"
                                    >
                                        {{ item.score_label }}
                                    </span>
                                    <span
                                        v-if="item.under_review"
                                        class="rounded bg-amber-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-amber-900"
                                    >
                                        Under review
                                    </span>
                                    <span
                                        v-else-if="item.needs_follow_up"
                                        class="rounded bg-fuchsia-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-fuchsia-950"
                                    >
                                        Not 100% yet
                                    </span>
                                    <span
                                        v-else
                                        class="rounded bg-emerald-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-emerald-900"
                                    >
                                        Done
                                    </span>
                                    <span
                                        v-if="item.detail"
                                        class="text-xs text-slate-600"
                                    >
                                        {{ item.detail }}
                                    </span>
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-600">
                                        {{ item.kind_label }}
                                    </span>
                                    <div class="ml-auto flex flex-wrap items-center gap-2">
                                        <button
                                            v-if="item.can_correct"
                                            type="button"
                                            class="inline-flex rounded-md bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-700 disabled:opacity-60"
                                            :disabled="correctingWorksheetId === item.practice_set_id"
                                            @click="startCorrectionPractice(item)"
                                        >
                                            {{ correctingWorksheetId === item.practice_set_id ? 'Starting…' : followUpActionLabel(item) }}
                                        </button>
                                        <Link
                                            v-if="item.latest_attempt_id"
                                            :href="route('student.attempts.result', item.latest_attempt_id)"
                                            class="inline-flex rounded-md border border-violet-300 bg-white px-3 py-1.5 text-xs font-semibold text-violet-800 hover:bg-violet-50"
                                        >
                                            Review
                                        </Link>
                                        <Link
                                            :href="assignmentHref(item)"
                                            class="inline-flex rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                        >
                                            Open set
                                        </Link>
                                    </div>
                                </div>
                                <p v-if="item.submitted_at" class="mt-1 text-[10px] text-slate-500">
                                    Finished {{ formatDateTime(item.submitted_at) }}
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- School study plan — primary student landing -->
                    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <Deferred data="classCoverage">
                            <template #fallback>
                                <div class="animate-pulse space-y-3 py-6">
                                    <div class="h-4 w-48 rounded bg-slate-200" />
                                    <div class="h-32 rounded-lg bg-slate-100" />
                                    <p class="text-center text-sm text-slate-500">Loading your study plan…</p>
                                </div>
                            </template>

                        <div v-if="classCoverage?.load_error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                            {{ classCoverage.load_error }}
                        </div>

                        <div v-if="underStudyChapter" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-950">Please complete</p>
                            <p class="mt-1 text-sm text-amber-900">
                                Continue your current chapter:
                                <span class="font-semibold">{{ underStudyChapter.label }} — {{ underStudyChapter.name }}</span>
                            </p>
                        </div>

                        <div
                            v-else-if="classCoverage.chapters?.length && studiedChapterRows.length === 0 && underStudyChapterRows.length === 0"
                            class="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3"
                        >
                            <p class="text-xs font-semibold uppercase tracking-wide text-sky-950">Please start — study plan first</p>
                            <p class="mt-1 text-sm text-sky-900">
                                Mark chapters as <span class="font-semibold">Studied</span> or one as
                                <span class="font-semibold">Under study</span> below.
                                No drills on day one — daily drills unlock from tomorrow after your study plan is filled.
                            </p>
                        </div>

                        <ClassCoveragePanel
                            :class-coverage="classCoverage"
                            :upcoming-exams="upcomingExams"
                            @status-overrides="studyStatusOverrides = $event"
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
                        </Deferred>
                    </section>

                    <!-- Welcome — single compact row -->
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gradient-to-r from-emerald-600 via-teal-600 to-cyan-600 px-4 py-3 text-white shadow">
                        <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                            <p class="text-base font-semibold whitespace-nowrap">Welcome, {{ $page.props.auth.user.name }}</p>
                            <span class="hidden text-emerald-100/70 sm:inline">·</span>
                            <p class="hidden text-xs text-emerald-100 sm:inline">Use your study plan above for practice and exams</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <Link
                                v-if="route().has('student.exams.index')"
                                :href="route('student.exams.index')"
                                class="rounded-full bg-white/20 px-2.5 py-0.5 underline decoration-white/50 underline-offset-2 hover:bg-white/30"
                            >
                                {{ stats.upcoming_exams || 0 }} exams
                            </Link>
                            <span v-else class="rounded-full bg-white/20 px-2.5 py-0.5">{{ stats.upcoming_exams || 0 }} exams</span>
                            <span class="rounded-full bg-amber-300/40 px-2.5 py-0.5">{{ stats.sets_todo || 0 }} to do</span>
                            <span
                                v-if="stats.sets_under_review"
                                class="rounded-full bg-violet-300/50 px-2.5 py-0.5"
                            >
                                {{ stats.sets_under_review }} under review
                            </span>
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

                    <Deferred data="assignments">
                        <template #fallback>
                            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-center text-sm text-slate-500">
                                Loading your set lists…
                            </div>
                        </template>

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

                    <!-- Submitted — under review (AI / teacher) -->
                    <section
                        v-if="checkingAssignments.length"
                        class="rounded-xl border border-violet-200 bg-gradient-to-br from-violet-50 via-indigo-50 to-sky-50 p-4 shadow-sm"
                    >
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-violet-900">
                            Under review · {{ checkingAssignments.length }}
                        </h3>
                        <p class="mb-2 text-xs text-violet-800">
                            Your written work is uploaded. AI is checking it (or a teacher will review the steps). Open a chip to see photos and status.
                        </p>
                        <div class="mb-3 flex flex-wrap gap-1.5">
                            <Link
                                v-for="set in checkingAssignments"
                                :key="`chip-review-${set.assignment_id}`"
                                :href="assignmentHref(set)"
                                class="rounded border border-violet-300 bg-white px-2.5 py-1 text-[11px] font-mono font-semibold text-violet-900 shadow-sm hover:bg-violet-50"
                            >
                                {{ setLabel(set) }}
                                <span class="font-sans font-medium"> · under review</span>
                            </Link>
                        </div>
                        <StudentAssignmentGroupTable
                            :groups="checkingByChapter"
                            variant="checking"
                            count-suffix="under review"
                        />
                    </section>
                        </Deferred>

                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
