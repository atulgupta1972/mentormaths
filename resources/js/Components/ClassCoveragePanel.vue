<script setup>
import { formatDate } from '@/utils/dates';
import ChapterPerformanceSummary from '@/Components/ChapterPerformanceSummary.vue';
import CoverageItemsWithRevisionRail from '@/Components/CoverageItemsWithRevisionRail.vue';
import CoverageSetItemCard from '@/Components/CoverageSetItemCard.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    classCoverage: {
        type: Object,
        default: () => ({
            chapters: [],
            under_study_chapter_id: null,
            availability_columns: [],
        }),
    },
    upcomingExams: {
        type: Array,
        default: () => [],
    },
    updateRouteName: {
        type: String,
        default: 'student.class-coverage.update',
    },
    updateRouteParams: {
        type: Object,
        default: () => ({}),
    },
    assignStudentId: {
        type: [Number, String, null],
        default: null,
    },
});

const savingId = ref(null);
const saveError = ref('');
const assigningWorksheetId = ref(null);
const expandedChapterIds = ref(new Set());

const chapters = computed(() => props.classCoverage?.chapters ?? []);
const additionalGroups = computed(() => props.classCoverage?.additional_groups ?? []);
const chapterChoices = computed(() => props.classCoverage?.chapter_choices ?? []);
const isStudentView = computed(() => String(props.updateRouteName).startsWith('student.'));
const canStaffAssign = computed(() => ! isStudentView.value && Boolean(props.assignStudentId) && route().has('admin.practice-sets.assign'));
const canMoveChapter = computed(() =>
    (isStudentView.value && route().has('student.assignments.study-chapter'))
    || route().has('admin.set-assignments.effective-chapter'),
);
/** Ch No + Chapter + Topics + Completion % + Score % + Revision status + Studied + Under study */
const columnCount = computed(() => 8);

const movingAssignmentId = ref(null);
const moveTargets = ref({});

const studyChapterUrl = (assignmentId) => {
    if (isStudentView.value && route().has('student.assignments.study-chapter')) {
        return route('student.assignments.study-chapter', assignmentId);
    }

    return route('admin.set-assignments.effective-chapter', assignmentId);
};

const moveAdditionalToChapter = (item) => {
    if (! item.assignment_id || ! canMoveChapter.value) {
        return;
    }

    const chapterId = moveTargets.value[item.assignment_id];
    if (! chapterId) {
        saveError.value = 'Pick a chapter first, then click Move.';

        return;
    }

    movingAssignmentId.value = item.assignment_id;
    saveError.value = '';
    router.post(studyChapterUrl(item.assignment_id), {
        effective_syllabus_chapter_id: chapterId,
    }, {
        preserveScroll: true,
        onFinish: () => {
            movingAssignmentId.value = null;
        },
    });
};

const leaveInAdditional = (item) => {
    if (! item.assignment_id || ! canMoveChapter.value) {
        return;
    }

    movingAssignmentId.value = item.assignment_id;
    saveError.value = '';
    router.post(studyChapterUrl(item.assignment_id), {
        effective_syllabus_chapter_id: null,
    }, {
        preserveScroll: true,
        onFinish: () => {
            movingAssignmentId.value = null;
        },
    });
};

