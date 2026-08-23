<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import ClassAttemptProtectionPanel from '@/Components/ClassAttemptProtectionPanel.vue';
import ExamPlanPanel from '@/Components/ExamPlanPanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { assignToClassPath, safeRoute } from '@/utils/routes';

const props = defineProps({
    gradeLevel: Object,
    activeYear: Object,
    syllabusVersion: Object,
    selectedChapterId: [Number, String, null],
    chapters: Array,
    chapterRows: Array,
    stats: Object,
    examFilter: { type: String, default: 'upcoming' },
    examPlanRows: { type: Array, default: () => [] },
    examPlanStats: { type: Object, default: () => ({}) },
    syllabusChapterOptions: { type: Array, default: () => [] },
    examTypeOptions: { type: Array, default: () => [] },
    boardOptions: { type: Array, default: () => [] },
    selectedBoardId: [Number, String, null],
    selectedBoard: { type: Object, default: null },
});

const boardFilter = ref(props.selectedBoardId ? String(props.selectedBoardId) : '');
const chapterFilter = ref(props.selectedChapterId || '');
const examFilter = ref(props.examFilter || 'upcoming');
const editingStudentId = ref(null);
const autoOpenCreate = ref(false);

const isAdmin = computed(() => usePage().props.auth?.isAdmin ?? false);

const reload = () => {
    router.get(route('admin.classes.show', props.gradeLevel.id), {
        board_id: boardFilter.value || undefined,
        syllabus_chapter_id: chapterFilter.value || undefined,
        exam_filter: examFilter.value,
    }, { preserveState: false });
};

const reloadExamFilter = () => {
    router.get(route('admin.classes.show', props.gradeLevel.id), {
        board_id: boardFilter.value || undefined,
        syllabus_chapter_id: chapterFilter.value || undefined,
        exam_filter: examFilter.value,
    }, { preserveState: true, preserveScroll: true });
};

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

const openStudentPlans = (studentId, startCreate = false) => {
    editingStudentId.value = studentId;
    autoOpenCreate.value = startCreate;
};

const closeStudentPlans = () => {
    editingStudentId.value = null;
    autoOpenCreate.value = false;
};

watch(chapterFilter, (id, oldId) => {
    if (id === oldId) {
        return;
    }
    reload();
});

watch(examFilter, (value, oldValue) => {
    if (value === oldValue) {
        return;
    }
    reloadExamFilter();
});

watch(boardFilter, (value, oldValue) => {
    if (value === oldValue) {
        return;
    }
    reload();
});
</script>

