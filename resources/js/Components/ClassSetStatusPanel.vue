<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatScoreLabel } from '@/utils/scores';

const props = defineProps({
    chapters: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
    gradeLevelId: { type: Number, required: true },
    gradeLevelName: { type: String, default: '' },
    boardId: { type: [Number, String, null], default: null },
    canAssign: { type: Boolean, default: true },
});

const page = usePage();

const defaultTargetDate = () => {
    const d = new Date();
    d.setDate(d.getDate() + 3);

    return d.toISOString().slice(0, 10);
};

const quickAssignDate = () => defaultTargetDate();

const selectedChapterId = ref('');
const targetDate = ref(defaultTargetDate());
const assignStudentBySet = ref({});
const selectedMonth = ref('');
const showPractice = ref(true);
const showTests = ref(true);
const showCatchUp = ref(true);

const assignForm = useForm({ student_id: '', target_date: '', notes: '' });
const bulkForm = useForm({ grade_level_id: '', board_id: '', target_date: '', notes: '' });
const reassignForm = useForm({ target_date: '', notes: '' });
const deassignForm = useForm({});

const statusLegend = [
    { label: 'Done', boxClass: 'bg-emerald-100 text-emerald-800 border-emerald-200' },
    { label: 'In progress', boxClass: 'bg-amber-100 text-amber-900 border-amber-200' },
    { label: 'Not done', boxClass: 'bg-rose-100 text-rose-800 border-rose-200' },
    { label: 'Not assigned', boxClass: 'bg-slate-100 text-slate-400 border-slate-200' },
];

const syllabusChapters = computed(() => props.chapters.filter((chapter) => !chapter.is_extra));

const showAllChapters = computed(() => !selectedChapterId.value);

const monthOptions = computed(() => {
    const months = new Map();

    props.chapters.forEach((chapter) => {
        chapter.sets?.forEach((set) => {
            set.students?.forEach((row) => {
                const target = row.progress?.target_date;

                if (!target) {
                    return;
                }

                const key = target.slice(0, 7);
                const label = new Date(`${key}-01T00:00:00`).toLocaleDateString(undefined, {
                    month: 'long',
                    year: 'numeric',
                });
                months.set(key, label);
            });
        });
    });

    return [...months.entries()]
        .sort(([left], [right]) => right.localeCompare(left))
        .map(([value, label]) => ({ value, label }));
});

const setMatchesTypeFilter = (set) => {
    if (set.is_catch_up) {
        return showCatchUp.value;
    }

    if (set.kind_label === 'Test') {
        return showTests.value;
    }

    return showPractice.value;
};

const setMatchesMonthFilter = (set) => {
    if (!selectedMonth.value) {
        return true;
    }

    return (set.students ?? []).some((row) => row.progress?.target_date?.startsWith(selectedMonth.value));
};

const filterSets = (sets) => (sets ?? []).filter((set) => setMatchesTypeFilter(set) && setMatchesMonthFilter(set));

const visibleChapters = computed(() => {
    let chapters = props.chapters;

    if (selectedChapterId.value) {
        chapters = chapters.filter(
            (chapter) => String(chapter.chapter_id) === String(selectedChapterId.value),
        );
    }

    return chapters
        .map((chapter) => ({
            ...chapter,
            sets: filterSets(chapter.sets),
        }))
        .filter((chapter) => (chapter.sets?.length ?? 0) > 0);
});

const hasVisibleSets = computed(() =>
    visibleChapters.value.some((chapter) => (chapter.sets?.length ?? 0) > 0),
);

const visibleSetCount = computed(() =>
    visibleChapters.value.reduce((sum, chapter) => sum + (chapter.sets?.length ?? 0), 0),
);

const studentRow = (set, studentId) =>
    set.students?.find((row) => row.student_id === studentId) ?? null;

const isNotAssigned = (progress) => !progress?.assignment_id;

const canDeassignProgress = (progress) =>
    progress?.assignment_id
    && ['assigned', 'in_progress'].includes(progress.assignment_status);

