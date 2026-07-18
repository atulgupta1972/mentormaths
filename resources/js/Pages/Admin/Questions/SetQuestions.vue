<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { questionHubChapterUrl, questionHubClassUrl } from '@/utils/questionHub';
import { formatScoreLabel } from '@/utils/scores';

const props = defineProps({
    practiceSet: Object,
    topic: Object,
    questions: Array,
    isChapterTest: { type: Boolean, default: false },
    isFillInBlankSet: { type: Boolean, default: false },
    hintStats: Object,
    topicHintStats: Object,
    assignmentPanel: { type: Object, default: null },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.isAdmin ?? false);
const generating = ref(false);
const overwrite = ref(false);

const defaultTargetDate = () => {
    const d = new Date();
    d.setDate(d.getDate() + 7);
    return d.toISOString().slice(0, 10);
};

const selectedGradeLevelId = ref('');
const selectedStudentIds = ref([]);
const targetDate = ref(defaultTargetDate());
const assignNotes = ref('');

const assignForm = useForm({
    student_ids: [],
    target_date: '',
    notes: '',
});

const deleteSetForm = useForm({});

const destroySet = () => {
    const code = props.practiceSet?.set_code || 'this set';
    const message = `Delete practice set ${code}? Assignments for this set will be removed. Questions stay in the chapter bank until you delete them from the chapter sets page.`;

    if (!window.confirm(message)) {
        return;
    }

    deleteSetForm.delete(route('admin.practice-sets.destroy', props.practiceSet.id));
};

const filteredStudents = computed(() => {
    const students = props.assignmentPanel?.students ?? [];

    if (!selectedGradeLevelId.value) {
        return students;
    }

    return students.filter((student) => String(student.grade_level_id) === String(selectedGradeLevelId.value));
});

const existingByStudentId = computed(() => {
    const map = {};

    (props.assignmentPanel?.existingAssignments ?? []).forEach((row) => {
        map[row.student_id] = row;
    });

    return map;
});

const completedAssignments = computed(() =>
    (props.assignmentPanel?.existingAssignments ?? []).filter(
        (row) => row.assignment_status === 'completed' && row.latest_score != null,
    ),
);

const otherAssignments = computed(() =>
    (props.assignmentPanel?.existingAssignments ?? []).filter(
        (row) => !(row.assignment_status === 'completed' && row.latest_score != null),
    ),
);

const selectAllFiltered = () => {
    selectedStudentIds.value = filteredStudents.value.map((student) => student.id);
};

const clearSelectedStudents = () => {
    selectedStudentIds.value = [];
};

const toggleStudent = (studentId) => {
    const index = selectedStudentIds.value.indexOf(studentId);

    if (index === -1) {
        selectedStudentIds.value.push(studentId);
    } else {
        selectedStudentIds.value.splice(index, 1);
    }
};

watch(selectedGradeLevelId, (value) => {
    if (value) {
        selectAllFiltered();
    } else {
        clearSelectedStudents();
    }
});

const assignSelected = () => {
    assignForm.student_ids = selectedStudentIds.value;
    assignForm.target_date = targetDate.value;
    assignForm.notes = assignNotes.value;
    assignForm.post(route('admin.practice-sets.assign-students', props.practiceSet.id), {
        preserveScroll: true,
    });
};

const formatDate = (value) => {
    if (!value) {
        return '—';
    }

    return new Date(String(value).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const statusLabel = (row) => {
    if (row.assignment_status === 'completed' && row.latest_score != null) {
        const late = row.submission_timing === 'late' ? ' · Delayed' : '';
        return `${row.latest_score_label || formatScoreLabel(row.latest_score, row.latest_max_score)}${late}`;
    }

    if (row.is_overdue) {
        return 'Overdue';
    }

    if (row.assignment_status === 'in_progress') {
        return 'In progress';
    }

    if (row.assignment_status === 'assigned') {
        return 'Assigned';
    }

    return row.assignment_status;
};

const statusClass = (row) => {
    if (row.assignment_status === 'completed' && row.latest_score != null) {
        return row.submission_timing === 'late'
            ? 'bg-amber-100 text-amber-900'
            : 'bg-green-100 text-green-800';
    }

    if (row.is_overdue) {
        return 'bg-red-100 text-red-800';
    }

    if (row.assignment_status === 'in_progress') {
        return 'bg-yellow-100 text-yellow-800';
    }

    return 'bg-blue-100 text-blue-800';
};

const classListUrl = computed(() => questionHubClassUrl(props.topic?.grade_level_id, props.topic?.board_id));
const chapterSetsUrl = computed(() => questionHubChapterUrl(props.topic?.chapter_id));

const generateHints = () => {
    if (generating.value || !props.topic?.id) {
        return;
    }

    const topicMissing = props.topicHintStats?.missing_hint ?? 0;
    const setMissing = props.hintStats?.missing_hint ?? 0;
    const message = overwrite.value
        ? `Replace method hints for all ${props.topicHintStats?.total ?? 0} MCQs in “${props.topic.name}”?`
        : topicMissing > 0
            ? `Generate theory-only hints for ${topicMissing} MCQ${topicMissing === 1 ? '' : 's'} in this topic (${setMissing} missing in this set)?`
            : 'All topic MCQs already have hints. Regenerate using sign-rule patterns anyway?';

    if (!window.confirm(message)) {
        return;
    }

    generating.value = true;
    router.post(route('admin.questions.topics.generate-method-hints', props.topic.id), {
        overwrite: overwrite.value,
        sanitize_explanations: true,
    }, {
        preserveScroll: true,
        onFinish: () => {
            generating.value = false;
        },
    });
};
</script>

<template>
    <Head :title="practiceSet.set_code || 'Practice set'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link
                        v-if="topic"
                        :href="chapterSetsUrl"
                        class="text-sm text-indigo-600"
                    >
                        ← Ch {{ topic.chapter_number }} {{ topic.chapter_name }}
                    </Link>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ topic?.board_code }} {{ topic?.grade_name }}
                        <span v-if="isChapterTest"> · Chapter test (mixed)</span>
                        <span v-else-if="topic"> · {{ topic.name }}</span>
                        <span v-if="topic?.grade_level_id && topic?.board_id">
                            ·
                            <Link :href="classListUrl" class="text-indigo-600 hover:underline">All chapters</Link>
                        </span>
                    </p>
                    <div class="mt-1 flex items-center gap-3">
                        <span class="font-mono text-2xl font-bold tracking-wide text-indigo-600">
                            {{ practiceSet.set_code }}
                        </span>
                        <span class="text-sm text-gray-600">{{ practiceSet.tier_label }} · {{ practiceSet.questions_count }} sums</span>
                        <span
                            v-if="isFillInBlankSet"
                            class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800"
                        >
                            Fill in blank
                        </span>
                    </div>
                    <p v-if="isAdmin && topic?.id && hintStats?.total > 0" class="mt-1 text-xs text-gray-500">
                        Method hints in this set: {{ hintStats.with_hint }}/{{ hintStats.total }}
                        <span v-if="hintStats.missing_hint > 0" class="text-amber-700">
                            · {{ hintStats.missing_hint }} missing
                        </span>
                        <span v-if="topicHintStats && topicHintStats.total > hintStats.total" class="text-gray-400">
                            · {{ topicHintStats.total }} MCQs in topic
                        </span>
                    </p>
                </div>
                <div v-if="isAdmin" class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="route('admin.questions.set-code', { code: practiceSet.set_code })"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        Look up / edit answers
                    </Link>
                    <template v-if="topic?.id && !isChapterTest">
                        <label v-if="hintStats?.total > 0" class="flex items-center gap-2 text-xs text-gray-600">
                            <input v-model="overwrite" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            Replace existing
                        </label>
                        <button
                            v-if="topicHintStats?.total > 0"
                            type="button"
                            class="rounded-md border border-sky-300 bg-sky-50 px-3 py-2 text-sm font-medium text-sky-900 hover:bg-sky-100 disabled:opacity-50"
                            :disabled="generating"
                            @click="generateHints"
                        >
                            {{ generating ? 'Generating…' : 'Generate method hints' }}
                        </button>
                        <Link
                            :href="route('admin.questions.topics.show', topic.id)"
                            class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                        >
                            All topic MCQs
                        </Link>
                    </template>
                    <Link
                        v-if="isChapterTest && topic"
                        :href="route('admin.practice-sets.chapters.show', topic.chapter_id)"
                        class="rounded-md border border-sky-300 bg-sky-50 px-3 py-2 text-sm text-sky-800 hover:bg-sky-100"
                    >
                        Chapter tests & assign
                    </Link>
                    <Link
                        v-else-if="topic?.id"
                        :href="route('admin.practice-sets.topics.show', topic.id)"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        Sets & assign
                    </Link>
                    <Link
                        v-if="topic?.chapter_id"
                        :href="route('admin.questions.chapters.show', topic.chapter_id)"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        Chapter sets
                    </Link>
                    <DangerButton
                        type="button"
                        class="!py-2 !text-xs"
                        :disabled="deleteSetForm.processing"
                        @click="destroySet"
                    >
                        Delete set
                    </DangerButton>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <BrowseModeNotice />

                <div
                    v-if="page.props.flash?.success"
                    class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
                >
                    {{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash?.warning"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    {{ page.props.flash.warning }}
                </div>

                <div
                    v-if="page.props.flash?.error"
                    class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                >
                    {{ page.props.flash.error }}
                </div>

                <p class="text-sm text-gray-600">{{ practiceSet.tier_tagline }}</p>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">#</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Question</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Answer</th>
                                <th v-if="isAdmin && !isFillInBlankSet" class="px-4 py-3 text-left text-xs uppercase text-gray-500">Hint</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Difficulty</th>
                                <th v-if="isAdmin" class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="(q, index) in questions" :key="q.id">
                                <td class="px-4 py-3 text-gray-500">{{ index + 1 }}</td>
                                <td class="px-4 py-3">
                                    <QuestionBody :question-text="q.question_text" :diagram-url="q.diagram_url" :compact="true" />
                                    <p class="mt-1 text-xs text-gray-500">
                                        <span
                                            class="rounded-full px-1.5 py-0.5 font-medium"
                                            :class="q.type === 'fill_in_blank' ? 'bg-emerald-100 text-emerald-800' : 'bg-indigo-100 text-indigo-800'"
                                        >
                                            {{ q.type_label }}
                                        </span>
                                        <span v-if="q.type !== 'fill_in_blank'"> · {{ q.options_count }} options</span>
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <template v-if="q.type === 'fill_in_blank'">
                                        <p class="font-mono font-semibold text-gray-900">{{ q.correct_answer || '—' }}</p>
                                        <p class="text-xs text-gray-500">{{ q.answer_format || '—' }}</p>
                                    </template>
                                    <template v-else>
                                        <p class="font-mono text-sm text-gray-700">{{ q.correct_answer || '—' }}</p>
                                    </template>
                                </td>
                                <td v-if="isAdmin && !isFillInBlankSet" class="px-4 py-3">
                                    <span
                                        v-if="q.method_hint"
                                        class="inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800"
                                        :title="q.method_hint"
                                    >
                                        Yes
                                    </span>
                                    <span v-else class="text-xs text-amber-700">Missing</span>
                                </td>
                                <td class="px-4 py-3">{{ q.difficulty || '—' }}</td>
                                <td v-if="isAdmin" class="px-4 py-3 text-right">
                                    <Link
                                        v-if="q.type === 'fill_in_blank'"
                                        :href="route('admin.questions.set-code', { code: practiceSet.set_code })"
                                        class="text-indigo-600 hover:text-indigo-800"
                                    >
                                        Edit answer
                                    </Link>
                                    <Link v-else :href="route('admin.questions.edit', q.id)" class="text-indigo-600 hover:text-indigo-800">
                                        Edit
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="questions.length === 0">
                                <td :colspan="isAdmin ? (isFillInBlankSet ? 4 : 5) : 3" class="px-4 py-8 text-center text-gray-500">No questions in this set.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <section
                    v-if="isAdmin && assignmentPanel"
                    class="overflow-hidden rounded-xl border border-indigo-200 bg-white shadow-sm"
                >
                    <div class="border-b border-indigo-100 bg-indigo-50/60 px-5 py-4">
                        <h3 class="text-base font-semibold text-indigo-900">Assign this set</h3>
                        <p class="mt-1 text-sm text-indigo-800">
                            Review who has already done {{ practiceSet.set_code }}, then assign to one or more students.
                            Re-assigning lets them attempt again — previous scores are kept.
                        </p>
                    </div>

                    <div class="space-y-6 p-5">
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900">Already assigned / completed</h4>
                                    <p class="mt-1 text-xs text-gray-600">
                                        {{ assignmentPanel.existingAssignments.length }} student{{ assignmentPanel.existingAssignments.length === 1 ? '' : 's' }}
                                        <span v-if="assignmentPanel.activeYear"> · {{ assignmentPanel.activeYear.name }}</span>
                                    </p>
                                </div>
                            </div>

                            <p v-if="!assignmentPanel.existingAssignments.length" class="mt-3 text-sm text-gray-500">
                                No one has been assigned this set yet.
                            </p>

                            <div v-else class="mt-4 space-y-4">
                                <div v-if="completedAssignments.length">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Completed</p>
                                    <div class="mt-2 overflow-x-auto rounded-lg border border-gray-200 bg-white">
                                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Student</th>
                                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Class</th>
                                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Score</th>
                                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Target</th>
                                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Submitted</th>
                                                    <th class="px-3 py-2"></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <tr v-for="row in completedAssignments" :key="row.assignment_id">
                                                    <td class="px-3 py-2 font-medium text-gray-900">{{ row.student_name }}</td>
                                                    <td class="px-3 py-2 text-gray-600">{{ row.class_name }}</td>
                                                    <td class="px-3 py-2">
                                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(row)">
                                                            {{ statusLabel(row) }}
                                                        </span>
                                                    </td>
                                                    <td class="px-3 py-2 text-gray-600">{{ formatDate(row.target_date) }}</td>
                                                    <td class="px-3 py-2 text-gray-600">{{ formatDate(row.submitted_at) }}</td>
                                                    <td class="px-3 py-2 text-right">
                                                        <Link
                                                            :href="route('admin.set-assignments.show', row.assignment_id)"
                                                            class="text-indigo-600 hover:underline"
                                                        >
                                                            History
                                                        </Link>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div v-if="otherAssignments.length">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Assigned / in progress</p>
                                    <div class="mt-2 overflow-x-auto rounded-lg border border-gray-200 bg-white">
                                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Student</th>
                                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Class</th>
                                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Status</th>
                                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Target</th>
                                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Attempts</th>
                                                    <th class="px-3 py-2"></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <tr v-for="row in otherAssignments" :key="row.assignment_id">
                                                    <td class="px-3 py-2 font-medium text-gray-900">{{ row.student_name }}</td>
                                                    <td class="px-3 py-2 text-gray-600">{{ row.class_name }}</td>
                                                    <td class="px-3 py-2">
                                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(row)">
                                                            {{ statusLabel(row) }}
                                                        </span>
                                                    </td>
                                                    <td class="px-3 py-2 text-gray-600">{{ formatDate(row.target_date) }}</td>
                                                    <td class="px-3 py-2 text-gray-600">{{ row.attempt_count }}</td>
                                                    <td class="px-3 py-2 text-right">
                                                        <Link
                                                            :href="route('admin.set-assignments.show', row.assignment_id)"
                                                            class="text-indigo-600 hover:underline"
                                                        >
                                                            History
                                                        </Link>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-6 lg:grid-cols-3">
                            <div class="space-y-4 lg:col-span-2">
                                <div class="flex flex-wrap items-end gap-4">
                                    <div>
                                        <InputLabel value="Class" />
                                        <select
                                            v-model="selectedGradeLevelId"
                                            class="mt-1 rounded-md border-gray-300 text-sm"
                                        >
                                            <option value="">All classes</option>
                                            <option
                                                v-for="grade in assignmentPanel.gradeLevels"
                                                :key="grade.id"
                                                :value="grade.id"
                                            >
                                                {{ grade.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="selectAllFiltered">
                                            Select all ({{ filteredStudents.length }})
                                        </SecondaryButton>
                                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="clearSelectedStudents">
                                            Clear
                                        </SecondaryButton>
                                    </div>
                                </div>

                                <p class="text-xs text-gray-500">
                                    {{ selectedGradeLevelId ? 'Showing students in the selected class.' : 'Showing all active students across classes.' }}
                                    Selected: {{ selectedStudentIds.length }}
                                </p>

                                <div
                                    v-if="!filteredStudents.length"
                                    class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500"
                                >
                                    No active students found for this filter.
                                </div>

                                <div
                                    v-else
                                    class="max-h-72 overflow-y-auto rounded-lg border border-gray-200 divide-y divide-gray-100"
                                >
                                    <label
                                        v-for="student in filteredStudents"
                                        :key="student.id"
                                        class="flex cursor-pointer items-start gap-3 px-4 py-3 hover:bg-gray-50"
                                    >
                                        <input
                                            type="checkbox"
                                            class="mt-0.5 rounded border-gray-300 text-indigo-600"
                                            :checked="selectedStudentIds.includes(student.id)"
                                            @change="toggleStudent(student.id)"
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-medium text-gray-900">{{ student.name }}</span>
                                            <span class="mt-0.5 block text-xs text-gray-500">
                                                {{ student.class_name }}
                                                <span v-if="student.board_code"> · {{ student.board_code }}</span>
                                            </span>
                                            <span
                                                v-if="existingByStudentId[student.id]"
                                                class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                                :class="statusClass(existingByStudentId[student.id])"
                                            >
                                                Already: {{ statusLabel(existingByStudentId[student.id]) }}
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <aside class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-4">
                                <h4 class="text-sm font-semibold text-emerald-900">Assign selected</h4>
                                <p class="mt-1 text-xs text-emerald-800">
                                    Students who already have this set will be re-assigned with a new target date.
                                </p>

                                <div class="mt-4 space-y-3">
                                    <div>
                                        <InputLabel value="Target date" />
                                        <input
                                            v-model="targetDate"
                                            type="date"
                                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                        />
                                    </div>
                                    <div>
                                        <InputLabel value="Note (optional)" />
                                        <textarea
                                            v-model="assignNotes"
                                            rows="2"
                                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                            placeholder="e.g. Complete before school test"
                                        />
                                    </div>
                                </div>

                                <div class="mt-4 rounded-lg bg-white/80 p-3 text-sm text-gray-700">
                                    <p><span class="font-medium">{{ selectedStudentIds.length }}</span> student{{ selectedStudentIds.length === 1 ? '' : 's' }} selected</p>
                                    <p class="mt-1">{{ practiceSet.questions_count }} questions in set</p>
                                </div>

                                <PrimaryButton
                                    type="button"
                                    class="mt-4 w-full justify-center"
                                    :disabled="assignForm.processing || !selectedStudentIds.length || !targetDate"
                                    @click="assignSelected"
                                >
                                    {{ assignForm.processing ? 'Assigning…' : 'Assign set' }}
                                </PrimaryButton>
                            </aside>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
