<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { formatScoreLabel } from '@/utils/scores';

const props = defineProps({
    sheet: { type: Object, required: true },
    students: { type: Array, default: () => [] },
    selectedStudentId: { type: [Number, null], default: null },
    studentProgress: { type: Object, default: null },
    assignments: { type: Array, default: () => [] },
    activeYear: { type: Object, default: null },
    gradeLevels: { type: Array, default: () => [] },
});

const page = usePage();
const regenerateForm = useForm({});
const verifyForm = useForm({});
const rejectForm = useForm({});

const defaultTargetDate = () => {
    const d = new Date();
    d.setDate(d.getDate() + 7);
    return d.toISOString().slice(0, 10);
};

const selectedStudent = ref(props.selectedStudentId || '');
const targetDate = ref(defaultTargetDate());
const bulkTargetDate = ref(defaultTargetDate());
const assignStudentId = ref('');
const bulkGradeId = ref('');

const assignForm = useForm({ student_id: '', target_date: '', notes: '' });
const bulkForm = useForm({ grade_level_id: '', target_date: '', notes: '' });
const reassignForm = useForm({ target_date: '', notes: '' });

const regenerate = () => {
    regenerateForm.post(route('admin.written-sheets.regenerate', props.sheet.id), { preserveScroll: true });
};

const verify = () => {
    if (!confirm('Verify this sheet? Students can be assigned after verification.')) {
        return;
    }

    verifyForm.post(route('admin.written-sheets.verify', props.sheet.id), { preserveScroll: true });
};

const reject = () => {
    rejectForm.post(route('admin.written-sheets.reject', props.sheet.id), { preserveScroll: true });
};

const onStudentChange = () => {
    router.get(
        route('admin.written-sheets.show', props.sheet.id),
        { student_id: selectedStudent.value || undefined },
        { preserveState: true, preserveScroll: true },
    );
};

const assignSheet = () => {
    assignForm.student_id = assignStudentId.value;
    assignForm.target_date = targetDate.value;
    assignForm.post(route('admin.practice-sets.assign', props.sheet.id), { preserveScroll: true });
};

const assignBulk = () => {
    bulkForm.grade_level_id = bulkGradeId.value || '';
    bulkForm.target_date = bulkTargetDate.value;
    bulkForm.post(route('admin.practice-sets.assign-bulk', props.sheet.id), { preserveScroll: true });
};

const reassign = (assignmentId) => {
    if (!confirm('Re-assign this sheet? Student can upload again with a new target date.')) {
        return;
    }

    reassignForm.target_date = targetDate.value;
    reassignForm.post(route('admin.set-assignments.reassign', assignmentId), { preserveScroll: true });
};

const formatDate = (d) => {
    if (!d) {
        return '—';
    }

    return new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
};

const progressLabel = (p) => {
    if (!p) {
        return { label: 'Not assigned', class: 'bg-gray-100 text-gray-600' };
    }

    if (p.written_submission_status === 'graded' && p.latest_score != null) {
        return {
            label: p.latest_score_label || formatScoreLabel(p.latest_score, p.latest_max_score),
            class: 'bg-green-100 text-green-800',
        };
    }

    if (p.written_submission_status === 'processing' || p.written_submission_status === 'uploaded') {
        return { label: 'AI checking…', class: 'bg-yellow-100 text-yellow-800' };
    }

    if (p.written_submission_status === 'failed') {
        return { label: 'Check failed', class: 'bg-rose-100 text-rose-800' };
    }

    if (p.is_overdue) {
        return { label: 'Overdue', class: 'bg-red-100 text-red-800' };
    }

    return { label: 'Assigned', class: 'bg-blue-100 text-blue-800' };
};
</script>

