<script setup>
import ExamPlanPanel from '@/Components/ExamPlanPanel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDate } from '@/utils/dates';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    examPlans: {
        type: Object,
        default: () => ({ upcoming: [], past: [] }),
    },
    allPlans: { type: Array, default: () => [] },
    syllabusChapters: { type: Array, default: () => [] },
    examTypeOptions: { type: Array, default: () => [] },
    studyPlanContext: {
        type: Object,
        default: () => ({ grade_name: null, board_name: null }),
    },
});

const page = usePage();
const createFormOpen = ref(props.allPlans.length === 0);
const editingPlanId = ref(null);

const upcoming = computed(() => props.examPlans.upcoming || []);
const past = computed(() => props.examPlans.past || []);

const subtitle = computed(() => {
    const parts = [props.studyPlanContext?.grade_name, props.studyPlanContext?.board_name].filter(Boolean);

    return parts.length ? parts.join(' · ') : 'Your class syllabus';
});

const chapterSummary = (plan) => {
    if (!plan.chapter_names?.length) {
        return 'No chapters selected';
    }

    if (plan.chapter_names.length <= 2) {
        return plan.chapter_names.join(' · ');
    }

    return `${plan.chapter_names.slice(0, 2).join(' · ')} +${plan.chapter_names.length - 2} more`;
};

const daysUntil = (dateStr) => {
    if (!dateStr) {
        return '—';
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

const prepLabel = (plan) => {
    if (!plan.prep_summary?.total) {
        return '—';
    }

    return `${plan.prep_summary.completed}/${plan.prep_summary.total} done`;
};

const scrollToForm = (elementId) => {
    nextTick(() => {
        document.getElementById(elementId)?.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
        });
    });
};

const openAddForm = () => {
    editingPlanId.value = null;
    createFormOpen.value = true;
    scrollToForm('exam-form-add');
};

const openEditForm = (planId) => {
    createFormOpen.value = false;
    editingPlanId.value = planId;
    scrollToForm(`exam-form-edit-${planId}`);
};

const closeForms = () => {
    createFormOpen.value = false;
    editingPlanId.value = null;
};

const isEditingRow = (planId) => String(editingPlanId.value) === String(planId);
</script>

