<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import ClassSetStatusPanel from '@/Components/ClassSetStatusPanel.vue';
import ExamPlanPanel from '@/Components/ExamPlanPanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { assignToClassPath, safeRoute } from '@/utils/routes';

const props = defineProps({
    gradeLevel: Object,
    activeYear: Object,
    syllabusVersion: Object,
    view: { type: String, default: 'topic' },
    selectedChapterId: [Number, String, null],
    selectedTopicId: [Number, String, null],
    chapters: Array,
    chapterTopics: Array,
    chapterRows: Array,
    topics: Array,
    stats: Object,
    examFilter: { type: String, default: 'upcoming' },
    examPlanRows: { type: Array, default: () => [] },
    examPlanStats: { type: Object, default: () => ({}) },
    syllabusChapterOptions: { type: Array, default: () => [] },
    examTypeOptions: { type: Array, default: () => [] },
    setStatusBoard: {
        type: Object,
        default: () => ({ students: [], chapters: [] }),
    },
    classStudents: { type: Array, default: () => [] },
    boardOptions: { type: Array, default: () => [] },
    selectedBoardId: [Number, String, null],
    selectedBoard: { type: Object, default: null },
});

const viewMode = ref(props.view || 'sets');
const boardFilter = ref(props.selectedBoardId ? String(props.selectedBoardId) : '');
const chapterFilter = ref(props.selectedChapterId || '');
const topicFilter = ref(props.selectedTopicId || '');
const examFilter = ref(props.examFilter || 'upcoming');
const editingStudentId = ref(null);
const autoOpenCreate = ref(false);

const isChapterView = computed(() => viewMode.value === 'chapter');
const isSetsView = computed(() => viewMode.value === 'sets');
const isAdmin = computed(() => usePage().props.auth?.isAdmin ?? false);

const classMatrixLabel = computed(() => {
    const parts = [props.gradeLevel?.name];

    if (props.selectedBoard?.code) {
        parts.push(props.selectedBoard.code);
    }

    return parts.join(' · ');
});

const reload = () => {
    const params = {
        view: viewMode.value,
        board_id: boardFilter.value || undefined,
        syllabus_chapter_id: chapterFilter.value || undefined,
        exam_filter: examFilter.value,
    };

    if (!isChapterView.value && !isSetsView.value) {
        params.syllabus_topic_id = topicFilter.value || undefined;
    }

    router.get(route('admin.classes.show', props.gradeLevel.id), params, { preserveState: false });
};

