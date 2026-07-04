<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    plans: { type: Array, default: () => [] },
    syllabusChapters: { type: Array, default: () => [] },
    examTypeOptions: { type: Array, default: () => [] },
    studentId: { type: [Number, String], default: null },
    context: { type: String, default: 'student' },
    compact: { type: Boolean, default: false },
    autoOpenCreate: { type: Boolean, default: false },
});

const isAdminContext = computed(() => props.context === 'admin');

const showForm = ref(false);
const editingPlan = ref(null);
const assigningPlanId = ref(null);
const assignDueDates = ref({});

const emptyForm = () => ({
    exam_date: '',
    title: '',
    exam_type: 'unit_test',
    notes: '',
    syllabus_chapter_ids: [],
    ...(props.context === 'admin' && props.studentId ? { student_id: props.studentId } : {}),
});

const form = useForm(emptyForm());
const assignForm = useForm({
    student_id: '',
    target_date: '',
    exam_plan_id: '',
    notes: '',
});

const canManage = computed(() => props.syllabusChapters.length > 0);

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

const prepStatusClass = (prep) => {
    if (prep.assignment_status === 'completed') {
        return prep.submission_timing === 'late' ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-800';
    }
    if (prep.is_overdue) {
        return 'bg-red-100 text-red-800';
    }
    if (prep.assignment_status === 'in_progress') {
        return 'bg-yellow-100 text-yellow-800';
    }

    return 'bg-indigo-50 text-indigo-800';
};

const dueDateForPlan = (plan) => assignDueDates.value[plan.id] || plan.suggested_due_date || plan.exam_date;

const toggleAssign = (plan) => {
    if (assigningPlanId.value === plan.id) {
        assigningPlanId.value = null;

        return;
    }

    assigningPlanId.value = plan.id;

    if (!assignDueDates.value[plan.id]) {
        assignDueDates.value[plan.id] = plan.suggested_due_date || plan.exam_date;
    }
};

const chapterHasSets = (chapter) =>
    (chapter.topic_sets?.length || 0) + (chapter.chapter_tests?.length || 0) > 0;

const assignSet = (plan, worksheetId) => {
    if (!props.studentId) {
        return;
    }

    assignForm.student_id = props.studentId;
    assignForm.target_date = dueDateForPlan(plan);
    assignForm.exam_plan_id = plan.id;
    assignForm.notes = '';

    assignForm.post(route('admin.practice-sets.assign', worksheetId), {
        preserveScroll: true,
    });
};