const cellStatus = (progress) => {
    const box = 'inline-flex min-h-[22px] min-w-[76px] items-center justify-center rounded border px-1.5 py-0.5 text-[10px] font-semibold leading-tight';

    if (isNotAssigned(progress)) {
        return {
            label: 'Not assigned',
            boxClass: `${box} bg-slate-100 text-slate-500 border-slate-200`,
            title: 'Click to assign (3-day target)',
        };
    }

    if (progress.assignment_status === 'completed') {
        if (progress.latest_score != null) {
            const late = progress.submission_timing === 'late';

            return {
                label: late
                    ? `${progress.latest_score_label || formatScoreLabel(progress.latest_score, progress.latest_max_score)} late`
                    : (progress.latest_score_label || formatScoreLabel(progress.latest_score, progress.latest_max_score)),
                boxClass: late
                    ? `${box} bg-amber-100 text-amber-900 border-amber-300`
                    : `${box} bg-emerald-100 text-emerald-800 border-emerald-300`,
                title: late ? 'Completed (delayed)' : 'Completed',
            };
        }

        return {
            label: 'Done',
            boxClass: `${box} bg-emerald-100 text-emerald-800 border-emerald-300`,
            title: 'Completed',
        };
    }

    if (progress.is_overdue) {
        return { label: 'Not done', boxClass: `${box} bg-red-100 text-red-800 border-red-300`, title: 'Overdue' };
    }

    if (progress.assignment_status === 'in_progress') {
        return { label: 'In progress', boxClass: `${box} bg-amber-100 text-amber-900 border-amber-300`, title: 'Started, not submitted' };
    }

    if (progress.assignment_status === 'assigned') {
        return {
            label: 'Not done',
            boxClass: `${box} bg-rose-100 text-rose-800 border-rose-300`,
            title: 'Assigned, not submitted yet',
        };
    }

    return {
        label: 'Not assigned',
        boxClass: `${box} bg-slate-100 text-slate-500 border-slate-200`,
        title: 'Click to assign (3-day target)',
    };
};

const classDoneClass = (set) => {
    const box = 'inline-flex rounded border px-1.5 py-0.5 text-[10px] font-semibold';
    const allDone = set.completed_count === props.students.length && props.students.length > 0;
    const someDone = set.completed_count > 0;

    if (allDone) {
        return `${box} bg-emerald-50 text-emerald-700 border-emerald-200`;
    }

    if (someDone) {
        return `${box} bg-sky-50 text-sky-700 border-sky-200`;
    }

    return `${box} bg-slate-50 text-slate-500 border-slate-200`;
};

const assignActionLabel = (set) => {
    const studentId = assignStudentBySet.value[set.id];

    if (!studentId) {
        return 'Assign';
    }

    const progress = studentRow(set, Number(studentId))?.progress;

    if (progress?.assignment_id) {
        return 'Re-assign';
    }

    return 'Assign';
};

const selectedStudentProgress = (set) => {
    const studentId = assignStudentBySet.value[set.id];

    if (!studentId) {
        return null;
    }

    return studentRow(set, Number(studentId))?.progress ?? null;
};

const assignSet = (setId, studentId = null) => {
    const chosenStudentId = studentId || assignStudentBySet.value[setId];

    if (!chosenStudentId) {
        return;
    }

    assignForm.student_id = chosenStudentId;
    assignForm.target_date = targetDate.value;
    assignForm.post(route('admin.practice-sets.assign', setId), { preserveScroll: true });
};

const reassign = (assignmentId) => {
    if (!confirm('Re-assign this set? Previous scores are kept. Student can attempt again.')) {
        return;
    }

    reassignForm.target_date = targetDate.value;
    reassignForm.post(route('admin.set-assignments.reassign', assignmentId), { preserveScroll: true });
};

const deassign = (assignmentId, setCode, studentName) => {
    if (!confirm(`Remove ${setCode} for ${studentName}? The student will no longer see this assignment.`)) {
        return;
    }

    deassignForm.delete(route('admin.set-assignments.destroy', assignmentId), { preserveScroll: true });
};

const assignOrReassign = (set) => {
    const studentId = assignStudentBySet.value[set.id];

    if (!studentId) {
        return;
    }

    const progress = studentRow(set, Number(studentId))?.progress;

    if (progress?.assignment_id) {
        reassign(progress.assignment_id);
        return;
    }

    assignSet(set.id, studentId);
};

const assignBulk = (setId) => {
    bulkForm.grade_level_id = String(props.gradeLevelId);
    bulkForm.board_id = props.boardId ? String(props.boardId) : '';
    bulkForm.target_date = targetDate.value;
    bulkForm.post(route('admin.practice-sets.assign-bulk', setId), { preserveScroll: true });
};

const onCellClick = (set, studentId) => {
    if (!props.canAssign) {
        return;
    }

    const progress = studentRow(set, studentId)?.progress;
    assignStudentBySet.value[set.id] = String(studentId);

    if (isNotAssigned(progress)) {
        assignForm.student_id = String(studentId);
        assignForm.target_date = quickAssignDate();
        assignForm.post(route('admin.practice-sets.assign', set.id), { preserveScroll: true });
        return;
    }

    assignOrReassign(set);
};

const setMeta = (set) => {
    const parts = [set.kind_label];

    if (set.is_cross_grade && set.sheet_grade_name) {
        parts.push(set.sheet_grade_name);
    }

    if (set.topic_name) {
        parts.push(set.topic_name);
    }

    return parts.join(' · ');
};