const reloadExamFilter = () => {
    router.get(route('admin.classes.show', props.gradeLevel.id), {
        view: viewMode.value,
        board_id: boardFilter.value || undefined,
        syllabus_chapter_id: chapterFilter.value || undefined,
        syllabus_topic_id: topicFilter.value || undefined,
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

const chapterSummary = (plan) => {
    if (!plan) {
        return '—';
    }

    if (plan.chapter_names?.length) {
        return plan.chapter_names.join(', ');
    }

    return plan.chapters?.map((ch) => ch.label || ch.name).join(', ') || '—';
};

const openStudentPlans = (studentId, startCreate = false) => {
    editingStudentId.value = studentId;
    autoOpenCreate.value = startCreate;
};

const closeStudentPlans = () => {
    editingStudentId.value = null;
    autoOpenCreate.value = false;
};

watch(viewMode, () => {
    if (isChapterView.value || isSetsView.value) {
        topicFilter.value = '';
    }
    reload();
});

watch(chapterFilter, (id, oldId) => {
    if (id === oldId) {
        return;
    }
    topicFilter.value = '';
    reload();
});

watch(topicFilter, (id, oldId) => {
    if (isChapterView.value || isSetsView.value || id === oldId) {
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
                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.students_count }}</p>
                        <p class="text-xs text-gray-500">Students</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.topics_count }}</p>
                        <p class="text-xs text-gray-500">{{ isChapterView ? 'Topics (in view)' : 'Topics' }}</p>
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
                                <h3 class="font-medium text-gray-900">Exam plans</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    You or the student can add dates. Use <strong>Add exam date</strong> to enter a plan for any student.
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

                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Student</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Plan</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Exam date</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapters</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Prep</th>
                                <th class="px-4 py-3 text-right text-xs uppercase text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <template v-for="row in examPlanRows" :key="row.enrollment_id">
                                <tr :class="!row.has_upcoming && examFilter === 'upcoming' ? 'bg-amber-50/60' : ''">
                                    <td class="px-4 py-3">
                                        <Link
                                            :href="route('admin.students.show', row.student_id)"
                                            class="font-medium text-indigo-600 hover:underline"
                                        >
                                            {{ row.student_name }}
                                        </Link>
                                    </td>
                                    <td class="px-4 py-3">
                                        <template v-if="row.display_plan">
                                            <p class="font-medium text-gray-900">{{ row.display_plan.title }}</p>
                                            <p class="text-xs text-gray-500">{{ row.display_plan.exam_type_label }}</p>
                                        </template>
                                        <span v-else class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                            No plan
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        {{ formatDate(row.display_plan?.exam_date) }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">
                                        {{ chapterSummary(row.display_plan) }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">
                                        <template v-if="row.display_plan?.prep_assignments?.length">
                                            <span class="text-xs text-gray-500">
                                                {{ row.display_plan.prep_summary?.completed }}/{{ row.display_plan.prep_summary?.total }} done
                                            </span>
                                            <p class="mt-1 text-xs">
                                                {{ row.display_plan.prep_assignments.map((p) => p.set_code).join(', ') }}
                                            </p>
                                        </template>
                                        <span v-else class="text-xs text-gray-400">—</span>
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-3">
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
                                    <td colspan="6" class="bg-indigo-50/40 px-4 py-4">
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
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                    No active students in this class for the current year.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="!syllabusVersion" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    No syllabus imported for {{ gradeLevel.name }} yet.
                    <Link :href="route('admin.syllabus.index')" class="font-medium text-indigo-600">Import syllabus</Link>
                </div>

                <div v-else class="rounded-lg bg-white p-4 shadow-sm space-y-4">
                    <div v-if="boardOptions.length" class="flex flex-wrap items-end gap-4 rounded-lg border border-indigo-100 bg-indigo-50/40 p-3">
                        <div class="min-w-[200px]">
                            <InputLabel value="Board" />
                            <select v-model="boardFilter" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option v-for="board in boardOptions" :key="board.id" :value="String(board.id)">
                                    {{ board.name }} · {{ board.students_count }} student{{ board.students_count === 1 ? '' : 's' }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Syllabus and students are filtered by board — CBSE and ICSE are separate.</p>
                        </div>
                    </div>

                    <div>
                        <InputLabel value="View" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-3">
                            <button
                                type="button"
                                class="rounded-lg border p-3 text-left text-sm transition"
                                :class="viewMode === 'sets'
                                    ? 'border-violet-500 bg-violet-50 ring-1 ring-violet-500'
                                    : 'border-gray-200 hover:border-gray-300'"
                                @click="viewMode = 'sets'"
                            >
                                <p class="font-medium text-gray-900">Practice & tests</p>
                                <p class="mt-0.5 text-xs text-gray-500">Chapter-wise sheets, student scores, assign here</p>
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border p-3 text-left text-sm transition"
                                :class="viewMode === 'topic'
                                    ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
                                    : 'border-gray-200 hover:border-gray-300'"
                                @click="viewMode = 'topic'"
                            >
                                <p class="font-medium text-gray-900">Topic wise</p>
                                <p class="mt-0.5 text-xs text-gray-500">List topics — filter by chapter and topic</p>
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border p-3 text-left text-sm transition"
                                :class="isChapterView
                                    ? 'border-sky-500 bg-sky-50 ring-1 ring-sky-500'
                                    : 'border-gray-200 hover:border-gray-300'"
                                @click="viewMode = 'chapter'"
                            >
                                <p class="font-medium text-gray-900">Chapter summary</p>
                                <p class="mt-0.5 text-xs text-gray-500">Counts per chapter — links to question bank</p>
                            </button>
                        </div>
                    </div>

                    <div v-if="!isSetsView" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Chapter" />
                            <select v-model="chapterFilter" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option value="">All chapters</option>
                                <option v-for="ch in chapters" :key="ch.id" :value="ch.id">{{ ch.label }}</option>
                            </select>
                        </div>
                        <div v-if="viewMode === 'topic'">
                            <InputLabel value="Topic (optional)" />
                            <select
                                v-model="topicFilter"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                :disabled="!chapterFilter"
                            >
                                <option value="">All topics{{ chapterFilter ? ' in chapter' : '' }}</option>
                                <option v-for="t in chapterTopics" :key="t.id" :value="t.id">
                                    {{ t.name }} ({{ t.questions_count }} Q)
                                </option>
                            </select>
                            <p v-if="!chapterFilter" class="mt-1 text-xs text-gray-500">Select a chapter first to filter by topic.</p>
                        </div>
                    </div>
                </div>

                <!-- Practice & tests — assign + student status -->
                <ClassSetStatusPanel
                    v-if="isSetsView && syllabusVersion"
                    :chapters="setStatusBoard.chapters"
                    :students="classStudents"
                    :grade-level-id="gradeLevel.id"
                    :grade-level-name="classMatrixLabel"
                    :board-id="selectedBoardId"
                    :can-assign="isAdmin"
                />

                <div v-else-if="isSetsView && !syllabusVersion && boardOptions.length" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    No syllabus imported for {{ gradeLevel.name }} · {{ selectedBoard?.name || 'this board' }} yet.
                    <Link :href="route('admin.syllabus.index')" class="font-medium text-indigo-600">Import syllabus</Link>
                </div>

                <!-- Chapter wise table -->
                <div v-if="isChapterView && syllabusVersion" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
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
                                <td class="px-4 py-3 text-right space-x-3">
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

                <!-- Topic wise table -->
                <div v-if="!isChapterView && syllabusVersion" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapter</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Topic</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Questions</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Sets</th>
                                <th class="px-4 py-3 text-right text-xs uppercase text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="topic in topics" :key="topic.id">
                                <td class="px-4 py-3 text-gray-600">
                                    {{ topic.chapter_number }} {{ topic.chapter_name }}
                                </td>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ topic.name }}</td>
                                <td class="px-4 py-3">{{ topic.questions_count }}</td>
                                <td class="px-4 py-3">{{ topic.practice_sets_count }}</td>
                                <td class="px-4 py-3 text-right space-x-3">
                                    <Link
                                        :href="route('admin.questions.topics.show', topic.id)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        View bank
                                    </Link>
                                    <template v-if="isAdmin">
                                        <Link
                                            :href="route('admin.questions.create', {
                                                syllabus_chapter_id: topic.chapter_id,
                                                syllabus_topic_id: topic.id,
                                            })"
                                            class="text-indigo-600 hover:underline"
                                        >
                                            Add MCQs
                                        </Link>
                                        <Link
                                            :href="route('admin.practice-sets.topics.show', topic.id)"
                                            class="text-indigo-600 hover:underline"
                                        >
                                            Sets & assign
                                        </Link>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="topics.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                    No topics match this filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
