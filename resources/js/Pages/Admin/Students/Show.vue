<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ContactNumbersPanel from '@/Components/ContactNumbersPanel.vue';
import StudentEmailContactsPanel from '@/Components/StudentEmailContactsPanel.vue';
import StudentProgressSummaryPanel from '@/Components/StudentProgressSummaryPanel.vue';
import DangerButton from '@/Components/DangerButton.vue';
import ExamPlanPanel from '@/Components/ExamPlanPanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    student: Object,
    accountActive: { type: Boolean, default: true },
    currentYearEnrollment: { type: Object, default: null },
    enrollmentHistory: Array,
    latestEnrollment: Object,
    nextGrade: Object,
    academicYears: Array,
    gradeLevels: Array,
    boards: Array,
    shareLinks: Object,
    examPlans: { type: Array, default: () => [] },
    syllabusChapters: { type: Array, default: () => [] },
    examTypeOptions: { type: Array, default: () => [] },
    resolutionItems: { type: Array, default: () => [] },
    helpRequestsCount: { type: Number, default: 0 },
    defaultSummaryEmail: { type: String, default: '' },
    summaryEmailRecipients: { type: Array, default: () => [] },
    whatsappRecipientCount: { type: Number, default: 0 },
});

const contactFields = computed(() => [
    {
        key: 'student',
        field: 'student_mobile',
        notifyField: 'notify_student_mobile',
        label: 'Student mobile',
        mobile: props.student.student_mobile,
        notify: props.student.notify_student_mobile,
    },
    {
        key: 'parent1',
        field: 'parent1_mobile',
        notifyField: 'notify_parent1_mobile',
        label: 'Parent 1 mobile',
        name: props.student.parent1_name,
        mobile: props.student.parent1_mobile,
        notify: props.student.notify_parent1_mobile ?? true,
        required: true,
    },
    {
        key: 'parent2',
        field: 'parent2_mobile',
        notifyField: 'notify_parent2_mobile',
        label: 'Parent 2 mobile',
        name: props.student.parent2_name || null,
        mobile: props.student.parent2_mobile,
        notify: props.student.notify_parent2_mobile,
    },
]);

const defaultYear = computed(() => props.academicYears.find((y) => !y.is_active) || props.academicYears[0]);

const form = useForm({
    academic_year_id: defaultYear.value?.id || '',
    grade_level_id: props.nextGrade?.id || '',
    board_id: props.latestEnrollment?.board_id || '',
    school_name: props.latestEnrollment?.school_name || props.student.school_name,
});

watch(defaultYear, (year) => {
    if (year && !form.academic_year_id) {
        form.academic_year_id = year.id;
    }
});

const submit = () => {
    form.post(route('admin.students.promote', props.student.id));
};

const toggleActive = () => {
    const action = props.accountActive ? 'Deactivate' : 'Activate';
    const detail = props.accountActive
        ? 'They will not be able to log in and will be hidden from class lists and assignments.'
        : 'They will be able to log in and appear in class lists again.';

    if (!confirm(`${action} ${props.student.name}? ${detail}`)) {
        return;
    }

    router.post(route('admin.students.toggle-active', props.student.id));
};

const deleteForm = useForm({});

const destroyStudent = () => {
    if (!confirm(`Permanently delete ${props.student.name}? This removes their login, enrollments, exam plans, and assignments. This cannot be undone.`)) {
        return;
    }

    deleteForm.delete(route('admin.students.destroy', props.student.id));
};
</script>

