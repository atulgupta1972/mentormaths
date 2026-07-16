<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    selectedGrade: Object,
    chapters: { type: Array, default: () => [] },
    topics: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    topic: Object,
    weakStudents: { type: Array, default: () => [] },
    recentCatchUps: { type: Array, default: () => [] },
    cursorPrompt: { type: String, default: null },
    selectedEnrollmentIds: { type: Array, default: () => [] },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);

const chapterId = ref(props.filters.syllabus_chapter_id || '');
const topicId = ref(props.filters.syllabus_topic_id || '');
const selectedIds = ref(
    props.selectedEnrollmentIds?.length
        ? [...props.selectedEnrollmentIds]
        : props.weakStudents.map((s) => s.student_enrollment_id),
);

watch(
    () => props.weakStudents,
    (rows) => {
        if (!props.selectedEnrollmentIds?.length) {
            selectedIds.value = rows.map((s) => s.student_enrollment_id);
        }
    },
);

const dueDateDefault = () => {
    const d = new Date();
    d.setDate(d.getDate() + 7);

    return d.toISOString().slice(0, 10);
};

const promptForm = useForm({
    syllabus_topic_id: props.filters.syllabus_topic_id || '',
    enrollment_ids: selectedIds.value,
});

const importForm = useForm({
    syllabus_topic_id: props.filters.syllabus_topic_id || '',
    enrollment_ids: selectedIds.value,
    json: '',
    due_date: dueDateDefault(),
});