<template>
    <Head title="My Exams" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">
                    My Exams
                </h2>
                <p class="text-sm text-gray-500">
                    {{ subtitle }}
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                    Add each school test with its date and chapters. After the exam, enter your marks.
                    Prep sets are assigned from your study plan on the dashboard.
                </div>

                <section
                    v-if="createFormOpen"
                    id="exam-form-add"
                    class="rounded-xl border-2 border-indigo-300 bg-indigo-50/50 p-4 shadow-sm"
                >
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-bold uppercase tracking-wide text-indigo-950">
                                Add new exam
                            </h3>
                            <p class="mt-1 text-xs text-indigo-900/80">
                                Enter the test date, type, title, and chapters.
                            </p>
                        </div>
                        <button
                            v-if="allPlans.length"
                            type="button"
                            class="text-xs font-semibold text-indigo-800 hover:underline"
                            @click="closeForms"
                        >
                            Cancel
                        </button>
                    </div>
                    <ExamPlanPanel
                        :plans="allPlans"
                        :syllabus-chapters="syllabusChapters"
                        :exam-type-options="examTypeOptions"
                        context="student"
                        compact
                        embedded
                        hide-plan-list
                        auto-open-create
                        @closed="closeForms"
                    />
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-bold uppercase tracking-wide text-slate-800">
                                Upcoming exams · {{ upcoming.length }}
                            </h3>
                            <p class="mt-1 text-xs text-slate-600">
                                Tests still to come — sorted by date.
                            </p>
                        </div>
                        <PrimaryButton
                            v-if="!createFormOpen"
                            type="button"
                            class="!py-2 !text-sm"
                            @click="openAddForm"
                        >
                            Add exam
                        </PrimaryButton>
                    </div>

                    <div v-if="upcoming.length" class="mt-4 overflow-x-auto">
                        <table class="min-w-full border-collapse text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                                    <th class="px-2 py-2">Date</th>
                                    <th class="px-2 py-2">Exam</th>
                                    <th class="px-2 py-2">Chapters</th>
                                    <th class="px-2 py-2">Prep</th>
                                    <th class="px-2 py-2 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="plan in upcoming" :key="plan.id">
                                    <tr
                                        class="border-b border-slate-100"
                                        :class="isEditingRow(plan.id) ? 'bg-indigo-50/70' : 'last:border-0'"
                                    >
                                        <td class="px-2 py-2.5 align-top whitespace-nowrap">
                                            <p class="font-semibold text-slate-900">{{ formatDate(plan.exam_date) }}</p>
                                            <p class="text-xs text-slate-500">{{ daysUntil(plan.exam_date) }}</p>
                                        </td>
                                        <td class="px-2 py-2.5 align-top">
                                            <p class="font-semibold text-slate-900">{{ plan.title }}</p>
                                            <p class="text-xs text-slate-500">{{ plan.exam_type_label }}</p>
                                        </td>
                                        <td class="max-w-[14rem] px-2 py-2.5 align-top text-xs text-slate-700">
                                            {{ chapterSummary(plan) }}
                                        </td>
                                        <td class="px-2 py-2.5 align-top text-xs text-slate-700">
                                            {{ prepLabel(plan) }}
                                        </td>
                                        <td class="px-2 py-2.5 align-top text-right">
                                            <button
                                                v-if="!isEditingRow(plan.id)"
                                                type="button"
                                                class="text-xs font-semibold text-indigo-700 hover:underline"
                                                @click="openEditForm(plan.id)"
                                            >
                                                Edit
                                            </button>
                                            <span v-else class="text-xs font-semibold text-indigo-900">Editing…</span>
                                        </td>
                                    </tr>
                                    <tr v-if="isEditingRow(plan.id)">
                                        <td colspan="5" class="border-b border-indigo-200 bg-indigo-50/40 p-0">
                                            <div
                                                :id="`exam-form-edit-${plan.id}`"
                                                class="border-t-2 border-indigo-300 p-4"
                                            >
                                                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                                    <div>
                                                        <h3 class="text-sm font-bold uppercase tracking-wide text-indigo-950">
                                                            Edit exam · {{ plan.title }}
                                                        </h3>
                                                        <p class="mt-1 text-xs text-indigo-900/80">
                                                            {{ formatDate(plan.exam_date) }} · {{ plan.exam_type_label }}
                                                        </p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        class="text-xs font-semibold text-indigo-800 hover:underline"
                                                        @click="closeForms"
                                                    >
                                                        Cancel
                                                    </button>
                                                </div>
                                                <ExamPlanPanel
                                                    :plans="allPlans"
                                                    :syllabus-chapters="syllabusChapters"
                                                    :exam-type-options="examTypeOptions"
                                                    context="student"
                                                    compact
                                                    embedded
                                                    hide-plan-list
                                                    :highlight-plan-id="plan.id"
                                                    @closed="closeForms"
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="mt-4 text-sm text-slate-500">
                        No upcoming exams yet.
                        <button
                            v-if="!createFormOpen"
                            type="button"
                            class="font-semibold text-indigo-700 hover:underline"
                            @click="openAddForm"
                        >
                            Add your first exam
                        </button>
                    </p>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-800">
                        Past exams & scores · {{ past.length }}
                    </h3>
                    <p class="mt-1 text-xs text-slate-600">
                        Enter school marks after each test so you can track progress.
                    </p>

                    <div v-if="past.length" class="mt-4 overflow-x-auto">
                        <table class="min-w-full border-collapse text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-[11px] font-bold uppercase tracking-wide text-slate-600">
                                    <th class="px-2 py-2">Date</th>
                                    <th class="px-2 py-2">Exam</th>
                                    <th class="px-2 py-2">Score</th>
                                    <th class="px-2 py-2 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="plan in past" :key="plan.id">
                                    <tr
                                        class="border-b border-slate-100"
                                        :class="isEditingRow(plan.id) ? 'bg-indigo-50/70' : 'last:border-0'"
                                    >
                                        <td class="px-2 py-2.5 align-top whitespace-nowrap font-semibold text-slate-900">
                                            {{ formatDate(plan.exam_date) }}
                                        </td>
                                        <td class="px-2 py-2.5 align-top">
                                            <p class="font-semibold text-slate-900">{{ plan.title }}</p>
                                            <p class="text-xs text-slate-500">{{ plan.exam_type_label }}</p>
                                        </td>
                                        <td class="px-2 py-2.5 align-top">
                                            <span
                                                v-if="plan.has_marks || plan.marks_score_label"
                                                class="font-semibold text-emerald-700"
                                            >
                                                {{ plan.marks_score_label }}
                                            </span>
                                            <span v-else class="text-xs font-medium text-amber-800">Not entered</span>
                                        </td>
                                        <td class="px-2 py-2.5 align-top text-right">
                                            <button
                                                v-if="!isEditingRow(plan.id)"
                                                type="button"
                                                class="text-xs font-semibold text-indigo-700 hover:underline"
                                                @click="openEditForm(plan.id)"
                                            >
                                                {{ plan.has_marks ? 'Edit marks' : 'Enter marks' }}
                                            </button>
                                            <span v-else class="text-xs font-semibold text-indigo-900">Editing…</span>
                                        </td>
                                    </tr>
                                    <tr v-if="isEditingRow(plan.id)">
                                        <td colspan="4" class="border-b border-indigo-200 bg-indigo-50/40 p-0">
                                            <div
                                                :id="`exam-form-edit-${plan.id}`"
                                                class="border-t-2 border-indigo-300 p-4"
                                            >
                                                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                                    <div>
                                                        <h3 class="text-sm font-bold uppercase tracking-wide text-indigo-950">
                                                            Edit exam · {{ plan.title }}
                                                        </h3>
                                                        <p class="mt-1 text-xs text-indigo-900/80">
                                                            {{ formatDate(plan.exam_date) }} · enter or update your school marks
                                                        </p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        class="text-xs font-semibold text-indigo-800 hover:underline"
                                                        @click="closeForms"
                                                    >
                                                        Cancel
                                                    </button>
                                                </div>
                                                <ExamPlanPanel
                                                    :plans="allPlans"
                                                    :syllabus-chapters="syllabusChapters"
                                                    :exam-type-options="examTypeOptions"
                                                    context="student"
                                                    compact
                                                    embedded
                                                    hide-plan-list
                                                    :highlight-plan-id="plan.id"
                                                    @closed="closeForms"
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="mt-4 text-sm text-slate-500">
                        No past exams yet.
                    </p>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