<template>
    <Head :title="student.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">{{ student.name }}</h2>
                <Link :href="route('admin.students.index')" class="text-sm text-indigo-600">Back to students</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div
                    class="flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4 shadow-sm"
                    :class="accountActive ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50'"
                >
                    <div>
                        <p class="text-sm font-semibold" :class="accountActive ? 'text-emerald-900' : 'text-rose-900'">
                            {{ accountActive ? 'Active student' : 'Inactive student' }}
                        </p>
                        <p class="mt-1 text-sm" :class="accountActive ? 'text-emerald-800' : 'text-rose-800'">
                            <span v-if="student.user">Login: {{ student.user.email }}</span>
                            <span v-else>No login linked</span>
                            <span v-if="currentYearEnrollment?.status"> · Enrollment: {{ currentYearEnrollment.status }}</span>
                        </p>
                        <p v-if="!accountActive" class="mt-1 text-xs text-rose-700">
                            Deactivate keeps the record but blocks login. Use Delete below to remove a duplicate registration entirely.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <DangerButton v-if="accountActive" type="button" @click="toggleActive">
                            Deactivate student
                        </DangerButton>
                        <SecondaryButton v-else type="button" @click="toggleActive">
                            Activate student
                        </SecondaryButton>
                        <DangerButton type="button" :disabled="deleteForm.processing" @click="destroyStudent">
                            Delete student
                        </DangerButton>
                    </div>
                </div>

                <div class="rounded-lg bg-indigo-50 p-4 text-sm text-indigo-900">
                    <strong>Same student, new class each year.</strong>
                    Profile stays one record. Each academic year gets its own enrollment row
                    (class, board, school). Past years are kept for history.
                </div>

                <ContactNumbersPanel
                    :student-name="student.name"
                    :contacts="contactFields"
                    :save-url="route('admin.students.contacts.update', student.id)"
                    :share-links="shareLinks"
                />

                <StudentEmailContactsPanel
                    :student="student"
                    :login-email="student.user?.email || ''"
                    :save-url="route('admin.students.emails.update', student.id)"
                    :summary-email-recipients="summaryEmailRecipients"
                />

                <StudentProgressSummaryPanel
                    :student="student"
                    :default-email="defaultSummaryEmail"
                    :summary-email-recipients="summaryEmailRecipients"
                    :whatsapp-recipient-count="whatsappRecipientCount"
                />

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b px-6 py-4">
                        <h3 class="font-medium">Enrollment history</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Year</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Class</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Board</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="row in enrollmentHistory" :key="row.id">
                                <td class="px-4 py-3">{{ row.academic_year?.name }}</td>
                                <td class="px-4 py-3">{{ row.grade_level?.name }}</td>
                                <td class="px-4 py-3">{{ row.board?.code }}</td>
                                <td class="px-4 py-3 capitalize">{{ row.status }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="font-medium text-rose-900">
                        Asked for teacher help
                        <span v-if="helpRequestsCount" class="ml-1 rounded-full bg-rose-100 px-2 py-0.5 text-sm font-bold text-rose-800">
                            {{ helpRequestsCount }}
                        </span>
                    </h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Sums the student gave up during guided practice. Explain in class, then they retry from their dashboard.
                    </p>
                    <ul v-if="resolutionItems.length" class="mt-4 divide-y divide-gray-100">
                        <li v-for="item in resolutionItems" :key="item.id" class="py-3">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p v-if="item.set_code" class="font-mono text-sm font-semibold text-indigo-600">{{ item.set_code }}</p>
                                    <p class="mt-1 text-sm text-gray-800">{{ item.question_text }}</p>
                                </div>
                                <p class="text-xs text-gray-500">
                                    Given up {{ item.gave_up_at ? new Date(item.gave_up_at).toLocaleDateString('en-IN') : '—' }}
                                </p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="mt-4 rounded-lg border border-dashed border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
                        No pending help requests for this student.
                    </p>
                </div>

                <div v-if="latestEnrollment" class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <ExamPlanPanel
                        :plans="examPlans"
                        :syllabus-chapters="syllabusChapters"
                        :exam-type-options="examTypeOptions"
                        :student-id="student.id"
                        context="admin"
                    />
                </div>

                <div v-if="latestEnrollment" class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="font-medium text-gray-900">Promote to next class</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Latest: {{ latestEnrollment.grade_level?.name }} ({{ latestEnrollment.academic_year?.name }}).
                        <span v-if="nextGrade">Suggested next: {{ nextGrade.name }}.</span>
                    </p>

                    <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                        <div>
                            <InputLabel value="Academic year" />
                            <select v-model="form.academic_year_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option v-for="year in academicYears" :key="year.id" :value="year.id">
                                    {{ year.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Class" />
                            <select v-model="form.grade_level_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option v-for="grade in gradeLevels" :key="grade.id" :value="grade.id">
                                    {{ grade.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Board" />
                            <select v-model="form.board_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option v-for="board in boards" :key="board.id" :value="board.id">
                                    {{ board.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="School" />
                            <TextInput v-model="form.school_name" class="mt-1 block w-full" />
                        </div>
                        <div class="sm:col-span-2">
                            <PrimaryButton :disabled="form.processing">Promote student</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
