<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ClassCoveragePanel from '@/Components/ClassCoveragePanel.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    gradeLevel: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    withPlanStudents: { type: Array, default: () => [] },
    withoutPlanStudents: { type: Array, default: () => [] },
    summary: {
        type: Object,
        default: () => ({
            total: 0,
            with_plan: 0,
            without_plan: 0,
            without_plan_with_email: 0,
            without_plan_no_email: 0,
        }),
    },
    filters: { type: Object, default: () => ({}) },
    selectedStudent: { type: Object, default: null },
    classCoverage: {
        type: Object,
        default: () => ({ chapters: [], under_study_chapter_id: null }),
    },
    context: { type: Object, default: null },
});

const reminderForm = useForm({});

const setStudent = (studentId) => {
    router.get(route('admin.school-study-plan.index'), {
        student_id: studentId || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

const updateRouteParams = computed(() => (
    props.selectedStudent?.id
        ? { student: props.selectedStudent.id }
        : {}
));

const contextLabel = computed(() => {
    const parts = [props.context?.grade_name, props.context?.board_name].filter(Boolean);

    return parts.join(' · ');
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success ?? '');
const flashWarning = computed(() => page.props.flash?.warning ?? '');

const sendReminders = () => {
    const count = props.summary?.without_plan_with_email || 0;
    const className = props.gradeLevel?.name ?? 'this class';

    if (!count) {
        alert('No students without a study plan have an email address on file.');

        return;
    }

    if (!confirm(
        `Send study-plan reminder emails to ${count} student(s) in ${className} who have not marked any school study plan? Parents will be CC'd when email is on file. (WhatsApp later.)`,
    )) {
        return;
    }

    reminderForm.post(route('admin.school-study-plan.send-reminders'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="School study plans" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">School study plans</h2>
                <p class="text-sm text-gray-500">
                    See who in {{ gradeLevel?.name ?? 'the selected class' }} has marked school chapters, and email those who have not.
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6">
                <div v-if="!gradeLevel" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
                    Select a class from the top bar first.
                </div>

                <template v-else>
                    <div
                        v-if="flashSuccess"
                        class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                    >
                        {{ flashSuccess }}
                    </div>
                    <div
                        v-if="flashWarning"
                        class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                    >
                        {{ flashWarning }}
                    </div>

                    <div class="grid gap-3 sm:grid-cols-4">
                        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Students</p>
                            <p class="mt-1 text-2xl font-bold text-slate-900">{{ summary.total }}</p>
                        </div>
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 shadow-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-800">Plan marked</p>
                            <p class="mt-1 text-2xl font-bold text-emerald-900">{{ summary.with_plan }}</p>
                        </div>
                        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 shadow-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-rose-800">Not marked</p>
                            <p class="mt-1 text-2xl font-bold text-rose-900">{{ summary.without_plan }}</p>
                        </div>
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 shadow-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-800">Emailable</p>
                            <p class="mt-1 text-2xl font-bold text-amber-950">{{ summary.without_plan_with_email }}</p>
                            <p class="mt-0.5 text-[11px] text-amber-800">
                                {{ summary.without_plan_no_email }} missing email
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <p class="text-sm text-slate-600">
                            Email students who have not marked any studied / under-study chapter.
                            WhatsApp will be added once configured.
                        </p>
                        <button
                            type="button"
                            class="rounded-md bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="reminderForm.processing || !(summary.without_plan_with_email > 0)"
                            @click="sendReminders"
                        >
                            {{ reminderForm.processing ? 'Sending…' : `Email ${summary.without_plan_with_email || 0} without plan` }}
                        </button>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <section class="rounded-xl border border-rose-200 bg-white p-4 shadow-sm">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-rose-800">
                                Not marked · {{ withoutPlanStudents.length }}
                            </h3>
                            <ul v-if="withoutPlanStudents.length" class="mt-3 divide-y divide-rose-50">
                                <li
                                    v-for="student in withoutPlanStudents"
                                    :key="`missing-${student.id}`"
                                    class="flex items-center justify-between gap-2 py-2"
                                >
                                    <button
                                        type="button"
                                        class="text-left text-sm font-medium text-slate-900 hover:text-rose-800"
                                        @click="setStudent(student.id)"
                                    >
                                        {{ student.name }}
                                        <span v-if="student.board_name" class="font-normal text-slate-500"> · {{ student.board_name }}</span>
                                    </button>
                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                        :class="student.has_email ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-500'"
                                    >
                                        {{ student.has_email ? 'Has email' : 'No email' }}
                                    </span>
                                </li>
                            </ul>
                            <p v-else class="mt-3 text-sm text-slate-500">Everyone in this class has marked a study plan.</p>
                        </section>

                        <section class="rounded-xl border border-emerald-200 bg-white p-4 shadow-sm">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-emerald-800">
                                Plan marked · {{ withPlanStudents.length }}
                            </h3>
                            <ul v-if="withPlanStudents.length" class="mt-3 divide-y divide-emerald-50">
                                <li
                                    v-for="student in withPlanStudents"
                                    :key="`ok-${student.id}`"
                                    class="py-2"
                                >
                                    <button
                                        type="button"
                                        class="text-left text-sm font-medium text-slate-900 hover:text-emerald-800"
                                        @click="setStudent(student.id)"
                                    >
                                        {{ student.name }}
                                        <span v-if="student.board_name" class="font-normal text-slate-500"> · {{ student.board_name }}</span>
                                    </button>
                                </li>
                            </ul>
                            <p v-else class="mt-3 text-sm text-slate-500">No students have marked a plan yet.</p>
                        </section>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <label class="text-sm text-gray-600">Open student plan</label>
                        <select
                            class="rounded-md border-gray-300 text-sm"
                            :value="filters.student_id ?? ''"
                            @change="setStudent($event.target.value || null)"
                        >
                            <option value="">Select student…</option>
                            <option
                                v-for="student in students"
                                :key="student.id"
                                :value="student.id"
                            >
                                {{ student.name }}{{ student.has_study_plan ? '' : ' (not marked)' }}
                            </option>
                        </select>
                    </div>

                    <div v-if="!selectedStudent" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-600">
                        Choose a student above to view or update their chapter ticks.
                    </div>

                    <div v-else class="space-y-2">
                        <p class="text-sm text-slate-700">
                            <span class="font-semibold">{{ selectedStudent.name }}</span>
                            <span v-if="contextLabel" class="text-slate-500"> · {{ contextLabel }}</span>
                        </p>
                        <ClassCoveragePanel
                            :class-coverage="classCoverage"
                            update-route-name="admin.school-study-plan.update"
                            :update-route-params="updateRouteParams"
                        />
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