const todayDate = () => {
    const date = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

const pendingAssignKey = ref(null);
const staffAssignForm = useForm({
    student_id: null,
    target_date: todayDate(),
});

const itemKey = (groupKey, item) => `${groupKey}-${item.worksheet_id}`;

const canAssignItem = (item) => canStaffAssign.value && item.worksheet_id && ! item.is_correction;

const openStaffAssign = (item, groupKey) => {
    pendingAssignKey.value = itemKey(groupKey, item);
    staffAssignForm.student_id = props.assignStudentId;
    staffAssignForm.target_date = todayDate();
    saveError.value = '';
};

const confirmStaffAssign = (item) => {
    if (! item.worksheet_id || staffAssignForm.processing) {
        return;
    }

    staffAssignForm.student_id = props.assignStudentId;
    staffAssignForm.post(route('admin.practice-sets.assign', item.worksheet_id), {
        preserveScroll: true,
        onSuccess: () => {
            pendingAssignKey.value = null;
            saveError.value = '';
        },
        onError: (errors) => {
            const first = Object.values(errors ?? {})[0];
            saveError.value = Array.isArray(first) ? first[0] : (first || 'Could not assign. Please try again.');
        },
    });
};

const daysUntilExam = (dateStr) => {
    if (!dateStr) {
        return null;
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const target = new Date(`${dateStr}T00:00:00`);

    return Math.ceil((target - today) / (1000 * 60 * 60 * 24));
};

const upcomingExamByChapterId = computed(() => {
    const map = new Map();

    for (const plan of props.upcomingExams ?? []) {
        if (!plan?.exam_date) {
            continue;
        }

        const days = daysUntilExam(plan.exam_date);
        if (days === null || days < 0) {
            continue;
        }

        const chapterIds = plan.chapter_ids
            ?? (plan.chapters || []).map((chapter) => chapter.id);

        for (const chapterId of chapterIds) {
            const existing = map.get(chapterId);
            if (!existing || plan.exam_date < existing.exam_date) {
                map.set(chapterId, plan);
            }
        }
    }

    return map;
});

const upcomingExamForChapter = (chapterId) => upcomingExamByChapterId.value.get(chapterId) ?? null;

const chapterExamUrgency = (chapterId) => {
    const plan = upcomingExamForChapter(chapterId);
    if (!plan) {
        return null;
    }

    const days = daysUntilExam(plan.exam_date);
    if (days !== null && days <= 7) {
        return 'urgent';
    }

    return 'upcoming';
};

/** Soft row tint only — thick rings break across table cells. */
const chapterExamRowClass = (chapterId) => {
    const urgency = chapterExamUrgency(chapterId);
    if (urgency === 'urgent') {
        return 'bg-rose-50';
    }
    if (urgency === 'upcoming') {
        return 'bg-amber-50';
    }

    return '';
};

/** Thin continuous top/bottom line on every cell (full row). */
const chapterRowLineClass = (chapterId) => {
    const urgency = chapterExamUrgency(chapterId);
    if (urgency === 'urgent') {
        return 'shadow-[inset_0_1px_0_0_#7f1d1d,inset_0_-1px_0_0_#7f1d1d]';
    }
    if (urgency === 'upcoming') {
        return 'shadow-[inset_0_1px_0_0_#78350f,inset_0_-1px_0_0_#78350f]';
    }

    return 'border-b border-slate-200';
};

const chapterExamBadgeClass = (chapterId) => {
    const urgency = chapterExamUrgency(chapterId);
    if (urgency === 'urgent') {
        return 'border-rose-900 bg-rose-100 text-rose-950';
    }
    if (urgency === 'upcoming') {
        return 'border-amber-900 bg-amber-100 text-amber-950';
    }

    return '';
};

const examDueLabel = (plan) => {
    const days = daysUntilExam(plan.exam_date);
    if (days === 0) {
        return 'Exam today';
    }
    if (days === 1) {
        return 'Exam tomorrow';
    }
    if (days !== null && days > 0) {
        return `Exam ${formatDate(plan.exam_date)}`;
    }

    return plan.title || 'Upcoming exam';
};

const mark = (chapter, status) => {
    if (savingId.value) {
        return;
    }

    if (!route().has(props.updateRouteName)) {
        saveError.value = 'Save route is not available. Try refreshing the page.';

        return;
    }

    let nextStatus = status;
    if (status === 'studied' && chapter.studied) {
        nextStatus = 'none';
    } else if (status === 'under_study' && chapter.under_study) {
        nextStatus = 'none';
    } else if (status === 'studied' && chapter.under_study) {
        nextStatus = 'studied';
    } else if (status === 'under_study' && chapter.studied) {
        nextStatus = 'under_study';
    }

    savingId.value = chapter.id;
    saveError.value = '';

    const params = {
        ...props.updateRouteParams,
        syllabusChapter: chapter.id,
    };

    router.put(route(props.updateRouteName, params), {
        status: nextStatus,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            saveError.value = '';
        },
        onError: (errors) => {
            const first = Object.values(errors ?? {})[0];

            saveError.value = Array.isArray(first) ? first[0] : (first || 'Could not save. Please try again.');
        },
        onFinish: () => {
            savingId.value = null;
        },
    });
};

const toggleChapter = (chapterId) => {
    const next = new Set(expandedChapterIds.value);

    if (next.has(chapterId)) {
        next.delete(chapterId);
    } else {
        next.add(chapterId);
    }

    expandedChapterIds.value = next;
};

const isExpanded = (chapterId) => expandedChapterIds.value.has(chapterId);

const chapterNumberLabel = (chapter) => {
    const number = String(chapter.chapter_number ?? '').trim();

    if (! number) {
        return '—';
    }

    return number.toLowerCase().startsWith('ch') ? number : `Ch ${number}`;
};

const chapterNameLabel = (chapter) => chapter.name || chapter.label || 'Chapter';

const chapterTitle = (chapter) => {
    const number = chapterNumberLabel(chapter);
    const name = chapterNameLabel(chapter);

    if (number === '—') {
        return name;
    }

    return `${number} — ${name}`;
};

const availabilityCount = (chapter, key) => Number(chapter.availability?.[key] ?? 0);

const questionSuffix = (item) => {
    const count = Number(item.question_count ?? 0);

    return count > 0 ? ` (${count})` : '';
};

const statusClass = (status) => ({
    done: 'bg-emerald-100 text-emerald-900',
    checking: 'bg-amber-100 text-amber-900',
    overdue: 'bg-rose-100 text-rose-900',
    in_progress: 'bg-sky-100 text-sky-900',
    pending: 'bg-slate-100 text-slate-700',
    not_assigned: 'bg-slate-100 text-slate-600',
    correction_pending: 'bg-orange-100 text-orange-900',
}[status] ?? 'bg-slate-100 text-slate-700');

const groupHeaderClass = (group) => ({
    sky: 'text-sky-800',
    amber: 'text-amber-800',
    emerald: 'text-emerald-800',
}[group?.color] ?? 'text-slate-700');

const isTierDashboard = (chapter) => chapter?.items?.layout === 'tier_blocks';

const chapterDashboard = (chapter) => (isTierDashboard(chapter) ? chapter.items : null);

const blockShellClass = (color) => ({
    sky: 'border-2 border-sky-500 bg-white shadow-md ring-1 ring-sky-200',
    amber: 'border-2 border-amber-500 bg-white shadow-md ring-1 ring-amber-200',
    emerald: 'border-2 border-emerald-600 bg-white shadow-md ring-1 ring-emerald-200',
}[color] ?? 'border-2 border-slate-400 bg-white shadow-md');

const blockTitleClass = (color) => ({
    sky: 'bg-sky-700 text-white',
    amber: 'bg-amber-600 text-white',
    emerald: 'bg-emerald-700 text-white',
}[color] ?? 'bg-slate-800 text-white');

const hasDashboardContent = (dashboard) => {
    if (! dashboard) {
        return false;
    }

    const blockItems = (dashboard.blocks || []).some((block) => (block.item_count || 0) > 0);
    const extras = ['formula', 'books', 'other', 'revisions'].some(
        (key) => (dashboard[key]?.items?.length || 0) > 0,
    );
    const bookGroups = (dashboard.book_groups || []).some(
        (group) => (group.items?.length || 0) > 0 || (group.revision_items?.length || 0) > 0,
    );
    const otherGroups = (dashboard.other_groups || []).some((group) => (group.items?.length || 0) > 0);

    return blockItems || extras || bookGroups || otherGroups;
};

const bookGroups = (dashboard) => {
    const groups = (dashboard?.book_groups ?? []).filter((group) => (group.items?.length || 0) > 0);
    if (groups.length) {
        return groups;
    }

    const items = dashboard?.books?.items ?? [];
    if (! items.length) {
        return [];
    }

    const byName = {};
    for (const item of items) {
        const name = item.textbook_name || 'Book content';
        if (! byName[name]) {
            byName[name] = [];
        }
        byName[name].push(item);
    }

    return Object.entries(byName).map(([name, groupItems]) => ({
        id: name,
        name,
        items: groupItems,
    }));
};

const otherGroups = (dashboard) => {
    const groups = (dashboard?.other_groups ?? []).filter((group) => (group.items?.length || 0) > 0);
    if (groups.length) {
        return groups;
    }

    const items = dashboard?.other?.items ?? [];
    if (! items.length) {
        return [];
    }

    return [{
        id: 'other',
        label: 'Other',
        items,
    }];
};

/** Hide empty Fill / Written / Test rows; keep only tiers that have at least one set. */
const visibleBlocks = (dashboard) =>
    (dashboard?.blocks ?? [])
        .map((block) => ({
            ...block,
            rows: (block.rows ?? []).filter((row) => (row.items?.length || 0) > 0),
        }))
        .filter((block) => block.rows.length > 0);

const collectDashboardItems = (dashboard) => {
    if (! dashboard) {
        return [];
    }

    const items = [];

    for (const block of dashboard.blocks ?? []) {
        for (const row of block.rows ?? []) {
            items.push(...(row.items ?? []));
            items.push(...(row.revision_items ?? []));
        }
    }

    for (const key of ['formula', 'books']) {
        items.push(...(dashboard[key]?.items ?? []));
    }

    for (const book of dashboard.book_groups ?? []) {
        items.push(...(book.revision_items ?? []));
    }

    const groups = dashboard.other_groups ?? [];
    if (groups.length) {
        for (const group of groups) {
            items.push(...(group.items ?? []));
        }
    } else {
        items.push(...(dashboard.other?.items ?? []));
    }

    return items;
};

const sumsForItem = (item) => {
    const poolMetrics = item.pool_metrics;
    if (poolMetrics && Number(poolMetrics.pool || 0) > 0) {
        return {
            pool: Number(poolMetrics.pool || 0),
            attempted: Number(poolMetrics.attempted || 0),
            correct: Number(poolMetrics.correct || 0),
        };
    }

    const questions = Number(item.question_count || 0);
    if (questions <= 0) {
        return { pool: 0, attempted: 0, correct: 0 };
    }

    if (item.status === 'done') {
        const pct = item.score_percent ?? item.latest_score_percent;
        const correct = pct != null && pct !== ''
            ? Math.round((Number(pct) / 100) * questions)
            : questions;

        return {
            pool: questions,
            attempted: questions,
            correct: Math.max(0, Math.min(questions, correct)),
        };
    }

    return { pool: questions, attempted: 0, correct: 0 };
};

const aggregateSums = (items) => {
    let pool = 0;
    let attempted = 0;
    let correct = 0;
    let setDone = 0;

    for (const item of items) {
        const sums = sumsForItem(item);
        pool += sums.pool;
        attempted += sums.attempted;
        correct += sums.correct;
        if (item.status === 'done') {
            setDone += 1;
        }
    }

    return {
        pool,
        attempted,
        correct,
        completionPct: pool > 0 ? Math.round((attempted / pool) * 100) : null,
        scorePct: pool > 0 ? Math.round((correct / pool) * 100) : null,
        setTotal: items.length,
        setDone,
    };
};

const performanceFromItems = (items) => {
    const main = items.filter((item) => ! item.is_correction && ! item.is_revision);
    const revisions = items.filter((item) => item.is_revision);
    const corrections = items.filter((item) => item.is_correction);

    const mainAgg = aggregateSums(main);
    const revisionAgg = aggregateSums(revisions);

    const correctionDone = corrections.filter((item) => item.status === 'done').length;
    const correctionPending = corrections.filter((item) => item.status !== 'done').length;
    const openWrongs = items
        .filter((item) => Number(item.correction_count || 0) > 0 && item.can_redo_wrong)
        .reduce((sum, item) => sum + Number(item.correction_count || 0), 0);

    return {
        total: mainAgg.pool,
        done: mainAgg.attempted,
        correct: mainAgg.correct,
        completionPct: mainAgg.completionPct,
        scorePct: mainAgg.scorePct,
        scoredCount: mainAgg.correct,
        setTotal: mainAgg.setTotal,
        setDone: mainAgg.setDone,
        revisionTotal: revisionAgg.pool,
        revisionDone: revisionAgg.attempted,
        revisionCorrect: revisionAgg.correct,
        revisionCompletionPct: revisionAgg.completionPct,
        revisionScorePct: revisionAgg.scorePct,
        revisionScoredCount: revisionAgg.correct,
        correctionDone,
        correctionPending,
        openWrongs,
        revisionPending: Math.max(0, revisionAgg.pool - revisionAgg.attempted) + openWrongs,
    };
};

const chapterPerformance = (dashboard) => {
    const perf = performanceFromItems(collectDashboardItems(dashboard));

    if (
        perf.completionPct === null
        && perf.scorePct === null
        && perf.revisionCompletionPct === null
        && perf.revisionScorePct === null
        && perf.correctionDone === 0
        && perf.correctionPending === 0
        && perf.openWrongs === 0
        && perf.revisionTotal === 0
    ) {
        return null;
    }

    return perf;
};

/** Always returns glance stats for the coverage table (— when no data). */
const chapterRowStats = (chapter) => {
    let items = [];

    if (isTierDashboard(chapter)) {
        items = collectDashboardItems(chapterDashboard(chapter));
    } else {
        for (const group of chapter.items ?? []) {
            items.push(...(group.items ?? []));
            items.push(...(group.revision_items ?? []));
        }
    }

    return performanceFromItems(items);
};

const chapterStatsById = computed(() => {
    const map = {};

    for (const chapter of chapters.value) {
        map[chapter.id] = chapterRowStats(chapter);
    }

    return map;
});

const chaptersWithStats = computed(() =>
    chapters.value.map((chapter) => ({
        chapter,
        stats: chapterStatsById.value[chapter.id],
    })),
);

const truncateTopics = (label, max = 75) => {
    const text = String(label ?? '').trim();

    if (! text) {
        return '';
    }

    if (text.length <= max) {
        return text;
    }

    return `${text.slice(0, max).trimEnd()}…`;
};

const pctToneClass = (pct) => {
    if (pct == null) {
        return 'text-slate-400';
    }
    if (pct >= 80) {
        return 'text-emerald-700';
    }
    if (pct >= 50) {
        return 'text-amber-700';
    }

    return 'text-rose-700';
};

const formatDisplayDate = (isoDate) => {
    try {
        return new Date(`${isoDate}T00:00:00`).toLocaleDateString(undefined, {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    } catch {
        return isoDate;
    }
};

const trackedStudyChapters = computed(() =>
    chapters.value.filter((chapter) => chapter.studied || chapter.under_study),
);

/** Aggregate performance for chapters ticked Studied / Under study — as on today. */
const studyPlanPerformance = computed(() => {
    const tracked = trackedStudyChapters.value;

    if (! tracked.length) {
        return null;
    }

    const items = [];
    const labels = [];

    for (const chapter of tracked) {
        if (isTierDashboard(chapter)) {
            items.push(...collectDashboardItems(chapterDashboard(chapter)));
        } else {
            for (const group of chapter.items ?? []) {
                items.push(...(group.items ?? []));
                items.push(...(group.revision_items ?? []));
            }
        }

        const number = String(chapter.chapter_number ?? '').trim();
        const short = number
            ? (number.toLowerCase().startsWith('ch') ? number : `Ch ${number}`)
            : (chapter.name || 'Chapter');
        labels.push(short);
    }

    for (const group of additionalGroups.value) {
        items.push(...(group.items ?? []));
    }

    return {
        ...performanceFromItems(items),
        chapterCount: tracked.length,
        chapterLabels: labels.slice(0, 6),
    };
});

const studyPlanAsOnLabel = computed(() => `As on ${formatDisplayDate(todayDate())}`);

const itemHref = (item) => {
    if (! isStudentView.value || ! item.can_open) {
        return null;
    }

    if (item.in_progress_attempt_id && route().has('student.attempts.show')) {
        return route('student.attempts.show', item.in_progress_attempt_id);
    }

    if (item.status === 'done' && item.latest_attempt_id && route().has('student.attempts.show')) {
        return route('student.attempts.show', item.latest_attempt_id);
    }

    if (item.assignment_id && item.delivery_mode === 'written' && route().has('student.written-assignments.show')) {
        return route('student.written-assignments.show', item.assignment_id);
    }

    if (item.assignment_id && route().has('student.assignments.show')) {
        return route('student.assignments.show', item.assignment_id);
    }

    return null;
};

const selfAssign = (item) => {
    if (! isStudentView.value || ! item.worksheet_id || assigningWorksheetId.value) {
        return;
    }

    assigningWorksheetId.value = item.worksheet_id;

    router.post(route('student.worksheets.self-assign', item.worksheet_id), {}, {
        preserveScroll: true,
        onFinish: () => {
            assigningWorksheetId.value = null;
        },
    });
};

const startCorrection = (item) => {
    if (! isStudentView.value || ! item.worksheet_id || assigningWorksheetId.value) {
        return;
    }

    assigningWorksheetId.value = item.worksheet_id;

    router.post(route('student.worksheets.correction-practice', item.worksheet_id), {
        assignment_id: item.assignment_id || null,
    }, {
        preserveScroll: true,
        onFinish: () => {
            assigningWorksheetId.value = null;
        },
    });
};

const startRevision = (item) => {
    if (! isStudentView.value || ! item.worksheet_id || assigningWorksheetId.value) {
        return;
    }

    assigningWorksheetId.value = item.worksheet_id;

    router.post(route('student.worksheets.self-assign', item.worksheet_id), {
        start_revision: 1,
        assignment_id: item.assignment_id || null,
    }, {
        preserveScroll: true,
        onFinish: () => {
            assigningWorksheetId.value = null;
        },
    });
};
</script>

<template>
    <section class="w-full max-w-7xl">
        <h3 class="mb-1 text-base font-semibold text-slate-800">Class coverage & available content</h3>
        <p class="mb-2 text-sm leading-snug text-slate-500">
            Click a chapter to see set details and scores. Tick each chapter independently — click the same box again to clear it.
            <span v-if="canStaffAssign"> Click a set to assign it — target date defaults to today.</span>
        </p>
        <p v-if="upcomingExamByChapterId.size" class="mb-2 text-sm leading-snug text-amber-900">
            Chapters in an upcoming exam have a thin dark-brown line (rose if within 7 days).
        </p>
        <p v-if="saveError" class="mb-2 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-800">
            {{ saveError }}
        </p>

        <ChapterPerformanceSummary
            v-if="studyPlanPerformance"
            class="mb-3"
            :perf="studyPlanPerformance"
            title="Study plan performance"
            :subtitle="studyPlanAsOnLabel"
        />
        <div
            v-else-if="chapters.length"
            class="mb-3 rounded-xl border-2 border-dashed border-indigo-300 bg-indigo-50/60 px-3 py-2.5 text-[11px] font-semibold text-indigo-950"
        >
            Mark chapters as <span class="font-extrabold">Studied</span> or <span class="font-extrabold">Under study</span>
            to see learning and revision completion / score here (as on today).
        </div>

        <div v-if="!chapters.length" class="rounded border border-dashed border-slate-300 px-3 py-3 text-xs text-slate-600">
            No syllabus chapters for your class / board yet.
        </div>

        <div v-else class="overflow-x-auto rounded-lg border-2 border-slate-400 shadow-sm">
            <table class="w-full min-w-[44rem] border-collapse text-[13px] leading-snug">
                <thead>
                    <tr class="bg-[#0b2a5b] text-white">
                        <th class="w-16 px-2 py-1.5 text-left font-semibold whitespace-nowrap">Ch No</th>
                        <th class="min-w-[9rem] max-w-[14rem] px-2 py-1.5 text-left font-semibold">Chapter</th>
                        <th class="min-w-[8rem] max-w-[12rem] px-2 py-1.5 text-left font-semibold">Topics</th>
                        <th class="bg-sky-800 px-2 py-1.5 text-center font-bold whitespace-nowrap" title="Sums attempted / total pool sums">
                            Completion %
                        </th>
                        <th class="bg-violet-800 px-2 py-1.5 text-center font-bold whitespace-nowrap" title="First-try correct sums / total pool sums">
                            Score %
                        </th>
                        <th class="bg-indigo-800 px-2 py-1.5 text-center font-bold whitespace-nowrap" title="Revision completion / score">
                            Revision
                        </th>
                        <th class="px-1.5 py-1.5 text-center font-semibold whitespace-nowrap">Studied</th>
                        <th class="px-1.5 py-1.5 text-center font-semibold whitespace-nowrap">Under study</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="({ chapter, stats }, index) in chaptersWithStats" :key="chapter.id">
                        <tr
                            :class="[
                                upcomingExamForChapter(chapter.id)
                                    ? chapterExamRowClass(chapter.id)
                                    : (index % 2 === 0 ? 'bg-white' : 'bg-slate-100'),
                                isExpanded(chapter.id) && !upcomingExamForChapter(chapter.id) ? 'bg-sky-50' : '',
                                savingId === chapter.id ? 'opacity-60' : '',
                            ]"
                        >
                            <td
                                class="w-16 whitespace-nowrap px-2 py-1 align-middle font-semibold text-slate-900"
                                :class="chapterRowLineClass(chapter.id)"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-left hover:text-sky-800"
                                    :title="chapterTitle(chapter)"
                                    @click="toggleChapter(chapter.id)"
                                >
                                    <span class="shrink-0 text-[10px] text-sky-700">{{ isExpanded(chapter.id) ? '▼' : '▶' }}</span>
                                    <span class="tabular-nums">{{ chapterNumberLabel(chapter) }}</span>
                                </button>
                            </td>
                            <td
                                class="min-w-[9rem] max-w-[14rem] px-2 py-1 align-middle font-medium text-slate-900"
                                :class="chapterRowLineClass(chapter.id)"
                            >
                                <div class="flex min-w-0 items-center gap-1.5">
                                    <span
                                        class="truncate"
                                        :title="chapterNameLabel(chapter)"
                                    >{{ chapterNameLabel(chapter) }}</span>
                                    <span
                                        v-if="upcomingExamForChapter(chapter.id)"
                                        class="shrink-0 rounded border px-1 py-px text-[10px] font-bold leading-none"
                                        :class="chapterExamBadgeClass(chapter.id)"
                                        :title="upcomingExamForChapter(chapter.id).title"
                                    >
                                        {{ examDueLabel(upcomingExamForChapter(chapter.id)) }}
                                    </span>
                                </div>
                            </td>
                            <td
                                class="min-w-[8rem] max-w-[12rem] px-2 py-1 align-middle text-[12px] text-slate-600"
                                :class="chapterRowLineClass(chapter.id)"
                                :title="chapter.topics_label || ''"
                            >
                                <span
                                    v-if="chapter.topics_label"
                                    class="block max-w-full cursor-help truncate whitespace-nowrap"
                                    :title="chapter.topics_label"
                                >{{ truncateTopics(chapter.topics_label, 42) }}</span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td
                                class="bg-sky-50/80 px-1.5 py-1 text-center align-middle"
                                :class="chapterRowLineClass(chapter.id)"
                                :title="stats.total
                                    ? `${stats.done}/${stats.total} sums attempted`
                                    : 'No sums yet'"
                            >
                                <span
                                    class="text-[14px] font-extrabold tabular-nums leading-none"
                                    :class="pctToneClass(stats.completionPct)"
                                >
                                    <template v-if="stats.completionPct != null">{{ stats.completionPct }}%</template>
                                    <template v-else>—</template>
                                </span>
                                <span
                                    v-if="stats.total"
                                    class="ml-0.5 text-[10px] font-semibold tabular-nums text-slate-500"
                                >{{ stats.done }}/{{ stats.total }}</span>
                            </td>
                            <td
                                class="bg-violet-50/80 px-1.5 py-1 text-center align-middle"
                                :class="chapterRowLineClass(chapter.id)"
                                :title="stats.total
                                    ? `${stats.correct ?? 0}/${stats.total} first-try correct`
                                    : 'No sums yet'"
                            >
                                <span
                                    class="text-[14px] font-extrabold tabular-nums leading-none"
                                    :class="pctToneClass(stats.scorePct)"
                                >
                                    <template v-if="stats.scorePct != null">{{ stats.scorePct }}%</template>
                                    <template v-else>—</template>
                                </span>
                                <span
                                    v-if="stats.total && stats.scorePct != null"
                                    class="ml-0.5 text-[10px] font-semibold tabular-nums text-slate-500"
                                >{{ stats.correct ?? 0 }}/{{ stats.total }}</span>
                            </td>
                            <td
                                class="bg-indigo-50/80 px-1.5 py-1 text-center align-middle"
                                :class="chapterRowLineClass(chapter.id)"
                                title="Revision completion % · score %"
                            >
                                <span class="text-[12px] font-extrabold tabular-nums leading-none text-indigo-900">
                                    <template v-if="stats.revisionCompletionPct != null || stats.revisionScorePct != null">
                                        <span :class="pctToneClass(stats.revisionCompletionPct)">
                                            {{ stats.revisionCompletionPct != null ? `${stats.revisionCompletionPct}%` : '—' }}
                                        </span>
                                        <span class="mx-px text-slate-400">·</span>
                                        <span :class="pctToneClass(stats.revisionScorePct)">
                                            {{ stats.revisionScorePct != null ? `${stats.revisionScorePct}%` : '—' }}
                                        </span>
                                    </template>
                                    <template v-else>—</template>
                                </span>
                            </td>
                            <td
                                class="px-1.5 py-1 text-center align-middle"
                                :class="chapterRowLineClass(chapter.id)"
                            >
                                <button
                                    type="button"
                                    class="inline-flex h-5 w-5 items-center justify-center rounded border-2 text-[11px] font-bold leading-none"
                                    :class="chapter.studied
                                        ? 'border-emerald-700 bg-emerald-600 text-white'
                                        : 'border-slate-400 bg-white hover:border-emerald-500'"
                                    :title="chapter.studied ? 'Marked studied — click to undo' : 'Mark as studied'"
                                    :aria-pressed="chapter.studied ? 'true' : 'false'"
                                    :disabled="savingId === chapter.id"
                                    @click.stop="mark(chapter, 'studied')"
                                >
                                    <span v-if="chapter.studied">✓</span>
                                </button>
                            </td>
                            <td
                                class="px-1.5 py-1 text-center align-middle"
                                :class="chapterRowLineClass(chapter.id)"
                            >
                                <button
                                    type="button"
                                    class="inline-flex h-5 w-5 items-center justify-center rounded border-2 text-[11px] font-bold leading-none"
                                    :class="chapter.under_study
                                        ? 'border-amber-600 bg-amber-500 text-white'
                                        : 'border-slate-400 bg-white hover:border-amber-500'"
                                    :title="chapter.under_study ? 'Currently under study — click to undo' : 'Mark as under study'"
                                    :aria-pressed="chapter.under_study ? 'true' : 'false'"
                                    :disabled="savingId === chapter.id"
                                    @click.stop="mark(chapter, 'under_study')"
                                >
                                    <span v-if="chapter.under_study">✓</span>
                                </button>
                            </td>
                        </tr>

                        <tr
                            v-if="isExpanded(chapter.id)"
                            class="bg-slate-100"
                        >
                            <td :colspan="columnCount" class="border-b border-slate-200 border-t border-slate-300 px-3 py-3">
                                <div
                                    v-if="isTierDashboard(chapter) && !hasDashboardContent(chapterDashboard(chapter))"
                                    class="text-[11px] text-slate-500"
                                >
                                    No practice / test content listed for this chapter yet.
                                </div>
                                <div
                                    v-else-if="!isTierDashboard(chapter) && !(chapter.items?.length)"
                                    class="text-[11px] text-slate-500"
                                >
                                    No practice / test content listed for this chapter yet.
                                </div>

                                <div v-else-if="isTierDashboard(chapter)" class="space-y-3">
                                    <div
                                        v-for="book in bookGroups(chapterDashboard(chapter))"
                                        :key="`${chapter.id}-book-${book.id}`"
                                        class="rounded-xl border-2 border-slate-500 bg-white p-3 shadow-md ring-1 ring-slate-200"
                                    >
                                        <p class="text-[11px] font-extrabold uppercase tracking-wide text-slate-900">
                                            {{ book.name }}
                                        </p>
                                        <CoverageItemsWithRevisionRail
                                            :items="book.items"
                                            :revision-items="book.revision_items || []"
                                            group-key="books"
                                            revision-group-key="revisions"
                                            :item-key-prefix="`book-${book.id}`"
                                            :is-student-view="isStudentView"
                                            :can-staff-assign="canStaffAssign"
                                            :assigning-worksheet-id="assigningWorksheetId"
                                            :pending-assign-key="pendingAssignKey"
                                            :staff-assign-form="staffAssignForm"
                                            @self-assign="selfAssign"
                                            @start-correction="startCorrection"
                                            @start-revision="startRevision"
                                            @open-staff-assign="openStaffAssign"
                                            @confirm-staff-assign="confirmStaffAssign"
                                            @cancel-staff-assign="pendingAssignKey = null"
                                        />
                                    </div>

                                    <div
                                        v-if="chapterDashboard(chapter).formula?.items?.length"
                                        class="rounded-xl border-2 border-violet-500 bg-white p-3 shadow-md ring-1 ring-violet-200"
                                    >
                                        <p class="text-[11px] font-extrabold uppercase tracking-wide text-violet-950">Formula</p>
                                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                                            <CoverageSetItemCard
                                                v-for="item in chapterDashboard(chapter).formula.items"
                                                :key="`formula-${item.worksheet_id}`"
                                                :item="item"
                                                group-key="formula"
                                                :is-student-view="isStudentView"
                                                :can-staff-assign="canStaffAssign"
                                                :assigning-worksheet-id="assigningWorksheetId"
                                                :pending-assign-key="pendingAssignKey"
                                                :staff-assign-form="staffAssignForm"
                                                @self-assign="selfAssign"
                                                @start-correction="startCorrection"
                                                @start-revision="startRevision"
                                                @open-staff-assign="openStaffAssign"
                                                @confirm-staff-assign="confirmStaffAssign"
                                                @cancel-staff-assign="pendingAssignKey = null"
                                            />
                                        </div>
                                    </div>

                                    <div
                                        class="grid gap-3"
                                        :class="visibleBlocks(chapterDashboard(chapter)).length >= 3
                                            ? 'lg:grid-cols-3'
                                            : visibleBlocks(chapterDashboard(chapter)).length === 2
                                                ? 'lg:grid-cols-2'
                                                : 'lg:grid-cols-1'"
                                    >
                                        <div
                                            v-for="block in visibleBlocks(chapterDashboard(chapter))"
                                            :key="`${chapter.id}-${block.tier}`"
                                            class="overflow-hidden rounded-xl"
                                            :class="blockShellClass(block.color)"
                                        >
                                            <div
                                                class="px-3 py-2.5 text-center text-xs font-extrabold uppercase tracking-wider"
                                                :class="blockTitleClass(block.color)"
                                            >
                                                {{ block.label }}
                                                <span class="ml-1 font-bold opacity-95">({{ block.item_count || 0 }})</span>
                                            </div>
                                            <div class="space-y-2.5 bg-white p-3">
                                                <div
                                                    v-for="row in block.rows"
                                                    :key="`${block.tier}-${row.key}`"
                                                >
                                                    <p class="text-[10px] font-extrabold uppercase tracking-wide text-slate-800">
                                                        {{ row.label }}
                                                    </p>
                                                    <CoverageItemsWithRevisionRail
                                                        :items="row.items"
                                                        :revision-items="row.revision_items || []"
                                                        :group-key="`${block.tier}:${row.key}`"
                                                        :revision-group-key="`${block.tier}:${row.key}:rev`"
                                                        :item-key-prefix="`${block.tier}-${row.key}`"
                                                        :is-student-view="isStudentView"
                                                        :can-staff-assign="canStaffAssign"
                                                        :assigning-worksheet-id="assigningWorksheetId"
                                                        :pending-assign-key="pendingAssignKey"
                                                        :staff-assign-form="staffAssignForm"
                                                        @self-assign="selfAssign"
                                                        @start-correction="startCorrection"
                                                        @start-revision="startRevision"
                                                        @open-staff-assign="openStaffAssign"
                                                        @confirm-staff-assign="confirmStaffAssign"
                                                        @cancel-staff-assign="pendingAssignKey = null"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div
                                        v-if="otherGroups(chapterDashboard(chapter)).length"
                                        class="rounded-xl border-2 border-slate-700 bg-white p-3 shadow-md ring-1 ring-slate-300"
                                    >
                                        <p class="text-[11px] font-extrabold uppercase tracking-wide text-slate-900">Additional (in chapter)</p>
                                        <div class="mt-2 space-y-2.5">
                                            <div
                                                v-for="group in otherGroups(chapterDashboard(chapter))"
                                                :key="`${chapter.id}-other-${group.id}`"
                                            >
                                                <p class="text-[10px] font-bold tracking-wide text-slate-700">
                                                    {{ group.label }}
                                                </p>
                                                <div class="mt-1 flex flex-wrap gap-1.5">
                                                    <CoverageSetItemCard
                                                        v-for="item in group.items"
                                                        :key="`other-${group.id}-${item.worksheet_id}`"
                                                        :item="item"
                                                        group-key="other"
                                                        :is-student-view="isStudentView"
                                                        :can-staff-assign="canStaffAssign"
                                                        :assigning-worksheet-id="assigningWorksheetId"
                                                        :pending-assign-key="pendingAssignKey"
                                                        :staff-assign-form="staffAssignForm"
                                                        @self-assign="selfAssign"
                                                        @start-correction="startCorrection"
                                                        @start-revision="startRevision"
                                                        @open-staff-assign="openStaffAssign"
                                                        @confirm-staff-assign="confirmStaffAssign"
                                                        @cancel-staff-assign="pendingAssignKey = null"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <template
                                        v-for="perf in [chapterPerformance(chapterDashboard(chapter))]"
                                        :key="`perf-${chapter.id}`"
                                    >
                                        <ChapterPerformanceSummary
                                            v-if="perf"
                                            :perf="perf"
                                            title="Chapter performance"
                                        />
                                    </template>
                                </div>

                                <div v-else class="space-y-2">
                                    <div
                                        v-for="group in chapter.items"
                                        :key="`${chapter.id}-${group.key}`"
                                    >
                                        <p
                                            class="text-[10px] font-bold uppercase tracking-wide"
                                            :class="groupHeaderClass(group)"
                                        >
                                            {{ group.label }}
                                        </p>
                                        <div class="mt-0.5 flex flex-wrap gap-1.5">
                                            <CoverageSetItemCard
                                                v-for="item in group.items"
                                                :key="`${group.key}-${item.worksheet_id}`"
                                                :item="item"
                                                :group-key="group.key"
                                                :is-student-view="isStudentView"
                                                :can-staff-assign="canStaffAssign"
                                                :assigning-worksheet-id="assigningWorksheetId"
                                                :pending-assign-key="pendingAssignKey"
                                                :staff-assign-form="staffAssignForm"
                                                @self-assign="selfAssign"
                                                @start-correction="startCorrection"
                                                @start-revision="startRevision"
                                                @open-staff-assign="openStaffAssign"
                                                @confirm-staff-assign="confirmStaffAssign"
                                                @cancel-staff-assign="pendingAssignKey = null"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div
            v-if="additionalGroups.length"
            class="mt-3 rounded-xl border-2 border-slate-700 bg-white p-3 shadow-md ring-1 ring-slate-300"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-[12px] font-extrabold uppercase tracking-wide text-slate-950">
                    Additional
                </p>
                <p class="text-[11px] font-semibold text-slate-600">
                    Sheets from another class / board / book — move into a chapter, or leave here.
                </p>
            </div>

            <div class="mt-2 space-y-3">
                <div
                    v-for="group in additionalGroups"
                    :key="`additional-${group.id}`"
                >
                    <p class="text-[11px] font-bold tracking-wide text-slate-800">
                        {{ group.label }}
                    </p>
                    <div class="mt-1.5 space-y-2">
                        <div
                            v-for="item in group.items"
                            :key="`additional-item-${item.assignment_id || item.worksheet_id}`"
                            class="flex flex-wrap items-center gap-2 rounded-md border border-slate-300 bg-slate-50 px-2 py-1.5"
                        >
                            <CoverageSetItemCard
                                :item="item"
                                group-key="additional"
                                :is-student-view="isStudentView"
                                :can-staff-assign="canStaffAssign"
                                :assigning-worksheet-id="assigningWorksheetId"
                                :pending-assign-key="pendingAssignKey"
                                :staff-assign-form="staffAssignForm"
                                @self-assign="selfAssign"
                                @start-correction="startCorrection"
                                @start-revision="startRevision"
                                @open-staff-assign="openStaffAssign"
                                @confirm-staff-assign="confirmStaffAssign"
                                @cancel-staff-assign="pendingAssignKey = null"
                            />
                            <template v-if="canMoveChapter && item.assignment_id && item.can_move_chapter !== false">
                                <select
                                    v-model="moveTargets[item.assignment_id]"
                                    class="rounded border-slate-300 px-1.5 py-1 text-[11px] font-semibold text-slate-800"
                                >
                                    <option value="">Move to chapter…</option>
                                    <option
                                        v-for="choice in chapterChoices"
                                        :key="choice.id"
                                        :value="choice.id"
                                    >
                                        {{ choice.label }}
                                    </option>
                                </select>
                                <button
                                    type="button"
                                    class="rounded bg-indigo-700 px-2 py-1 text-[10px] font-bold text-white hover:bg-indigo-800 disabled:opacity-50"
                                    :disabled="movingAssignmentId === item.assignment_id"
                                    @click="moveAdditionalToChapter(item)"
                                >
                                    Move
                                </button>
                                <button
                                    type="button"
                                    class="rounded border border-slate-400 bg-white px-2 py-1 text-[10px] font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-50"
                                    :disabled="movingAssignmentId === item.assignment_id"
                                    @click="leaveInAdditional(item)"
                                >
                                    Leave in Additional
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