const chapterRowClass = (chapter) => (chapter.is_extra ? 'bg-violet-50/70' : '');
</script>

<template>
    <div class="space-y-3">
        <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 px-3 py-2 text-xs text-green-800">
            {{ page.props.flash.success }}
        </div>
        <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-800">
            {{ page.props.flash.error }}
        </div>

        <div class="rounded-lg border border-gray-200 bg-white px-3 py-2 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <div class="flex items-center gap-2">
                        <InputLabel value="Chapter" class="!mb-0 shrink-0 !text-xs" />
                        <select
                            v-model="selectedChapterId"
                            class="rounded-md border-gray-300 py-1 text-xs"
                        >
                            <option value="">All chapters · {{ syllabusChapters.length }} total</option>
                            <option v-for="chapter in syllabusChapters" :key="chapter.chapter_id" :value="String(chapter.chapter_id)">
                                {{ chapter.chapter_label }} · {{ chapter.sets?.length ?? 0 }} sheets
                            </option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <InputLabel value="Month" class="!mb-0 shrink-0 !text-xs" />
                        <select v-model="selectedMonth" class="rounded-md border-gray-300 py-1 text-xs">
                            <option value="">All months</option>
                            <option v-for="month in monthOptions" :key="month.value" :value="month.value">
                                {{ month.label }}
                            </option>
                        </select>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <InputLabel value="Show" class="!mb-0 shrink-0 !text-xs" />
                        <label class="inline-flex items-center gap-1 text-[10px] text-gray-700">
                            <input v-model="showPractice" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            Practice
                        </label>
                        <label class="inline-flex items-center gap-1 text-[10px] text-gray-700">
                            <input v-model="showTests" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            Tests
                        </label>
                        <label class="inline-flex items-center gap-1 text-[10px] text-gray-700">
                            <input v-model="showCatchUp" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            Correction sets
                        </label>
                    </div>
                    <div v-if="canAssign" class="flex items-center gap-2">
                        <InputLabel value="Target date" class="!mb-0 shrink-0 !text-xs" />
                        <input v-model="targetDate" type="date" class="rounded-md border-gray-300 py-1 text-xs" />
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span
                            v-for="item in statusLegend"
                            :key="item.label"
                            :class="item.boxClass"
                            class="inline-flex rounded border px-1.5 py-0.5 text-[10px] font-semibold"
                        >
                            {{ item.label }}
                        </span>
                    </div>
                </div>
                <p v-if="hasVisibleSets" class="text-[10px] text-gray-500">
                    {{ visibleSetCount }} sheet{{ visibleSetCount === 1 ? '' : 's' }}
                    {{ showAllChapters ? '· all chapters' : '· filtered' }}
                </p>
            </div>
        </div>

        <div v-if="!chapters.length" class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-center text-xs text-gray-500">
            No published practice sets or tests for this class yet.
        </div>

        <div v-else-if="!hasVisibleSets" class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-center text-xs text-gray-500">
            No sheets match the current filters.
        </div>

        <div v-else class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full border-collapse text-xs">
                    <thead>
                        <tr class="border-b bg-slate-100/90">
                            <th
                                v-if="showAllChapters"
                                class="sticky left-0 z-10 bg-slate-100 px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wide text-gray-600"
                            >
                                Chapter
                            </th>
                            <th
                                class="sticky px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wide text-gray-600"
                                :class="showAllChapters ? 'left-[132px] z-10 bg-slate-100' : 'left-0 z-10 bg-slate-100'"
                            >
                                Set
                            </th>
                            <th class="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wide text-gray-600">
                                {{ gradeLevelName || 'Class' }}
                            </th>
                            <th
                                v-for="student in students"
                                :key="`head-${student.id}`"
                                class="min-w-[88px] px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wide text-gray-600"
                            >
                                {{ student.name }}
                            </th>
                            <th
                                v-if="canAssign"
                                class="min-w-[240px] px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wide text-gray-600"
                            >
                                Assign
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="chapter in visibleChapters" :key="chapter.chapter_id ?? 'extra'">
                            <tr v-if="chapter.is_extra" class="border-b border-violet-200 bg-violet-50">
                                <td
                                    :colspan="(showAllChapters ? 1 : 0) + 3 + students.length + (canAssign ? 1 : 0)"
                                    class="px-2 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-violet-900"
                                >
                                    {{ chapter.chapter_label }} — sheets from other grades, catch-up, or outside this syllabus
                                </td>
                            </tr>
                            <tr
                                v-for="set in chapter.sets"
                                :key="set.id"
                                class="border-b border-gray-100 hover:bg-gray-50/60"
                                :class="chapterRowClass(chapter)"
                            >
                                <td
                                    v-if="showAllChapters"
                                    class="sticky left-0 z-10 max-w-[132px] bg-white px-2 py-1.5 text-xs font-medium text-gray-900"
                                    :class="chapter.is_extra ? '!bg-violet-50/70' : ''"
                                >
                                    <span class="line-clamp-2" :title="chapter.chapter_label">
                                        {{ chapter.is_extra ? 'Extra' : chapter.chapter_label.replace(/^Ch \d+ — /, '') }}
                                    </span>
                                </td>
                                <td
                                    class="sticky max-w-[160px] bg-white px-2 py-1.5"
                                    :class="[showAllChapters ? 'left-[132px] z-10' : 'left-0 z-10', chapter.is_extra ? '!bg-violet-50/70' : '']"
                                >
                                    <div class="font-mono text-sm font-bold leading-none text-indigo-600">
                                        {{ set.set_code }}<span v-if="set.questions_count" class="font-semibold text-indigo-500"> ({{ set.questions_count }})</span>
                                    </div>
                                    <div class="mt-0.5 truncate text-xs text-gray-900" :title="setMeta(set)">
                                        {{ setMeta(set) }}
                                    </div>
                                    <span
                                        v-if="set.is_cross_grade"
                                        class="mt-1 inline-flex rounded border border-violet-200 bg-violet-100 px-1 py-0.5 text-[9px] font-semibold text-violet-800"
                                    >
                                        {{ set.sheet_grade_name }}
                                    </span>
                                </td>
                                <td class="px-2 py-1.5">
                                    <span :class="classDoneClass(set)">
                                        {{ set.completed_count }}/{{ students.length }}
                                    </span>
                                </td>
                                <td
                                    v-for="student in students"
                                    :key="`${set.id}-${student.id}`"
                                    class="px-2 py-1.5"
                                >
                                    <button
                                        v-if="canAssign"
                                        type="button"
                                        :class="cellStatus(studentRow(set, student.id)?.progress).boxClass"
                                        :title="cellStatus(studentRow(set, student.id)?.progress).title"
                                        @click="onCellClick(set, student.id)"
                                    >
                                        {{ cellStatus(studentRow(set, student.id)?.progress).label }}
                                    </button>
                                    <Link
                                        v-else-if="studentRow(set, student.id)?.progress?.assignment_id"
                                        :href="route('admin.set-assignments.show', studentRow(set, student.id).progress.assignment_id)"
                                        :class="cellStatus(studentRow(set, student.id)?.progress).boxClass"
                                    >
                                        {{ cellStatus(studentRow(set, student.id)?.progress).label }}
                                    </Link>
                                    <span
                                        v-else
                                        :class="cellStatus(studentRow(set, student.id)?.progress).boxClass"
                                    >
                                        {{ cellStatus(studentRow(set, student.id)?.progress).label }}
                                    </span>
                                </td>
                                <td v-if="canAssign" class="px-2 py-1.5">
                                    <div class="flex min-w-[240px] flex-wrap items-center gap-1">
                                        <select
                                            v-model="assignStudentBySet[set.id]"
                                            class="min-w-0 flex-1 rounded border-gray-300 py-1 text-[10px]"
                                        >
                                            <option value="">Student</option>
                                            <option v-for="student in students" :key="`pick-${set.id}-${student.id}`" :value="String(student.id)">
                                                {{ student.name }}
                                            </option>
                                        </select>
                                        <PrimaryButton
                                            type="button"
                                            class="!px-2 !py-1 !text-[10px]"
                                            :disabled="!assignStudentBySet[set.id] || !targetDate || assignForm.processing || reassignForm.processing || deassignForm.processing"
                                            @click="assignOrReassign(set)"
                                        >
                                            {{ assignActionLabel(set) }}
                                        </PrimaryButton>
                                        <DangerButton
                                            v-if="canDeassignProgress(selectedStudentProgress(set))"
                                            type="button"
                                            class="!px-2 !py-1 !text-[10px]"
                                            :disabled="deassignForm.processing"
                                            @click="deassign(
                                                selectedStudentProgress(set).assignment_id,
                                                set.set_code,
                                                students.find((s) => String(s.id) === assignStudentBySet[set.id])?.name || 'student',
                                            )"
                                        >
                                            Remove
                                        </DangerButton>
                                        <SecondaryButton
                                            type="button"
                                            class="!px-2 !py-1 !text-[10px]"
                                            :disabled="!targetDate || bulkForm.processing"
                                            @click="assignBulk(set.id)"
                                        >
                                            All
                                        </SecondaryButton>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p v-if="canAssign" class="border-t bg-gray-50 px-2 py-1 text-[10px] text-gray-500">
                Click <strong>Not assigned</strong> to assign with a 3-day target · select a student and use <strong>Remove</strong> to de-assign pending sets
            </p>
        </div>
    </div>
</template>