const openCreate = () => {
    editingPlan.value = null;
    assigningPlanId.value = null;
    form.defaults(emptyForm());
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const openEdit = (plan) => {
    editingPlan.value = plan;
    assigningPlanId.value = null;
    form.defaults({
        ...emptyForm(),
        exam_date: plan.exam_date,
        title: plan.title,
        exam_type: plan.exam_type,
        notes: plan.notes || '',
        syllabus_chapter_ids: [...(plan.chapter_ids || [])],
    });
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const cancelForm = () => {
    showForm.value = false;
    editingPlan.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingPlan.value) {
        form.put(route(`${props.context}.exam-plans.update`, editingPlan.value.id), {
            preserveScroll: true,
            onSuccess: () => cancelForm(),
        });

        return;
    }

    form.post(route(`${props.context}.exam-plans.store`), {
        preserveScroll: true,
        onSuccess: () => cancelForm(),
    });
};

const removePlan = (plan) => {
    if (!window.confirm(`Remove exam plan "${plan.title}"?`)) {
        return;
    }

    form.delete(route(`${props.context}.exam-plans.destroy`, plan.id), {
        preserveScroll: true,
    });
};

watch(
    () => props.autoOpenCreate,
    (shouldOpen) => {
        if (shouldOpen && canManage.value && !showForm.value) {
            openCreate();
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="space-y-4">
        <div v-if="!compact" class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-medium text-gray-900">Exam plans</h3>
                <p v-if="isAdminContext" class="mt-1 text-sm text-gray-500">
                    Edit the exam date and chapters, then assign practice sheets and chapter tests for this student.
                </p>
                <p v-else class="mt-1 text-sm text-gray-500">
                    Add your school test date and chapters. Your teacher's assigned prep will show here.
                </p>
            </div>
            <PrimaryButton v-if="canManage && !showForm" type="button" @click="openCreate">
                Add exam date
            </PrimaryButton>
        </div>

        <div v-if="compact && canManage" class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-600">
                {{ isAdminContext ? 'Edit exam or assign chapter practice / tests.' : 'Add your upcoming exam.' }}
            </p>
            <PrimaryButton v-if="!showForm" type="button" @click="openCreate">
                Add exam date
            </PrimaryButton>
        </div>

        <div v-if="!canManage" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <template v-if="isAdminContext">
                Import the class syllabus first, then you can enter exam dates here.
            </template>
            <template v-else>
                Syllabus is not set up for your class yet. Ask your teacher to import the syllabus first.
            </template>
        </div>

        <div v-if="showForm && canManage" class="rounded-lg border border-indigo-200 bg-indigo-50/40 p-4">
            <h4 class="text-sm font-medium text-gray-900">
                {{ editingPlan ? 'Edit exam plan' : 'Add exam plan' }}
            </h4>

            <form class="mt-4 space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel value="Exam date" />
                        <TextInput v-model="form.exam_date" type="date" class="mt-1 block w-full" required />
                        <InputError class="mt-1" :message="form.errors.exam_date" />
                    </div>
                    <div>
                        <InputLabel value="Type" />
                        <select v-model="form.exam_type" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            <option v-for="opt in examTypeOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.exam_type" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Title" />
                        <TextInput v-model="form.title" class="mt-1 block w-full" placeholder="Unit test 1" required />
                        <InputError class="mt-1" :message="form.errors.title" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Chapters in this exam" />
                        <select
                            v-model="form.syllabus_chapter_ids"
                            multiple
                            size="8"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                        >
                            <option v-for="chapter in syllabusChapters" :key="chapter.id" :value="chapter.id">
                                {{ chapter.label }}
                            </option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Hold Ctrl (Windows) or Cmd (Mac) to select multiple chapter names.
                        </p>
                        <InputError class="mt-1" :message="form.errors.syllabus_chapter_ids" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Notes (optional)" />
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                            placeholder="Anything your teacher should know"
                        />
                        <InputError class="mt-1" :message="form.errors.notes" />
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <PrimaryButton type="submit" :disabled="form.processing">
                        {{ editingPlan ? 'Save changes' : 'Save exam plan' }}
                    </PrimaryButton>
                    <SecondaryButton type="button" @click="cancelForm">Cancel</SecondaryButton>
                </div>
            </form>
        </div>

        <div v-if="plans.length === 0 && !showForm" class="rounded-lg border border-dashed border-gray-200 p-4 text-sm text-gray-500">
            No exam plans yet.
        </div>

        <div v-else-if="plans.length" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Date</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Exam</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapters</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Practice / tests</th>
                        <th v-if="canManage" class="px-4 py-3 text-right text-xs uppercase text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <template v-for="plan in plans" :key="plan.id">
                        <tr>
                            <td class="px-4 py-3 align-top whitespace-nowrap">{{ formatDate(plan.exam_date) }}</td>
                            <td class="px-4 py-3 align-top">
                                <p class="font-medium text-gray-900">{{ plan.title }}</p>
                                <p class="text-xs text-gray-500">{{ plan.exam_type_label }}</p>
                            </td>
                            <td class="px-4 py-3 align-top text-gray-700">
                                <ul class="space-y-1">
                                    <li v-for="(name, index) in (plan.chapter_names || [])" :key="index">
                                        {{ name }}
                                    </li>
                                    <li v-if="!(plan.chapter_names || []).length" class="text-gray-400">—</li>
                                </ul>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div v-if="plan.prep_assignments?.length" class="space-y-2">
                                    <p v-if="plan.prep_summary" class="text-xs text-gray-500">
                                        {{ plan.prep_summary.completed }}/{{ plan.prep_summary.total }} done
                                    </p>
                                    <ul class="space-y-1.5">
                                        <li
                                            v-for="prep in plan.prep_assignments"
                                            :key="prep.assignment_id"
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <span class="font-mono font-semibold text-gray-900">{{ prep.set_code }}</span>
                                            <span class="text-xs text-gray-500">{{ prep.kind_label }}</span>
                                            <span
                                                class="rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide"
                                                :class="prepStatusClass(prep)"
                                            >
                                                {{ prep.progress_label }}
                                            </span>
                                            <span v-if="prep.target_date" class="text-xs text-gray-500">
                                                Due {{ formatDate(prep.target_date) }}
                                            </span>
                                        </li>
                                    </ul>
                                </div>
                                <p v-else class="text-xs text-gray-400">
                                    {{ isAdminContext ? 'Click Assign sheets to add practice or tests.' : 'No prep assigned yet.' }}
                                </p>
                            </td>
                            <td v-if="canManage" class="px-4 py-3 align-top text-right space-x-2 whitespace-nowrap">
                                <button type="button" class="text-indigo-600 hover:underline" @click="openEdit(plan)">
                                    Edit
                                </button>
                                <button
                                    v-if="isAdminContext"
                                    type="button"
                                    class="font-medium text-indigo-600 hover:underline"
                                    @click="toggleAssign(plan)"
                                >
                                    {{ assigningPlanId === plan.id ? 'Close' : 'Assign sheets' }}
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="text-red-600 hover:underline"
                                    @click="removePlan(plan)"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>

                        <tr v-if="isAdminContext && assigningPlanId === plan.id">
                            <td colspan="5" class="bg-slate-50 px-4 py-4">
                                <div class="space-y-4">
                                    <div class="flex flex-wrap items-end justify-between gap-3">
                                        <div>
                                            <h4 class="text-sm font-medium text-gray-900">Assign practice / chapter tests</h4>
                                            <p class="mt-1 text-xs text-gray-500">
                                                Sheets from the exam chapters only. Default due date is the day before the exam.
                                            </p>
                                        </div>
                                        <div>
                                            <InputLabel value="Due date for new assignments" class="!text-xs" />
                                            <input
                                                v-model="assignDueDates[plan.id]"
                                                type="date"
                                                class="mt-1 rounded-md border-gray-300 text-sm"
                                            />
                                        </div>
                                    </div>

                                    <div
                                        v-for="chapter in plan.assignable_chapters || []"
                                        :key="chapter.chapter_id"
                                        class="rounded-lg border border-gray-200 bg-white p-4"
                                    >
                                        <h5 class="text-sm font-medium text-gray-900">{{ chapter.chapter_label }}</h5>

                                        <div v-if="!chapterHasSets(chapter)" class="mt-2 text-xs text-gray-400">
                                            No published practice sets or chapter tests for this chapter yet.
                                        </div>

                                        <div v-if="chapter.topic_sets?.length" class="mt-3">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Topic practice</p>
                                            <ul class="mt-2 divide-y divide-gray-100">
                                                <li
                                                    v-for="set in chapter.topic_sets"
                                                    :key="set.id"
                                                    class="flex flex-wrap items-center justify-between gap-3 py-2"
                                                >
                                                    <div>
                                                        <span class="font-mono font-semibold text-indigo-600">{{ set.set_code }}</span>
                                                        <span class="ml-2 text-xs text-gray-600">{{ set.topic_name }}</span>
                                                        <span class="ml-2 text-xs text-gray-400">{{ set.tier_label }} · {{ set.questions_count }} Q</span>
                                                    </div>
                                                    <PrimaryButton
                                                        v-if="!set.is_assigned"
                                                        type="button"
                                                        class="!py-1.5 !text-xs"
                                                        :disabled="assignForm.processing"
                                                        @click="assignSet(plan, set.id)"
                                                    >
                                                        Assign
                                                    </PrimaryButton>
                                                    <span v-else class="text-xs font-medium text-green-700">Already assigned</span>
                                                </li>
                                            </ul>
                                        </div>

                                        <div v-if="chapter.chapter_tests?.length" class="mt-3">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Chapter tests</p>
                                            <ul class="mt-2 divide-y divide-gray-100">
                                                <li
                                                    v-for="set in chapter.chapter_tests"
                                                    :key="set.id"
                                                    class="flex flex-wrap items-center justify-between gap-3 py-2"
                                                >
                                                    <div>
                                                        <span class="font-mono font-semibold text-indigo-600">{{ set.set_code }}</span>
                                                        <span class="ml-2 text-xs text-gray-600">Chapter test</span>
                                                        <span class="ml-2 text-xs text-gray-400">{{ set.questions_count }} Q</span>
                                                    </div>
                                                    <PrimaryButton
                                                        v-if="!set.is_assigned"
                                                        type="button"
                                                        class="!py-1.5 !text-xs"
                                                        :disabled="assignForm.processing"
                                                        @click="assignSet(plan, set.id)"
                                                    >
                                                        Assign
                                                    </PrimaryButton>
                                                    <span v-else class="text-xs font-medium text-green-700">Already assigned</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