<template>
    <Head :title="sheet.set_code" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        <span class="font-mono text-indigo-600">{{ sheet.set_code }}</span>
                        · {{ sheet.kind_label }}
                    </h2>
                    <p class="text-sm text-gray-500">{{ sheet.chapter_name }}<span v-if="sheet.topic_name"> · {{ sheet.topic_name }}</span></p>
                </div>
                <Link :href="route('admin.written-sheets.index')" class="text-sm text-indigo-600">Back</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>
                <div v-if="page.props.flash?.warning" class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {{ page.props.flash.warning }}
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700">
                            {{ sheet.written_status_label }}
                        </span>
                        <span class="text-sm text-gray-600">{{ sheet.questions_count }} sums</span>
                        <a
                            v-if="sheet.written_pdf_url"
                            :href="route('admin.written-sheets.download', sheet.id)"
                            class="text-sm font-medium text-indigo-600 hover:underline"
                            target="_blank"
                        >
                            Download PDF
                        </a>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <PrimaryButton
                            v-if="sheet.written_status === 'pending_review'"
                            type="button"
                            :disabled="verifyForm.processing"
                            @click="verify"
                        >
                            Verify sheet
                        </PrimaryButton>
                        <SecondaryButton type="button" :disabled="regenerateForm.processing" @click="regenerate">
                            Regenerate PDF
                        </SecondaryButton>
                        <DangerButton
                            v-if="sheet.written_status !== 'draft'"
                            type="button"
                            :disabled="rejectForm.processing"
                            @click="reject"
                        >
                            Send back to draft
                        </DangerButton>
                    </div>

                    <p class="mt-3 text-sm text-gray-600">
                        Step 2: check the PDF below. Step 3: verify, then assign to students below (same as online practice sets).
                    </p>
                </div>

                <div
                    v-if="sheet.can_assign"
                    id="assign"
                    class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-indigo-200"
                >
                    <h3 class="font-semibold text-gray-900">Assign to students</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Pick a student and target date, or assign in bulk to a class. Students download the PDF, write answers on a numbered answer sheet, then upload for AI checking.
                    </p>

                    <div class="mt-4 grid gap-6 lg:grid-cols-3">
                        <div class="lg:col-span-2 space-y-4">
                            <div class="rounded-md border border-gray-200 p-4">
                                <div class="flex flex-wrap items-end gap-3">
                                    <div>
                                        <InputLabel value="Student" class="!text-xs" />
                                        <select v-model="assignStudentId" class="mt-1 rounded-md border-gray-300 text-sm">
                                            <option value="">Select</option>
                                            <option v-for="s in students" :key="s.id" :value="s.id">{{ s.label || s.name }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel value="Target date" class="!text-xs" />
                                        <input v-model="targetDate" type="date" class="mt-1 rounded-md border-gray-300 text-sm" />
                                    </div>
                                    <PrimaryButton
                                        type="button"
                                        class="!py-2"
                                        :disabled="!assignStudentId || !targetDate || assignForm.processing"
                                        @click="assignSheet"
                                    >
                                        Assign
                                    </PrimaryButton>
                                </div>

                                <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4">
                                    <div>
                                        <InputLabel value="Bulk class" class="!text-xs" />
                                        <select v-model="bulkGradeId" class="mt-1 rounded-md border-gray-300 text-sm">
                                            <option value="">All students</option>
                                            <option v-for="g in gradeLevels" :key="g.id" :value="g.id">{{ g.name }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel value="Target date" class="!text-xs" />
                                        <input v-model="bulkTargetDate" type="date" class="mt-1 rounded-md border-gray-300 text-sm" />
                                    </div>
                                    <SecondaryButton
                                        type="button"
                                        class="!py-2"
                                        :disabled="!bulkTargetDate || bulkForm.processing"
                                        @click="assignBulk"
                                    >
                                        Assign bulk
                                    </SecondaryButton>
                                </div>
                            </div>

                            <div v-if="selectedStudent && studentProgress" class="rounded-md bg-gray-50 p-4 text-sm">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="font-medium text-gray-900">Selected student progress</p>
                                    <span
                                        class="rounded-full px-3 py-1 text-xs font-medium"
                                        :class="progressLabel(studentProgress).class"
                                    >
                                        {{ progressLabel(studentProgress).label }}
                                    </span>
                                </div>
                                <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-xs text-gray-500">Target date</dt>
                                        <dd class="font-medium">{{ formatDate(studentProgress.target_date) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-gray-500">Upload / graded</dt>
                                        <dd class="font-medium">{{ studentProgress.submitted_at ? formatDate(studentProgress.submitted_at.slice(0, 10)) : '—' }}</dd>
                                    </div>
                                </dl>
                                <button
                                    type="button"
                                    class="mt-3 text-indigo-600 hover:underline"
                                    @click="reassign(studentProgress.assignment_id)"
                                >
                                    Re-assign
                                </button>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div class="rounded-md border border-gray-200 p-4">
                                <h4 class="font-medium text-gray-800">Filter by student</h4>
                                <select v-model="selectedStudent" class="mt-2 w-full rounded-md border-gray-300 text-sm" @change="onStudentChange">
                                    <option value="">—</option>
                                    <option v-for="s in students" :key="s.id" :value="s.id">{{ s.label || s.name }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div v-if="assignments.length" class="mt-6">
                        <h4 class="text-sm font-semibold text-gray-800">Current assignments ({{ assignments.length }})</h4>
                        <div class="mt-2 overflow-hidden rounded-md border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium text-gray-600">Student</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-600">Target</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-600">Status</th>
                                        <th class="px-3 py-2 text-right font-medium text-gray-600">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="row in assignments" :key="row.assignment_id">
                                        <td class="px-3 py-2">{{ row.student_name }}</td>
                                        <td class="px-3 py-2">{{ formatDate(row.target_date) }}</td>
                                        <td class="px-3 py-2">
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                                :class="progressLabel(row).class"
                                            >
                                                {{ progressLabel(row).label }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <button type="button" class="text-indigo-600 hover:underline" @click="reassign(row.assignment_id)">
                                                Re-assign
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div v-else-if="sheet.written_status === 'pending_review'" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    Verify this sheet first — then you can assign it to students here.
                </div>

                <div v-if="sheet.written_pdf_url" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <iframe :src="sheet.written_pdf_url" class="h-[720px] w-full" title="Written sheet preview" />
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <h3 class="font-medium text-gray-900">Questions on this sheet</h3>
                    <ol class="mt-3 space-y-3">
                        <li v-for="question in sheet.questions" :key="question.id" class="text-sm">
                            <span class="font-semibold text-gray-900">Q{{ question.number }}.</span>
                            <span class="text-gray-700" v-html="question.question_text" />
                            <div class="mt-1 text-xs text-gray-500">Answer: {{ question.correct_answer || '—' }}</div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
