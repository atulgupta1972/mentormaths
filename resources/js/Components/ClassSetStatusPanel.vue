<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    chapters: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
    gradeLevelId: { type: Number, required: true },
    gradeLevelName: { type: String, default: '' },
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

const assignForm = useForm({ student_id: '', target_date: '', notes: '' });
const bulkForm = useForm({ grade_level_id: '', target_date: '', notes: '' });
const reassignForm = useForm({ target_date: '', notes: '' });

const statusLegend = [
    { label: 'Done', boxClass: 'bg-emerald-100 text-emerald-800 border-emerald-200' },
    { label: 'In progress', boxClass: 'bg-amber-100 text-amber-900 border-amber-200' },
    { label: 'Not done', boxClass: 'bg-rose-100 text-rose-800 border-rose-200' },
    { label: 'Not assigned', boxClass: 'bg-slate-100 text-slate-400 border-slate-200' },
];

watch(
    () => props.chapters,
    (chapters) => {
        if (!chapters.length) {
            selectedChapterId.value = '';
            return;
        }

        if (!chapters.some((chapter) => String(chapter.chapter_id) === String(selectedChapterId.value))) {
            selectedChapterId.value = String(chapters[0].chapter_id);
        }
    },
    { immediate: true },
);

const activeChapter = computed(() =>
    props.chapters.find((chapter) => String(chapter.chapter_id) === String(selectedChapterId.value)) ?? null,
);

const studentRow = (set, studentId) =>
    set.students?.find((row) => row.student_id === studentId) ?? null;

const isNotAssigned = (progress) => !progress?.assignment_id;

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
                    ? `${progress.latest_score}/${progress.latest_max_score} late`
                    : `${progress.latest_score}/${progress.latest_max_score}`,
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

    if (set.topic_name) {
        parts.push(set.topic_name);
    }

    return parts.join(' · ');
};
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
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div class="flex items-center gap-2">
                    <InputLabel value="Chapter" class="!mb-0 shrink-0 !text-xs" />
                    <select
                        v-model="selectedChapterId"
                        class="rounded-md border-gray-300 py-1 text-xs"
                    >
                        <option v-for="chapter in chapters" :key="chapter.chapter_id" :value="String(chapter.chapter_id)">
                            {{ chapter.chapter_label }}
                        </option>
                    </select>
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
        </div>

        <div v-if="!chapters.length" class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-center text-xs text-gray-500">
            No published practice sets or tests for this class yet.
        </div>

        <div v-else-if="!activeChapter?.sets?.length" class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-center text-xs text-gray-500">
            No sheets in this chapter.
        </div>

        <div v-else class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full border-collapse text-xs">
                    <thead>
                        <tr class="border-b bg-slate-100/90">
                            <th class="sticky left-0 z-10 bg-slate-100 px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wide text-gray-600">
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
                                class="min-w-[200px] px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wide text-gray-600"
                            >
                                Assign
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b bg-violet-50">
                            <td
                                :colspan="2 + students.length + (canAssign ? 1 : 0)"
                                class="px-2 py-1 text-[11px] font-semibold text-violet-900"
                            >
                                {{ activeChapter.chapter_label }}
                            </td>
                        </tr>

                        <tr
                            v-for="set in activeChapter.sets"
                            :key="set.id"
                            class="border-b border-gray-100 hover:bg-gray-50/60"
                        >
                            <td class="sticky left-0 z-10 max-w-[140px] bg-white px-2 py-1.5">
                                <div class="font-mono text-sm font-bold leading-none text-indigo-600">{{ set.set_code }}</div>
                                <div class="mt-0.5 truncate text-[10px] text-gray-500" :title="setMeta(set)">
                                    {{ setMeta(set) }}
                                </div>
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
                                <div class="flex min-w-[200px] items-center gap-1">
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
                                        :disabled="!assignStudentBySet[set.id] || !targetDate || assignForm.processing || reassignForm.processing"
                                        @click="assignOrReassign(set)"
                                    >
                                        {{ assignActionLabel(set) }}
                                    </PrimaryButton>
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
                    </tbody>
                </table>
            </div>

            <p v-if="canAssign" class="border-t bg-gray-50 px-2 py-1 text-[10px] text-gray-500">
                Click <strong>Not assigned</strong> to assign with a 3-day target · other cells assign / re-assign using the date above
            </p>
        </div>
    </div>
</template>