<template>
    <Head :title="gradeLevel.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="route('admin.classes.index')" class="text-sm text-indigo-600">← All classes</Link>
                    <h2 class="mt-1 text-xl font-semibold text-gray-800">{{ gradeLevel.name }}</h2>
                    <p v-if="activeYear" class="text-sm text-gray-500">
                        {{ activeYear.name }}<span v-if="selectedBoard"> · {{ selectedBoard.name }} board</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-if="syllabusVersion"
                        :href="route('admin.syllabus.show', syllabusVersion.id)"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        Syllabus
                    </Link>
                    <Link
                        :href="route('admin.questions.classes.show', gradeLevel.id)"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        Questions
                    </Link>
                    <Link
                        v-if="isAdmin"
                        :href="safeRoute('admin.classes.assign', gradeLevel.id, assignToClassPath(gradeLevel.id))"
                        class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-100"
                    >
                        Assign to class
                    </Link>
                    <Link
                        v-if="isAdmin"
                        :href="route('admin.practice-sets.index')"
                        class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        Practice sets
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <BrowseModeNotice />

                <ClassAttemptProtectionPanel v-if="isAdmin" :grade-level="gradeLevel" />

                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.students_count }}</p>
                        <p class="text-xs text-gray-500">Students</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.topics_count }}</p>
                        <p class="text-xs text-gray-500">Topics</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.questions_count }}</p>
                        <p class="text-xs text-gray-500">Questions</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.practice_sets_count }}</p>
                        <p class="text-xs text-gray-500">Sets / tests</p>
                    </div>
                </div>

                <div v-if="activeYear && isAdmin" class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="border-b px-6 py-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="font-medium text-gray-900">Class status &amp; exam plans</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    Completion %, score %, revision, login days, and time spent for each student.
                                    Click a student name to open their study plan. Use <strong>Add exam date</strong> when you need an exam plan.
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <InputLabel value="Show" class="sr-only" />
                                <select
                                    v-model="examFilter"
                                    class="rounded-md border-gray-300 text-sm"
                                >
                                    <option value="upcoming">Next upcoming exam</option>
                                    <option value="past">Most recent past exam</option>
                                    <option value="all">Next or latest past</option>
                                </select>
                            </div>
                        </div>
                        <div v-if="examPlanStats.without_upcoming > 0" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            {{ examPlanStats.without_upcoming }} student(s) have no upcoming exam plan yet.
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Student</th>
                                    <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Plan</th>
                                    <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Exam date</th>
                                    <th class="px-3 py-3 text-center text-xs uppercase text-gray-500" title="Study plan completion">Completion %</th>
                                    <th class="px-3 py-3 text-center text-xs uppercase text-gray-500" title="Average score on scored sets">Score %</th>
                                    <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Revision</th>
                                    <th class="px-3 py-3 text-center text-xs uppercase text-gray-500" title="Login days this academic year">Days logged</th>
                                    <th class="px-3 py-3 text-center text-xs uppercase text-gray-500" title="Approx time on practice this year">Hours spent</th>
                                    <th class="px-3 py-3 text-right text-xs uppercase text-gray-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <template v-for="row in examPlanRows" :key="row.enrollment_id">
                                    <tr :class="!row.has_upcoming && examFilter === 'upcoming' ? 'bg-amber-50/60' : ''">
                                        <td class="px-3 py-3">
                                            <Link
                                                :href="route('admin.school-study-plan.index', { student_id: row.student_id })"
                                                class="font-medium text-indigo-600 hover:underline"
                                                title="Open school study plan"
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
                                            <p
                                                v-if="row.progress?.sets_total"
                                                class="text-[10px] text-gray-500"
                                            >
                                                {{ row.progress.sets_done }}/{{ row.progress.sets_total }} sets
                                            </p>
                                        </td>
                                        <td class="px-3 py-3 text-center font-semibold tabular-nums text-emerald-800">
                                            {{ pctLabel(row.progress?.score_pct) }}
                                        </td>
                                        <td class="px-3 py-3 text-xs" :class="(row.progress?.revision_pending || row.progress?.open_wrongs) ? 'font-semibold text-orange-800' : 'text-emerald-700'">
                                            {{ revisionLabel(row.progress) }}
                                        </td>
                                        <td class="px-3 py-3 text-center font-semibold tabular-nums text-slate-800">
                                            {{ row.progress?.days_logged ?? 0 }}
                                        </td>
                                        <td class="px-3 py-3 text-center">
                                            <span class="font-semibold tabular-nums text-slate-800">{{ row.progress?.time_spent_hours ?? 0 }}h</span>
                                            <p class="text-[10px] text-gray-500">{{ row.progress?.time_spent_label || '—' }}</p>
                                        </td>
                                        <td class="space-x-3 px-3 py-3 text-right">
                                            <button
                                                v-if="!row.has_upcoming"
                                                type="button"
                                                class="text-sm font-medium text-indigo-600 hover:underline"
                                                @click="openStudentPlans(row.student_id, true)"
                                            >
                                                Add exam date
                                            </button>
                                            <button
                                                v-else
                                                type="button"
                                                class="text-sm text-indigo-600 hover:underline"
                                                @click="openStudentPlans(row.student_id, false)"
                                            >
                                                Edit plans
                                            </button>
                                            <button
                                                v-if="editingStudentId === row.student_id"
                                                type="button"
                                                class="text-sm text-gray-500 hover:underline"
                                                @click="closeStudentPlans"
                                            >
                                                Close
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="editingStudentId === row.student_id">
                                        <td colspan="9" class="bg-indigo-50/40 px-4 py-4">
                                            <ExamPlanPanel
                                                :plans="row.all_plans"
                                                :syllabus-chapters="syllabusChapterOptions"
                                                :exam-type-options="examTypeOptions"
                                                :student-id="row.student_id"
                                                :auto-open-create="autoOpenCreate"
                                                context="admin"
                                                compact
                                            />
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="examPlanRows.length === 0">
                                    <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                        No active students in this class for the current year.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="!syllabusVersion" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    No syllabus imported for {{ gradeLevel.name }} yet.
                    <Link :href="route('admin.syllabus.index')" class="font-medium text-indigo-600">Import syllabus</Link>
                </div>

                <div v-else class="space-y-4">
                    <div class="rounded-lg bg-white p-4 shadow-sm space-y-4">
                        <div class="flex flex-wrap items-end gap-4">
                            <div v-if="boardOptions.length" class="min-w-[200px]">
                                <InputLabel value="Board" />
                                <select v-model="boardFilter" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                    <option v-for="board in boardOptions" :key="board.id" :value="String(board.id)">
                                        {{ board.name }} · {{ board.students_count }} student{{ board.students_count === 1 ? '' : 's' }}
                                    </option>
                                </select>
                            </div>
                            <div class="min-w-[220px]">
                                <InputLabel value="Chapter" />
                                <select v-model="chapterFilter" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                    <option value="">All chapters</option>
                                    <option v-for="ch in chapters" :key="ch.id" :value="ch.id">{{ ch.label }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div class="border-b px-4 py-3">
                            <h3 class="font-medium text-gray-900">Chapter summary</h3>
                            <p class="mt-0.5 text-sm text-gray-500">Counts per chapter — open question bank or chapter tests when needed.</p>
                        </div>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapter</th>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Topics</th>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Questions</th>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Topic sets</th>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapter tests</th>
                                    <th class="px-4 py-3 text-right text-xs uppercase text-gray-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="chapter in chapterRows" :key="chapter.id">
                                    <td class="px-4 py-3 font-medium text-gray-900">
                                        Ch {{ chapter.chapter_number }} — {{ chapter.name }}
                                    </td>
                                    <td class="px-4 py-3">{{ chapter.topics_count }}</td>
                                    <td class="px-4 py-3">{{ chapter.questions_count }}</td>
                                    <td class="px-4 py-3">{{ chapter.topic_sets_count }}</td>
                                    <td class="px-4 py-3">{{ chapter.chapter_tests_count }}</td>
                                    <td class="space-x-3 px-4 py-3 text-right">
                                        <Link
                                            :href="route('admin.questions.chapters.show', chapter.id)"
                                            class="text-indigo-600 hover:underline"
                                        >
                                            Question bank
                                        </Link>
                                        <Link
                                            v-if="isAdmin"
                                            :href="route('admin.practice-sets.chapters.show', chapter.id)"
                                            class="text-indigo-600 hover:underline"
                                        >
                                            Chapter tests
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="chapterRows.length === 0">
                                    <td colspan="6" class="px-4 py-8 text-center text-gray-500">No chapters match this filter.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