const applyFilters = () => {
    router.get(route('admin.catch-up.index'), {
        syllabus_chapter_id: chapterId.value || undefined,
        syllabus_topic_id: topicId.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

watch(chapterId, () => {
    topicId.value = '';
    applyFilters();
});

watch(topicId, () => {
    if (topicId.value) {
        applyFilters();
    }
});

const allSelected = computed(() =>
    props.weakStudents.length > 0
    && props.weakStudents.every((s) => selectedIds.value.includes(s.student_enrollment_id)),
);

const toggleAll = () => {
    selectedIds.value = allSelected.value
        ? []
        : props.weakStudents.map((s) => s.student_enrollment_id);
};

const toggleStudent = (id) => {
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((x) => x !== id);
    } else {
        selectedIds.value = [...selectedIds.value, id];
    }
};

const syncForms = () => {
    promptForm.syllabus_topic_id = topicId.value;
    promptForm.enrollment_ids = selectedIds.value;
    importForm.syllabus_topic_id = topicId.value;
    importForm.enrollment_ids = selectedIds.value;
};

const generatePrompt = () => {
    syncForms();
    promptForm.post(route('admin.catch-up.prompt'));
};

const importSets = () => {
    syncForms();
    importForm.post(route('admin.catch-up.import'), {
        onSuccess: () => {
            importForm.json = '';
        },
    });
};

const copyPrompt = async () => {
    if (!props.cursorPrompt) {
        return;
    }

    await navigator.clipboard.writeText(props.cursorPrompt);
};

const reasonLabel = (reason) => ({
    asked_help: 'Asked help',
    used_hint: 'Used hint',
    corrected_after_help: 'Corrected after help',
    wrong_first_try: 'Wrong first try',
}[reason] || reason);
</script>

<template>
    <Head title="Catch-up Sets" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Catch-up Sets</h2>
                    <p class="text-sm text-gray-500">
                        Topic-wise variants for students who got wrong, used a hint, or asked for help — one Cursor prompt for all selected students.
                    </p>
                    <p v-if="selectedGrade" class="text-sm text-indigo-600">Showing: {{ selectedGrade.name }}</p>
                </div>
                <Link :href="route('admin.practice-sets.index')" class="text-sm text-indigo-600 hover:underline">
                    ← Practice sets
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="flashSuccess"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
                >
                    {{ flashSuccess }}
                </div>
                <div
                    v-if="flashError"
                    class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
                >
                    {{ flashError }}
                </div>

                <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <h3 class="text-sm font-semibold text-gray-900">1. Choose topic</h3>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-medium uppercase text-gray-500">Chapter</label>
                            <select
                                v-model="chapterId"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"
                            >
                                <option value="">Select chapter</option>
                                <option
                                    v-for="ch in chapters"
                                    :key="ch.id"
                                    :value="ch.id"
                                >
                                    {{ ch.grade_name ? `${ch.grade_name} · ` : '' }}Ch {{ ch.chapter_number }} — {{ ch.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium uppercase text-gray-500">Topic</label>
                            <select
                                v-model="topicId"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"
                                :disabled="!chapterId"
                            >
                                <option value="">Select topic</option>
                                <option
                                    v-for="t in topics"
                                    :key="t.id"
                                    :value="t.id"
                                >
                                    {{ t.name }}
                                </option>
                            </select>
                        </div>
                    </div>
                </section>

                <section v-if="topic" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">2. Students with weak sums</h3>
                            <p class="text-xs text-gray-500">{{ topic.chapter_name }} — {{ topic.name }}</p>
                        </div>
                        <button
                            v-if="weakStudents.length"
                            type="button"
                            class="text-xs font-semibold text-indigo-600 hover:underline"
                            @click="toggleAll"
                        >
                            {{ allSelected ? 'Clear all' : 'Select all' }}
                        </button>
                    </div>

                    <div v-if="weakStudents.length" class="mt-3 space-y-2">
                        <label
                            v-for="student in weakStudents"
                            :key="student.student_enrollment_id"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-100 bg-gray-50 p-3 hover:border-indigo-200"
                        >
                            <input
                                type="checkbox"
                                class="mt-1 rounded border-gray-300 text-indigo-600"
                                :checked="selectedIds.includes(student.student_enrollment_id)"
                                @change="toggleStudent(student.student_enrollment_id)"
                            >
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-gray-900">
                                    {{ student.student_name }}
                                    <span class="ml-1 font-normal text-gray-500">· {{ student.weak_count }} sum{{ student.weak_count === 1 ? '' : 's' }}</span>
                                </p>
                                <ul class="mt-1 space-y-1">
                                    <li
                                        v-for="item in student.items"
                                        :key="item.question_id"
                                        class="text-xs text-gray-600"
                                    >
                                        <span class="font-mono text-indigo-600">{{ item.set_code }}</span>
                                        · {{ reasonLabel(item.reason) }}
                                        · <span class="line-clamp-1 inline">{{ item.question_text }}</span>
                                    </li>
                                </ul>
                            </div>
                        </label>
                    </div>

                    <p v-else class="mt-3 rounded-lg border border-dashed border-gray-200 bg-gray-50 p-4 text-center text-sm text-gray-500">
                        No pending weak sums for this topic (or catch-up already covers them).
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                            :disabled="!selectedIds.length || promptForm.processing"
                            @click="generatePrompt"
                        >
                            {{ promptForm.processing ? 'Building…' : 'Generate one prompt for selected' }}
                        </button>
                        <p class="text-xs text-gray-500">Creates one Cursor prompt covering all selected students.</p>
                    </div>
                </section>

                <section v-if="cursorPrompt" class="rounded-xl border border-indigo-200 bg-indigo-50/40 p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-indigo-950">3. Copy prompt → Cursor → paste JSON</h3>
                        <button
                            type="button"
                            class="rounded-md border border-indigo-300 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50"
                            @click="copyPrompt"
                        >
                            Copy prompt
                        </button>
                    </div>
                    <textarea
                        class="mt-3 h-40 w-full rounded-md border-indigo-200 bg-white font-mono text-xs shadow-sm"
                        readonly
                        :value="cursorPrompt"
                    />

                    <form class="mt-4 space-y-3" @submit.prevent="importSets">
                        <div>
                            <label class="block text-xs font-medium uppercase text-gray-600">Paste Cursor JSON</label>
                            <textarea
                                v-model="importForm.json"
                                rows="10"
                                class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs shadow-sm"
                                placeholder='{"students":[{"student_enrollment_id":…,"variants":[…]}]}'
                                required
                            />
                            <p v-if="importForm.errors.json" class="mt-1 text-xs text-rose-600">{{ importForm.errors.json }}</p>
                        </div>
                        <div class="max-w-xs">
                            <label class="block text-xs font-medium uppercase text-gray-600">Due date</label>
                            <input
                                v-model="importForm.due_date"
                                type="date"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"
                                required
                            >
                        </div>
                        <button
                            type="submit"
                            class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                            :disabled="importForm.processing || !importForm.json.trim()"
                        >
                            {{ importForm.processing ? 'Creating…' : 'Create & assign catch-up sets' }}
                        </button>
                    </form>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <h3 class="text-sm font-semibold text-gray-900">Recent catch-up sets</h3>
                    <div v-if="recentCatchUps.length" class="mt-3 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Code</th>
                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Student</th>
                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Topic</th>
                                    <th class="px-3 py-2 text-left text-xs uppercase text-gray-500">Sums</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="set in recentCatchUps" :key="set.id">
                                    <td class="px-3 py-2 font-mono font-semibold text-indigo-600">
                                        <Link :href="route('admin.practice-sets.show', set.id)" class="hover:underline">
                                            {{ set.set_code }}
                                        </Link>
                                    </td>
                                    <td class="px-3 py-2">{{ set.student_name || '—' }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ set.topic_name || '—' }}</td>
                                    <td class="px-3 py-2">{{ set.questions_count }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="mt-3 text-sm text-gray-500">No catch-up sets yet.</p>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
