<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    topic: Object,
    practiceSets: Array,
    students: Array,
    selectedStudentId: [Number, null],
    activeYear: Object,
    gradeLevels: Array,
});

const page = usePage();
const selectedStudent = ref(props.selectedStudentId || '');

const defaultTargetDate = () => {
    const d = new Date();
    d.setDate(d.getDate() + 7);
    return d.toISOString().slice(0, 10);
};

const targetDate = ref(defaultTargetDate());
const bulkTargetDate = ref(defaultTargetDate());
const reassignTargetDate = ref(defaultTargetDate());
const assignStudentId = ref('');
const bulkGradeId = ref('');
const reassignNotes = ref('');

const assignForm = useForm({ student_id: '', target_date: '', notes: '' });
const bulkForm = useForm({ grade_level_id: '', target_date: '', notes: '' });
const reassignForm = useForm({ target_date: '', notes: '' });

const onStudentChange = () => {
    router.get(
        route('admin.practice-sets.topics.show', props.topic.id),
        { student_id: selectedStudent.value || undefined },
        { preserveState: true },
    );
};

const assignSet = (setId) => {
    assignForm.student_id = assignStudentId.value;
    assignForm.target_date = targetDate.value;
    assignForm.post(route('admin.practice-sets.assign', setId), { preserveScroll: true });
};

const assignBulk = (setId) => {
    bulkForm.grade_level_id = bulkGradeId.value || '';
    bulkForm.target_date = bulkTargetDate.value;
    bulkForm.post(route('admin.practice-sets.assign-bulk', setId), { preserveScroll: true });
};

const reassign = (assignmentId) => {
    if (!confirm('Re-assign this set? Previous scores are kept. Student can attempt again.')) {
        return;
    }
    reassignForm.target_date = reassignTargetDate.value;
    reassignForm.notes = reassignNotes.value;
    reassignForm.post(route('admin.set-assignments.reassign', assignmentId), { preserveScroll: true });
};

const formatTime = (seconds) => {
    if (!seconds) return '—';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m ? `${m}m ${s}s` : `${s}s`;
};

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
};

const progressLabel = (p) => {
    if (!p) return { label: 'Not assigned', class: 'bg-gray-100 text-gray-600' };
    if (p.assignment_status === 'completed' && p.latest_score != null) {
        const late = p.submission_timing === 'late' ? ' · Delayed' : '';
        return { label: `${p.latest_score}/${p.latest_max_score}${late}`, class: p.submission_timing === 'late' ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-800' };
    }
    if (p.is_overdue) return { label: 'Overdue', class: 'bg-red-100 text-red-800' };
    if (p.assignment_status === 'in_progress') return { label: 'In progress', class: 'bg-yellow-100 text-yellow-800' };
    return { label: 'Assigned', class: 'bg-blue-100 text-blue-800' };
};
</script>

<template>
    <Head :title="`${topic.chapter_name} — ${topic.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-500">
                        {{ topic.board_code }} {{ topic.grade_name }} · {{ topic.chapter_name }}
                    </p>
                    <h2 class="text-xl font-semibold text-gray-800">{{ topic.name }}</h2>
                </div>
                <Link
                    :href="route('admin.practice-sets.create', { syllabus_topic_id: topic.id })"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    + New set
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 p-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 p-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <div class="lg:col-span-2 space-y-4">
                        <div v-for="set in practiceSets" :key="set.id" class="rounded-lg border bg-white p-4 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <span class="font-mono text-xl font-bold text-indigo-600">{{ set.set_code }}</span>
                                    <span class="ml-2 text-sm text-gray-600">{{ set.tier_label }} · {{ set.questions_count }} sums</span>
                                </div>
                                <span
                                    v-if="selectedStudent && set.student_progress"
                                    class="rounded-full px-3 py-1 text-xs font-medium"
                                    :class="progressLabel(set.student_progress).class"
                                >
                                    {{ progressLabel(set.student_progress).label }}
                                </span>
                            </div>

                            <div v-if="selectedStudent && set.student_progress" class="mt-4 rounded-md bg-gray-50 p-3 text-sm">
                                <dl class="grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-xs text-gray-500">Target date</dt>
                                        <dd class="font-medium">{{ formatDate(set.student_progress.target_date) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-gray-500">Submitted</dt>
                                        <dd class="font-medium">
                                            {{ set.student_progress.submitted_at ? formatDate(set.student_progress.submitted_at.slice(0, 10)) : '—' }}
                                            <span v-if="set.student_progress.submission_timing === 'late'" class="text-amber-700">(Delayed)</span>
                                            <span v-else-if="set.student_progress.submitted_at" class="text-green-700">(On time)</span>
                                        </dd>
                                    </div>
                                    <div v-if="set.student_progress.latest_score != null">
                                        <dt class="text-xs text-gray-500">Score</dt>
                                        <dd class="font-medium">{{ set.student_progress.latest_score }}/{{ set.student_progress.latest_max_score }} · {{ formatTime(set.student_progress.latest_time_seconds) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-gray-500">Attempts</dt>
                                        <dd class="font-medium">{{ set.student_progress.attempt_count }}</dd>
                                    </div>
                                </dl>
                                <div class="mt-3 flex flex-wrap gap-3">
                                    <Link
                                        :href="route('admin.set-assignments.show', set.student_progress.assignment_id)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        Full history
                                    </Link>
                                    <button type="button" class="text-indigo-600 hover:underline" @click="reassign(set.student_progress.assignment_id)">
                                        Re-assign
                                    </button>
                                </div>
                            </div>

                            <div v-if="set.status === 'published'" class="mt-4 space-y-3 border-t pt-4">
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
                                    <PrimaryButton type="button" class="!py-2" :disabled="!assignStudentId || !targetDate" @click="assignSet(set.id)">
                                        Assign
                                    </PrimaryButton>
                                </div>
                                <div class="flex flex-wrap items-end gap-3">
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
                                    <SecondaryButton type="button" class="!py-2" :disabled="!bulkTargetDate" @click="assignBulk(set.id)">
                                        Assign bulk
                                    </SecondaryButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="rounded-lg bg-white p-4 shadow-sm">
                            <h3 class="font-semibold text-gray-800">Student</h3>
                            <select v-model="selectedStudent" class="mt-2 w-full rounded-md border-gray-300 text-sm" @change="onStudentChange">
                                <option value="">—</option>
                                <option v-for="s in students" :key="s.id" :value="s.id">{{ s.label || s.name }}</option>
                            </select>
                        </div>
                        <div class="rounded-lg bg-white p-4 shadow-sm text-sm text-gray-600">
                            <p class="font-medium text-gray-800">Re-assign defaults</p>
                            <div class="mt-2">
                                <InputLabel value="New target date" class="!text-xs" />
                                <input v-model="reassignTargetDate" type="date" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                            </div>
                            <p class="mt-3 text-xs">Missed target → student can still submit. System marks <strong>Delayed</strong> vs on-time.</p>
                            <p class="mt-2 text-xs text-indigo-700">You can assign to any student — their class need not match this topic.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
