<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    selectedGrade: Object,
    gradeLevels: { type: Array, default: () => [] },
    studentOptions: { type: Array, default: () => [] },
    chapters: { type: Array, default: () => [] },
    topics: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    weakStudents: { type: Array, default: () => [] },
    recentCatchUps: { type: Array, default: () => [] },
    cursorPrompt: { type: String, default: null },
    selectedEnrollmentIds: { type: Array, default: () => [] },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);

const classId = ref(props.filters.grade_level_id ? String(props.filters.grade_level_id) : '');
const studentEnrollmentId = ref(
    props.filters.student_enrollment_id ? String(props.filters.student_enrollment_id) : '',
);
const chapterId = ref(props.filters.syllabus_chapter_id || '');
const topicId = ref(props.filters.syllabus_topic_id || '');
const selectedIds = ref([]);

const syncSelectionFromFilters = () => {
    const ids = props.weakStudents.map((s) => s.student_enrollment_id);

    if (props.filters.student_enrollment_id) {
        selectedIds.value = ids.filter((id) => id === props.filters.student_enrollment_id);
    } else if (props.filters.grade_level_id) {
        selectedIds.value = [...ids];
    } else if (props.selectedEnrollmentIds?.length) {
        selectedIds.value = props.selectedEnrollmentIds.filter((id) => ids.includes(id));
        if (!selectedIds.value.length && ids.length) {
            selectedIds.value = [...ids];
        }
    } else {
        selectedIds.value = [];
    }
};

watch(
    () => [props.weakStudents, props.filters, props.selectedEnrollmentIds],
    () => syncSelectionFromFilters(),
    { immediate: true, deep: true },
);

watch(
    () => props.filters.grade_level_id,
    (id) => {
        classId.value = id ? String(id) : '';
    },
);

watch(
    () => props.filters.student_enrollment_id,
    (id) => {
        studentEnrollmentId.value = id ? String(id) : '';
    },
);

const dueDateDefault = () => {
    const d = new Date();
    d.setDate(d.getDate() + 7);

    return d.toISOString().slice(0, 10);
};

const promptForm = useForm({
    enrollment_ids: selectedIds.value,
    syllabus_chapter_id: props.filters.syllabus_chapter_id || '',
    syllabus_topic_id: props.filters.syllabus_topic_id || '',
});

const importForm = useForm({
    enrollment_ids: selectedIds.value,
    syllabus_chapter_id: props.filters.syllabus_chapter_id || '',
    syllabus_topic_id: props.filters.syllabus_topic_id || '',
    json: '',
    due_date: dueDateDefault(),
});

const jsonFileInput = ref(null);
const jsonFileName = ref('');
const jsonUploadError = ref('');

const filterParams = () => ({
    grade_level_id: classId.value || undefined,
    student_enrollment_id: studentEnrollmentId.value || undefined,
    syllabus_chapter_id: chapterId.value || undefined,
    syllabus_topic_id: topicId.value || undefined,
});

const applyFilters = () => {
    router.get(route('admin.catch-up.index'), filterParams(), {
        preserveState: true,
        replace: true,
    });
};

watch(classId, (value, oldValue) => {
    if (value === oldValue) {
        return;
    }
    studentEnrollmentId.value = '';
    applyFilters();
});

watch(studentEnrollmentId, (value, oldValue) => {
    if (value === oldValue) {
        return;
    }
    applyFilters();
});

watch(chapterId, (value, oldValue) => {
    if (value === oldValue) {
        return;
    }
    topicId.value = '';
    applyFilters();
});

watch(topicId, (value, oldValue) => {
    if (value === oldValue) {
        return;
    }
    applyFilters();
});

const allSelected = computed(() =>
    props.weakStudents.length > 0
    && props.weakStudents.every((s) => selectedIds.value.includes(s.student_enrollment_id)),
);

const selectedWeakCount = computed(() =>
    props.weakStudents
        .filter((s) => selectedIds.value.includes(s.student_enrollment_id))
        .reduce((sum, s) => sum + s.weak_count, 0),
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
    promptForm.enrollment_ids = selectedIds.value;
    promptForm.syllabus_chapter_id = chapterId.value || '';
    promptForm.syllabus_topic_id = topicId.value || '';
    importForm.enrollment_ids = selectedIds.value;
    importForm.syllabus_chapter_id = chapterId.value || '';
    importForm.syllabus_topic_id = topicId.value || '';
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
            jsonFileName.value = '';
            jsonUploadError.value = '';
        },
    });
};

const onJsonFileSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }

    jsonUploadError.value = '';

    const reader = new FileReader();
    reader.onload = () => {
        importForm.json = String(reader.result || '');
        jsonFileName.value = file.name;
        importForm.clearErrors('json');
    };
    reader.onerror = () => {
        jsonUploadError.value = 'Could not read that file. Try another .json file.';
    };
    reader.readAsText(file);
    event.target.value = '';
};

const clearImportedJson = () => {
    importForm.json = '';
    jsonFileName.value = '';
    jsonUploadError.value = '';
    importForm.clearErrors('json');
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

const clearFilters = () => {
    classId.value = '';
    studentEnrollmentId.value = '';
    chapterId.value = '';
    topicId.value = '';
    router.get(route('admin.catch-up.index'), {}, { preserveState: true, replace: true });
};

const hasScopeFilter = computed(() =>
    Boolean(classId.value || studentEnrollmentId.value || chapterId.value || topicId.value),
);
</script>

<template>
    <Head title="Catch-up Sets" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Catch-up Sets</h2>
                    <p class="text-sm text-gray-500">
                        Filter by class or student, then generate a Cursor prompt for the selected students only.
                    </p>
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

                <section class="rounded-xl border border-indigo-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">
                                1. Students needing catch-up · {{ weakStudents.length }}
                            </h3>
                            <p class="text-xs text-gray-500">
                                Wrong first try, used hint, or asked for help — including finished questions in sets still in progress.
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                v-if="weakStudents.length"
                                type="button"
                                class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                                @click="toggleAll"
                            >
                                {{ allSelected ? 'Clear all' : 'Select all shown' }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-3 grid gap-3 rounded-lg border border-dashed border-gray-200 bg-gray-50 p-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-medium uppercase text-gray-500">Filter class</label>
                            <select
                                v-model="classId"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"
                            >
                                <option value="">All classes</option>
                                <option
                                    v-for="grade in gradeLevels"
                                    :key="grade.id"
                                    :value="grade.id"
                                >
                                    {{ grade.name }} ({{ grade.weak_student_count }})
                                </option>
                            </select>
                            <p class="mt-1 text-[11px] text-gray-500">
                                Selecting a class shows and selects all its students.
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium uppercase text-gray-500">Filter student (optional)</label>
                            <select
                                v-model="studentEnrollmentId"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"
                                :disabled="!studentOptions.length"
                            >
                                <option value="">All students{{ classId ? ' in class' : '' }}</option>
                                <option
                                    v-for="student in studentOptions"
                                    :key="student.student_enrollment_id"
                                    :value="student.student_enrollment_id"
                                >
                                    {{ student.student_name }}{{ student.grade_name && !classId ? ` · ${student.grade_name}` : '' }}
                                </option>
                            </select>
                            <p class="mt-1 text-[11px] text-gray-500">
                                Pick one student to create a catch-up set only for them.
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium uppercase text-gray-500">Filter chapter (optional)</label>
                            <select
                                v-model="chapterId"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"
                            >
                                <option value="">All chapters</option>
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
                            <label class="block text-xs font-medium uppercase text-gray-500">Filter topic (optional)</label>
                            <div class="mt-1 flex gap-2">
                                <select
                                    v-model="topicId"
                                    class="w-full rounded-md border-gray-300 text-sm shadow-sm"
                                    :disabled="!chapterId"
                                >
                                    <option value="">All topics</option>
                                    <option
                                        v-for="t in topics"
                                        :key="t.id"
                                        :value="t.id"
                                    >
                                        {{ t.name }}
                                    </option>
                                </select>
                                <button
                                    v-if="hasScopeFilter"
                                    type="button"
                                    class="shrink-0 text-xs font-semibold text-indigo-600 hover:underline"
                                    @click="clearFilters"
                                >
                                    Clear
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-if="weakStudents.length" class="mt-4 space-y-2">
                        <label
                            v-for="student in weakStudents"
                            :key="student.student_enrollment_id"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition"
                            :class="selectedIds.includes(student.student_enrollment_id)
                                ? 'border-indigo-300 bg-indigo-50/60'
                                : 'border-gray-100 bg-gray-50 hover:border-indigo-200'"
                        >
                            <input
                                type="checkbox"
                                class="mt-1 rounded border-gray-300 text-indigo-600"
                                :checked="selectedIds.includes(student.student_enrollment_id)"
                                @change="toggleStudent(student.student_enrollment_id)"
                            >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-2">
                                    <p class="text-sm font-semibold text-gray-900">{{ student.student_name }}</p>
                                    <span v-if="student.grade_name" class="text-xs text-gray-500">{{ student.grade_name }}</span>
                                    <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-semibold text-indigo-700 ring-1 ring-indigo-200">
                                        {{ student.weak_count }} weak sum{{ student.weak_count === 1 ? '' : 's' }}
                                    </span>
                                    <span
                                        v-if="student.pending_catch_up_count"
                                        class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-800 ring-1 ring-amber-200"
                                    >
                                        {{ student.pending_catch_up_count }} catch-up pending
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-gray-600">
                                    <span
                                        v-for="(topic, idx) in student.topics"
                                        :key="topic.topic_id"
                                    >
                                        <span v-if="idx > 0"> · </span>
                                        {{ topic.chapter_name ? `${topic.chapter_name} / ` : '' }}{{ topic.topic_name }} ({{ topic.weak_count }})
                                    </span>
                                </p>
                                <ul class="mt-2 space-y-1">
                                    <li
                                        v-for="item in student.items.slice(0, 4)"
                                        :key="item.question_id"
                                        class="text-xs text-gray-600"
                                    >
                                        <span class="font-mono text-indigo-600">{{ item.set_code }}</span>
                                        · {{ reasonLabel(item.reason) }}
                                        · <span class="line-clamp-1 inline">{{ item.question_text }}</span>
                                    </li>
                                    <li v-if="student.items.length > 4" class="text-xs text-gray-400">
                                        +{{ student.items.length - 4 }} more
                                    </li>
                                </ul>
                            </div>
                        </label>
                    </div>

                    <div
                        v-else
                        class="mt-4 rounded-lg border border-dashed border-gray-200 bg-gray-50 p-6 text-center"
                    >
                        <p class="text-sm font-medium text-gray-700">No students need catch-up right now</p>
                        <p class="mt-1 text-xs text-gray-500">
                            After students finish practice with wrongs / hints / help, they will appear here.
                            Try clearing filters or choosing a different class.
                        </p>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4">
                        <button
                            type="button"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                            :disabled="!selectedIds.length || promptForm.processing"
                            @click="generatePrompt"
                        >
                            {{ promptForm.processing ? 'Building…' : `Generate prompt for ${selectedIds.length || 0} student${selectedIds.length === 1 ? '' : 's'}` }}
                        </button>
                        <p class="text-xs text-gray-500">
                            {{ selectedWeakCount }} weak sum{{ selectedWeakCount === 1 ? '' : 's' }} selected · catch-up will be assigned only to checked students
                        </p>
                    </div>
                </section>

                <section v-if="cursorPrompt" class="rounded-xl border border-indigo-200 bg-indigo-50/40 p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-indigo-950">2. Copy prompt → Cursor</h3>
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
                </section>

                <section
                    v-if="cursorPrompt || selectedIds.length"
                    class="rounded-xl border border-indigo-200 bg-indigo-50/40 p-4 shadow-sm"
                >
                    <h3 class="text-sm font-semibold text-indigo-950">
                        {{ cursorPrompt ? '3. Import catch-up JSON' : '2. Import catch-up JSON' }}
                    </h3>
                    <p class="mt-1 text-xs text-indigo-900/70">
                        Paste JSON from Cursor or upload a <strong>.json</strong> file, then create sets for the selected students.
                    </p>

                    <form class="mt-4 space-y-3" @submit.prevent="importSets">
                        <div>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <label class="block text-xs font-medium uppercase text-gray-600">Paste or upload JSON</label>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        class="rounded-md border border-indigo-300 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50"
                                        @click="jsonFileInput?.click()"
                                    >
                                        Upload .json
                                    </button>
                                    <button
                                        v-if="importForm.json.trim()"
                                        type="button"
                                        class="text-xs font-semibold text-gray-500 hover:text-gray-700"
                                        @click="clearImportedJson"
                                    >
                                        Clear
                                    </button>
                                </div>
                            </div>
                            <input
                                ref="jsonFileInput"
                                type="file"
                                accept=".json,application/json"
                                class="hidden"
                                @change="onJsonFileSelected"
                            >
                            <p v-if="jsonFileName" class="mt-1 text-xs text-emerald-700">
                                Loaded from {{ jsonFileName }}
                            </p>
                            <textarea
                                v-model="importForm.json"
                                rows="10"
                                class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs shadow-sm"
                                placeholder='{"students":[{"student_enrollment_id":…,"variants":[…]}]}'
                            />
                            <p v-if="jsonUploadError" class="mt-1 text-xs text-rose-600">{{ jsonUploadError }}</p>
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
                            :disabled="importForm.processing || !importForm.json.trim() || !selectedIds.length"
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
