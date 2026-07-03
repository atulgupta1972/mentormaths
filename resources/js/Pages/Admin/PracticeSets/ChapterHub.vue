<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    chapter: Object,
    gradeLevel: Object,
    boardCode: String,
    activeYear: Object,
    topics: Array,
    chapterTests: Array,
    students: Array,
    selectedStudentId: [Number, null],
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
const assignStudentId = ref('');
const bulkGradeId = ref('');
const questionsPerTopic = ref(2);
const maxTotal = ref('');

const assignForm = useForm({ student_id: '', target_date: '', notes: '' });
const bulkForm = useForm({ grade_level_id: '', target_date: '', notes: '' });
const autoMixForm = useForm({ questions_per_topic: 2, max_total: '', status: 'published' });

const onStudentChange = () => {
    router.get(
        route('admin.practice-sets.chapters.show', props.chapter.id),
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

const autoMix = () => {
    autoMixForm.questions_per_topic = Number(questionsPerTopic.value) || 2;
    autoMixForm.max_total = maxTotal.value ? Number(maxTotal.value) : '';
    autoMixForm.post(route('admin.practice-sets.chapters.auto-mix', props.chapter.id));
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
    <Head :title="`Ch ${chapter.chapter_number} — Chapter tests`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link
                        v-if="gradeLevel"
                        :href="route('admin.questions.chapters.show', chapter.id)"
                        class="text-sm text-indigo-600"
                    >
                        ← Topic sets
                    </Link>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ boardCode }} {{ gradeLevel?.name }} · Ch {{ chapter.chapter_number }} · {{ chapter.name }}
                    </p>
                    <h2 class="text-xl font-semibold text-gray-800">Chapter tests (mixed topics)</h2>
                    <p class="mt-1 text-xs text-gray-500">T711 = Chapter test · Class 7 · Ch 1 · Test 1 — questions from all topics</p>
                </div>
                <Link
                    :href="route('admin.practice-sets.chapters.create', chapter.id)"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    + Build test manually
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

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ chapter.topics_count }}</p>
                        <p class="text-xs text-gray-500">Topics in chapter</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ chapter.questions_count }}</p>
                        <p class="text-xs text-gray-500">Questions available</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ chapterTests.length }}</p>
                        <p class="text-xs text-gray-500">Chapter tests</p>
                    </div>
                </div>

                <div class="rounded-lg border border-sky-200 bg-sky-50 p-6">
                    <h3 class="font-medium text-sky-900">Quick build — auto-mix from all topics</h3>
                    <p class="mt-1 text-sm text-sky-800">
                        Picks questions from every topic in this chapter (e.g. 2 per topic).
                    </p>
                    <div class="mt-4 flex flex-wrap items-end gap-4">
                        <div>
                            <InputLabel value="Questions per topic" class="!text-xs" />
                            <input v-model.number="questionsPerTopic" type="number" min="1" max="20" class="mt-1 w-24 rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <InputLabel value="Max total (optional)" class="!text-xs" />
                            <input v-model="maxTotal" type="number" min="1" max="100" placeholder="All" class="mt-1 w-24 rounded-md border-gray-300 text-sm" />
                        </div>
                        <PrimaryButton type="button" :disabled="autoMixForm.processing || chapter.questions_count === 0" @click="autoMix">
                            {{ autoMixForm.processing ? 'Building…' : 'Auto-mix & publish' }}
                        </PrimaryButton>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <div class="lg:col-span-2 space-y-4">
                        <p v-if="chapterTests.length === 0" class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-6 text-center text-sm text-gray-500">
                            No chapter tests yet. Use auto-mix above or build manually.
                        </p>

                        <div v-for="set in chapterTests" :key="set.id" class="rounded-lg border border-sky-200 bg-white p-4 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <Link :href="route('admin.questions.sets.show', set.id)" class="font-mono text-xl font-bold text-sky-700 hover:underline">
                                        {{ set.set_code }}
                                    </Link>
                                    <span class="ml-2 text-sm text-gray-600">Chapter test · {{ set.questions_count }} sums</span>
                                    <span v-if="set.status === 'draft'" class="ml-2 text-xs text-amber-700">Draft</span>
                                </div>
                                <span
                                    v-if="selectedStudent && set.student_progress"
                                    class="rounded-full px-3 py-1 text-xs font-medium"
                                    :class="progressLabel(set.student_progress).class"
                                >
                                    {{ progressLabel(set.student_progress).label }}
                                </span>
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
                            <h3 class="font-semibold text-gray-800">Topics covered</h3>
                            <ul class="mt-2 space-y-1 text-sm text-gray-600">
                                <li v-for="t in topics" :key="t.id">{{ t.name }} ({{ t.questions_count }})</li>
                            </ul>
                        </div>
                        <div class="rounded-lg bg-white p-4 shadow-sm">
                            <h3 class="font-semibold text-gray-800">Track student</h3>
                            <select v-model="selectedStudent" class="mt-2 w-full rounded-md border-gray-300 text-sm" @change="onStudentChange">
                                <option value="">—</option>
                                <option v-for="s in students" :key="s.id" :value="s.id">{{ s.label || s.name }}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
