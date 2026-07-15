<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    version: Object,
    rows: Array,
    academicYears: Array,
    chapterHeads: Array,
});

const search = ref('');
const chapterHeadsList = ref([...(props.chapterHeads || [])]);
const addingHead = ref(false);
const newHeadName = ref('');
const addHeadError = ref('');
const addHeadSaving = ref(false);
const targetRowIndex = ref(null);
const newHeadInput = ref(null);
const isAdmin = computed(() => usePage().props.auth?.isAdmin ?? false);

const readOnlyFilteredRows = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.rows.filter((row) => query === '' || rowMatchesSearch(row, query));
});

const form = useForm({
    rows: props.rows.length
        ? props.rows.map((row) => ({ ...row }))
        : [emptyRow()],
});

const carryForm = useForm({
    academic_year_id: '',
});

const quickTopicForm = useForm({
    mode: 'existing',
    chapter_key: '',
    chapter_id: '',
    chapter_number: '',
    chapter_name: '',
    chapter_head_id: '',
    topic_name: '',
    learning_outcomes: '',
    difficulty: '',
    planned_periods: '',
});

const importForm = useForm({
    file: null,
});

watch(
    () => props.rows,
    (newRows) => {
        if (previewActive.value) {
            return;
        }

        form.rows = newRows?.length
            ? newRows.map((row) => ({ ...row }))
            : [emptyRow()];
    },
    { deep: true },
);

const importFeedback = ref('');
const importFeedbackType = ref('');
const previewActive = ref(false);
const previewFilename = ref('');
const previewLoading = ref(false);
const savedRowSnapshot = ref(null);

const onImportFileChange = (event) => {
    importForm.file = event.target.files[0] ?? null;
    importFeedback.value = importForm.file ? `Selected: ${importForm.file.name}` : '';
    importFeedbackType.value = importForm.file ? 'info' : '';
};

const snapshotSavedRows = () => {
    savedRowSnapshot.value = props.rows.map((row) => ({ ...row }));
};

const applyPreviewRows = (rows, filename) => {
    if (!previewActive.value && savedRowSnapshot.value === null) {
        snapshotSavedRows();
    }

    form.rows = rows.map((row) => ({ ...row }));
    previewActive.value = true;
    previewFilename.value = filename;
    importFeedbackType.value = 'info';
    importFeedback.value = `Preview loaded: ${rows.length} row(s) from ${filename}. Review the table, then click Save syllabus to apply.`;
    nextTick(resizeAllFields);
};

const discardPreview = () => {
    if (savedRowSnapshot.value) {
        form.rows = savedRowSnapshot.value.map((row) => ({ ...row }));
    } else {
        form.rows = props.rows.length ? props.rows.map((row) => ({ ...row })) : [emptyRow()];
    }

    previewActive.value = false;
    previewFilename.value = '';
    savedRowSnapshot.value = null;
    importFeedback.value = 'Preview discarded. Showing the last saved syllabus again.';
    importFeedbackType.value = 'info';
};

const submitExcelPreview = async () => {
    importFeedback.value = '';
    importFeedbackType.value = '';
    importForm.clearErrors();

    if (!importForm.file) {
        importForm.setError('file', 'Choose an Excel file first.');
        importFeedback.value = 'Choose an Excel file first.';
        importFeedbackType.value = 'error';

        return;
    }

    previewLoading.value = true;
    importFeedback.value = 'Reading Excel file…';
    importFeedbackType.value = 'info';

    try {
        const formData = new FormData();
        formData.append('file', importForm.file);

        const { data } = await window.axios.post(
            route('admin.syllabus.import-preview', props.version.id),
            formData,
            { headers: { 'Content-Type': 'multipart/form-data' } },
        );

        applyPreviewRows(data.rows, data.filename || importForm.file.name);
        importForm.reset('file');
    } catch (error) {
        importFeedbackType.value = 'error';
        importFeedback.value =
            error.response?.data?.message
            || error.response?.data?.errors?.file?.[0]
            || 'Could not read the Excel file. Use a .xlsx file under 10 MB.';
    } finally {
        previewLoading.value = false;
    }
};

function emptyRow() {
    return {
        id: null,
        chapter_id: null,
        chapter_number: '',
        chapter_name: '',
        chapter_head_id: '',
        topic_name: '',
        learning_outcomes: '',
        difficulty: '',
        planned_periods: '',
        remarks: '',
    };
}

const addRow = () => {
    const last = form.rows[form.rows.length - 1];
    form.rows.push({
        ...emptyRow(),
        chapter_number: last?.chapter_number || '',
        chapter_name: last?.chapter_name || '',
        chapter_id: last?.chapter_id || null,
        chapter_head_id: last?.chapter_head_id || '',
    });
};

const chapterRowKey = (row) => {
    if (row.chapter_id) {
        return `id:${row.chapter_id}`;
    }

    return `new:${row.chapter_number}|${row.chapter_name}`;
};

const distinctChapters = computed(() => {
    const seen = new Map();

    for (const row of form.rows) {
        if (!String(row.chapter_name ?? '').trim() && !String(row.chapter_number ?? '').trim()) {
            continue;
        }

        const key = chapterRowKey(row);

        if (!seen.has(key)) {
            seen.set(key, {
                key,
                chapter_id: row.chapter_id,
                chapter_number: row.chapter_number,
                chapter_name: row.chapter_name,
                chapter_head_id: row.chapter_head_id,
                topicCount: 0,
            });
        }

        if (String(row.topic_name ?? '').trim()) {
            seen.get(key).topicCount += 1;
        }
    }

    return [...seen.values()].sort((a, b) =>
        String(a.chapter_number).localeCompare(String(b.chapter_number), undefined, { numeric: true }),
    );
});

const selectedQuickChapter = computed(() =>
    distinctChapters.value.find((chapter) => chapter.key === quickTopicForm.chapter_key) ?? null,
);

watch(
    () => distinctChapters.value,
    (chapters) => {
        if (chapters.length === 0) {
            quickTopicForm.mode = 'new';
            quickTopicForm.chapter_key = '';

            return;
        }

        if (quickTopicForm.mode === 'existing' && !chapters.some((chapter) => chapter.key === quickTopicForm.chapter_key)) {
            quickTopicForm.chapter_key = chapters[0].key;
        }
    },
    { immediate: true },
);

const addTopicForRow = (index) => {
    const source = form.rows[index];

    form.rows.splice(index + 1, 0, {
        ...emptyRow(),
        chapter_id: source.chapter_id,
        chapter_number: source.chapter_number,
        chapter_name: source.chapter_name,
        chapter_head_id: source.chapter_head_id,
    });

    nextTick(resizeAllFields);
};

const submitQuickTopic = () => {
    quickTopicForm.clearErrors();

    const payload = {
        topic_name: quickTopicForm.topic_name,
        learning_outcomes: quickTopicForm.learning_outcomes || null,
        difficulty: quickTopicForm.difficulty || null,
        planned_periods: quickTopicForm.planned_periods === '' ? null : quickTopicForm.planned_periods,
        remarks: null,
    };

    if (quickTopicForm.mode === 'existing') {
        const chapter = selectedQuickChapter.value;

        if (!chapter) {
            quickTopicForm.setError('chapter_key', 'Select a chapter first.');

            return;
        }

        payload.chapter_id = chapter.chapter_id || null;
        payload.chapter_number = chapter.chapter_number || null;
        payload.chapter_name = chapter.chapter_name || null;
        payload.chapter_head_id = chapter.chapter_head_id || null;
    } else {
        payload.chapter_id = null;
        payload.chapter_number = quickTopicForm.chapter_number;
        payload.chapter_name = quickTopicForm.chapter_name;
        payload.chapter_head_id = quickTopicForm.chapter_head_id || null;
    }

    quickTopicForm.transform(() => payload).post(route('admin.syllabus.topics.store', props.version.id), {
        preserveScroll: true,
        onSuccess: () => {
            quickTopicForm.topic_name = '';
            quickTopicForm.learning_outcomes = '';
            quickTopicForm.difficulty = '';
            quickTopicForm.planned_periods = '';

            if (quickTopicForm.mode === 'new') {
                quickTopicForm.chapter_number = '';
                quickTopicForm.chapter_name = '';
                quickTopicForm.chapter_head_id = '';
                quickTopicForm.mode = distinctChapters.value.length ? 'existing' : 'new';
            }
        },
    });
};

const removeRow = (index) => {
    form.rows.splice(index, 1);
};

const rowMatchesSearch = (row, query) => {
    const fields = [
        row.chapter_number,
        row.chapter_name,
        row.chapter_head_name,
        row.topic_name,
        row.learning_outcomes,
        row.difficulty,
        row.planned_periods,
        row.remarks,
    ];

    return fields.some((field) => String(field ?? '').toLowerCase().includes(query));
};

const filteredRows = computed(() => {
    const query = search.value.trim().toLowerCase();

    return form.rows
        .map((row, index) => ({ row, index }))
        .filter(({ row }) => query === '' || rowMatchesSearch(row, query));
});

const clearSearch = () => {
    search.value = '';
};

const autoResize = (event) => {
    const el = event?.target ?? event;
    if (!el) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
};

const resizeAllFields = () => {
    nextTick(() => {
        document.querySelectorAll('.syllabus-field').forEach((el) => autoResize(el));
    });
};

onMounted(resizeAllFields);

const saveRows = () => {
    form.put(route('admin.syllabus.rows.update', props.version.id), {
        preserveScroll: true,
        onSuccess: () => {
            previewActive.value = false;
            previewFilename.value = '';
            savedRowSnapshot.value = null;
        },
    });
};

const submitCarryForward = () => {
    carryForm.post(route('admin.syllabus.carry-forward', props.version.id));
};

const startAddHead = async (rowIndex = null) => {
    addingHead.value = true;
    newHeadName.value = '';
    addHeadError.value = '';
    targetRowIndex.value = rowIndex;
    await nextTick();
    newHeadInput.value?.focus();
};

const cancelAddHead = () => {
    addingHead.value = false;
    newHeadName.value = '';
    addHeadError.value = '';
    targetRowIndex.value = null;
};

const saveNewHead = async () => {
    const name = newHeadName.value.trim();
    if (!name) {
        addHeadError.value = 'Enter a name.';
        return;
    }

    addHeadSaving.value = true;
    addHeadError.value = '';

    try {
        const { data } = await window.axios.post(route('admin.chapter-heads.quick-store'), { name });
        const exists = chapterHeadsList.value.some((head) => head.id === data.chapterHead.id);
        if (!exists) {
            chapterHeadsList.value.push(data.chapterHead);
            chapterHeadsList.value.sort((a, b) => a.name.localeCompare(b.name));
        }

        if (targetRowIndex.value !== null && form.rows[targetRowIndex.value]) {
            form.rows[targetRowIndex.value].chapter_head_id = data.chapterHead.id;
        }

        cancelAddHead();
    } catch (error) {
        addHeadError.value = error.response?.data?.errors?.name?.[0]
            || error.response?.data?.message
            || 'Could not add chapter head.';
    } finally {
        addHeadSaving.value = false;
    }
};
</script>

<template>
    <Head :title="version.board.code + ' ' + version.grade_level.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">
                    {{ version.board.code }} {{ version.grade_level.name }} — {{ version.subject.name }}
                </h2>
                <Link
                    :href="isAdmin ? route('admin.syllabus.index') : route('admin.classes.show', version.grade_level.id)"
                    class="text-sm text-indigo-600"
                >
                    Back
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="$page.props.flash?.success"
                    class="rounded-md bg-green-50 p-4 text-sm text-green-800"
                >
                    {{ $page.props.flash.success }}
                </div>
                <div
                    v-if="$page.props.flash?.error"
                    class="rounded-md bg-red-50 p-4 text-sm text-red-800"
                >
                    {{ $page.props.flash.error }}
                </div>

                <BrowseModeNotice />

                <div v-if="isAdmin" class="rounded-lg border border-dashed border-indigo-200 bg-indigo-50/40 p-4 shadow-sm">
                    <h3 class="font-medium text-gray-900">Import from Excel</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Load a .xlsx file to <strong>preview</strong> in the table below. Nothing is saved until you click
                        <strong>Save syllabus</strong>.
                    </p>
                    <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="submitExcelPreview">
                        <div class="min-w-[240px] flex-1">
                            <InputLabel value="Excel file (.xlsx)" />
                            <input
                                type="file"
                                accept=".xlsx,.xls"
                                class="mt-1 block w-full text-sm"
                                @change="onImportFileChange"
                            />
                            <InputError class="mt-1" :message="importForm.errors.file" />
                        </div>
                        <PrimaryButton type="submit" :disabled="previewLoading">
                            {{ previewLoading ? 'Reading…' : 'Preview Excel' }}
                        </PrimaryButton>
                    </form>
                    <div
                        v-if="importFeedback"
                        class="mt-3 rounded-md border px-4 py-3 text-sm"
                        :class="{
                            'border-green-300 bg-green-50 text-green-900': importFeedbackType === 'success',
                            'border-red-300 bg-red-50 text-red-900': importFeedbackType === 'error',
                            'border-indigo-300 bg-indigo-50 text-indigo-900': importFeedbackType === 'info',
                        }"
                    >
                        {{ importFeedback }}
                    </div>
                </div>

                <div
                    v-if="previewActive"
                    class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p>
                            <strong>Preview mode</strong> — showing {{ form.rows.length }} row(s) from
                            <strong>{{ previewFilename }}</strong>. The saved syllabus is unchanged until you click
                            <strong>Save syllabus</strong>.
                        </p>
                        <SecondaryButton type="button" @click="discardPreview">
                            Discard preview
                        </SecondaryButton>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm">
                    <p class="text-sm text-gray-600">
                        Academic year: <strong>{{ version.academic_year.name }}</strong>
                        · Status: <strong class="capitalize">{{ version.status }}</strong>
                        · Rows: <strong>{{ isAdmin ? form.rows.length : rows.length }}</strong>
                    </p>
                    <p v-if="isAdmin" class="mt-2 text-xs text-gray-500">
                        Tag each chapter with a chapter head (e.g. Integers) to browse topics across all classes.
                        Use <strong>+ Add</strong> in the table to create a new head without leaving this page.
                    </p>
                </div>

                <div v-if="isAdmin" class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-3 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-medium text-gray-900">Add topic manually</h3>
                            <p class="text-xs text-gray-600">Select chapter, enter topic — saves immediately.</p>
                        </div>
                        <div class="flex gap-3 text-xs">
                            <label class="inline-flex items-center gap-1.5">
                                <input
                                    v-model="quickTopicForm.mode"
                                    type="radio"
                                    value="existing"
                                    class="text-emerald-600"
                                    :disabled="distinctChapters.length === 0"
                                />
                                Existing chapter
                            </label>
                            <label class="inline-flex items-center gap-1.5">
                                <input v-model="quickTopicForm.mode" type="radio" value="new" class="text-emerald-600" />
                                New chapter
                            </label>
                        </div>
                    </div>

                    <form class="mt-3 max-w-4xl space-y-2" @submit.prevent="submitQuickTopic">
                        <div v-if="quickTopicForm.mode === 'existing'" class="flex flex-wrap items-end gap-2">
                            <div class="min-w-[200px] flex-1">
                                <InputLabel value="Chapter" class="!text-xs" />
                                <select
                                    v-model="quickTopicForm.chapter_key"
                                    class="mt-0.5 block w-full rounded-md border-gray-300 py-1.5 text-sm"
                                    required
                                >
                                    <option value="" disabled>Select chapter</option>
                                    <option v-for="chapter in distinctChapters" :key="chapter.key" :value="chapter.key">
                                        Ch {{ chapter.chapter_number || '?' }} — {{ chapter.chapter_name }}
                                        ({{ chapter.topicCount }})
                                    </option>
                                </select>
                                <InputError class="mt-0.5" :message="quickTopicForm.errors.chapter_key" />
                            </div>
                            <div class="min-w-[180px] flex-[1.2]">
                                <InputLabel value="Topic name" class="!text-xs" />
                                <TextInput
                                    v-model="quickTopicForm.topic_name"
                                    class="mt-0.5 block w-full !py-1.5 text-sm"
                                    placeholder="Advanced Percent Problems"
                                    required
                                />
                                <InputError class="mt-0.5" :message="quickTopicForm.errors.topic_name" />
                            </div>
                        </div>

                        <div v-else class="flex flex-wrap items-end gap-2">
                            <div class="w-16">
                                <InputLabel value="Ch No." class="!text-xs" />
                                <TextInput
                                    v-model="quickTopicForm.chapter_number"
                                    class="mt-0.5 block w-full !py-1.5 text-sm"
                                    placeholder="7"
                                    required
                                />
                                <InputError class="mt-0.5" :message="quickTopicForm.errors.chapter_number" />
                            </div>
                            <div class="min-w-[140px] flex-1">
                                <InputLabel value="Chapter name" class="!text-xs" />
                                <TextInput
                                    v-model="quickTopicForm.chapter_name"
                                    class="mt-0.5 block w-full !py-1.5 text-sm"
                                    placeholder="Percentages"
                                    required
                                />
                                <InputError class="mt-0.5" :message="quickTopicForm.errors.chapter_name" />
                            </div>
                            <div class="min-w-[120px] flex-1">
                                <InputLabel value="Head" class="!text-xs" />
                                <select
                                    v-model="quickTopicForm.chapter_head_id"
                                    class="mt-0.5 block w-full rounded-md border-gray-300 py-1.5 text-sm"
                                >
                                    <option value="">—</option>
                                    <option v-for="head in chapterHeadsList" :key="head.id" :value="head.id">{{ head.name }}</option>
                                </select>
                            </div>
                            <div class="min-w-[180px] flex-[1.2]">
                                <InputLabel value="Topic name" class="!text-xs" />
                                <TextInput
                                    v-model="quickTopicForm.topic_name"
                                    class="mt-0.5 block w-full !py-1.5 text-sm"
                                    placeholder="Advanced Percent Problems"
                                    required
                                />
                                <InputError class="mt-0.5" :message="quickTopicForm.errors.topic_name" />
                            </div>
                        </div>

                        <div class="flex flex-wrap items-end gap-2">
                            <div class="min-w-[200px] flex-[1.5]">
                                <InputLabel value="Key concepts (optional)" class="!text-xs" />
                                <input
                                    v-model="quickTopicForm.learning_outcomes"
                                    type="text"
                                    class="mt-0.5 block w-full rounded-md border-gray-300 py-1.5 text-sm shadow-sm"
                                    placeholder="Complex percentage adjustments..."
                                />
                            </div>
                            <div class="w-24">
                                <InputLabel value="Difficulty" class="!text-xs" />
                                <select v-model="quickTopicForm.difficulty" class="mt-0.5 block w-full rounded-md border-gray-300 py-1.5 text-sm">
                                    <option value="">—</option>
                                    <option value="Easy">Easy</option>
                                    <option value="Medium">Medium</option>
                                    <option value="Hard">Hard</option>
                                </select>
                            </div>
                            <div class="w-16">
                                <InputLabel value="Periods" class="!text-xs" />
                                <TextInput
                                    v-model="quickTopicForm.planned_periods"
                                    type="number"
                                    min="0"
                                    class="mt-0.5 block w-full !py-1.5 text-sm"
                                    placeholder="3"
                                />
                            </div>
                            <PrimaryButton type="submit" class="!py-1.5 !text-xs" :disabled="quickTopicForm.processing">
                                {{ quickTopicForm.processing ? 'Adding…' : 'Add topic' }}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <div v-if="isAdmin" class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                        <h3 class="font-medium text-gray-900">Syllabus table</h3>
                        <div class="flex gap-2">
                            <SecondaryButton type="button" @click="addRow">Add row</SecondaryButton>
                            <PrimaryButton type="button" :disabled="form.processing" @click="saveRows">
                                {{ previewActive ? 'Save preview to syllabus' : 'Save syllabus' }}
                            </PrimaryButton>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-b bg-gray-50 px-4 py-3">
                        <div class="relative min-w-[220px] flex-1">
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Search chapter, topic, concepts, difficulty..."
                                class="w-full rounded-md border-gray-300 py-2 pl-3 pr-9 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <button
                                v-if="search"
                                type="button"
                                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                aria-label="Clear search"
                                @click="clearSearch"
                            >
                                ✕
                            </button>
                        </div>
                        <p class="text-sm text-gray-500">
                            <template v-if="search.trim()">
                                Showing <strong>{{ filteredRows.length }}</strong> of {{ form.rows.length }} rows
                            </template>
                            <template v-else>
                                {{ form.rows.length }} rows
                            </template>
                        </p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Ch No.</th>
                                    <th class="min-w-[140px] px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">
                                        <div class="flex items-center gap-2">
                                            <span>Chapter head</span>
                                            <button
                                                type="button"
                                                class="normal-case text-indigo-600 hover:underline"
                                                @click="startAddHead()"
                                            >
                                                + Add
                                            </button>
                                        </div>
                                    </th>
                                    <th class="min-w-[160px] px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Chapter name</th>
                                    <th class="min-w-[180px] px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Topic</th>
                                    <th class="min-w-[260px] px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Key Concepts</th>
                                    <th class="px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Difficulty</th>
                                    <th class="px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Periods</th>
                                    <th class="min-w-[140px] px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Remarks</th>
                                    <th class="px-2 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-if="addingHead" class="bg-indigo-50">
                                    <td colspan="9" class="px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm font-medium text-indigo-900">New chapter head:</span>
                                            <input
                                                ref="newHeadInput"
                                                v-model="newHeadName"
                                                type="text"
                                                class="min-w-[180px] rounded-md border-gray-300 text-sm"
                                                placeholder="e.g. Integers"
                                                @keyup.enter="saveNewHead"
                                                @keyup.esc="cancelAddHead"
                                            />
                                            <PrimaryButton type="button" class="!py-1.5 !text-xs" :disabled="addHeadSaving" @click="saveNewHead">
                                                {{ addHeadSaving ? 'Adding…' : 'Add' }}
                                            </PrimaryButton>
                                            <button type="button" class="text-sm text-gray-600 hover:text-gray-900" @click="cancelAddHead">
                                                Cancel
                                            </button>
                                            <span v-if="addHeadError" class="text-sm text-red-600">{{ addHeadError }}</span>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-for="{ row, index } in filteredRows" :key="index">
                                    <td class="align-top px-2 py-2">
                                        <input
                                            v-model="row.chapter_number"
                                            type="text"
                                            class="w-16 rounded-md border-gray-300 text-sm"
                                            placeholder="Ch 1"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <select v-model="row.chapter_head_id" class="w-full min-w-[130px] rounded-md border-gray-300 text-sm">
                                            <option value="">—</option>
                                            <option v-for="head in chapterHeadsList" :key="head.id" :value="head.id">{{ head.name }}</option>
                                        </select>
                                        <button
                                            type="button"
                                            class="mt-1 text-xs text-indigo-600 hover:underline"
                                            @click="startAddHead(index)"
                                        >
                                            + Add
                                        </button>
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <textarea
                                            v-model="row.chapter_name"
                                            rows="2"
                                            class="syllabus-field w-full min-w-[150px] rounded-md border-gray-300 text-sm"
                                            placeholder="Chapter title in book"
                                            @input="autoResize"
                                        />
                                        <button
                                            type="button"
                                            class="mt-1 text-xs text-emerald-700 hover:underline"
                                            @click="addTopicForRow(index)"
                                        >
                                            + Topic
                                        </button>
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <textarea
                                            v-model="row.topic_name"
                                            rows="2"
                                            class="syllabus-field w-full min-w-[170px] rounded-md border-gray-300 text-sm"
                                            placeholder="Sub-topic"
                                            @input="autoResize"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <textarea
                                            v-model="row.learning_outcomes"
                                            rows="3"
                                            class="syllabus-field w-full min-w-[250px] rounded-md border-gray-300 text-sm"
                                            @input="autoResize"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <select v-model="row.difficulty" class="rounded-md border-gray-300 text-sm">
                                            <option value="">—</option>
                                            <option value="Easy">Easy</option>
                                            <option value="Medium">Medium</option>
                                            <option value="Hard">Hard</option>
                                        </select>
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <input
                                            v-model="row.planned_periods"
                                            type="number"
                                            min="0"
                                            class="w-16 rounded-md border-gray-300 text-sm"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <textarea
                                            v-model="row.remarks"
                                            rows="2"
                                            class="syllabus-field w-full min-w-[130px] rounded-md border-gray-300 text-sm"
                                            @input="autoResize"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <DangerButton type="button" class="!px-2 !py-1 text-xs" @click="removeRow(index)">
                                            Remove
                                        </DangerButton>
                                    </td>
                                </tr>
                                <tr v-if="form.rows.length === 0">
                                    <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                        No rows yet. Click "Add row" to enter chapters and topics.
                                    </td>
                                </tr>
                                <tr v-else-if="filteredRows.length === 0">
                                    <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                        No rows match "{{ search }}". <button type="button" class="text-indigo-600 hover:underline" @click="clearSearch">Clear search</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                        <h3 class="font-medium text-gray-900">Syllabus (read only)</h3>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 border-b bg-gray-50 px-4 py-3">
                        <div class="relative min-w-[220px] flex-1">
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Search chapter, topic, concepts..."
                                class="w-full rounded-md border-gray-300 py-2 pl-3 pr-9 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-medium uppercase text-gray-500">Ch</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium uppercase text-gray-500">Head</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium uppercase text-gray-500">Chapter</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium uppercase text-gray-500">Topic</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium uppercase text-gray-500">Key concepts</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium uppercase text-gray-500">Difficulty</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-for="(row, index) in readOnlyFilteredRows" :key="index">
                                    <td class="px-3 py-2">{{ row.chapter_number || '—' }}</td>
                                    <td class="px-3 py-2">{{ row.chapter_head_name || '—' }}</td>
                                    <td class="px-3 py-2">{{ row.chapter_name || '—' }}</td>
                                    <td class="px-3 py-2 font-medium text-gray-900">{{ row.topic_name || '—' }}</td>
                                    <td class="px-3 py-2 whitespace-pre-wrap text-gray-600">{{ row.learning_outcomes || '—' }}</td>
                                    <td class="px-3 py-2">{{ row.difficulty || '—' }}</td>
                                </tr>
                                <tr v-if="readOnlyFilteredRows.length === 0">
                                    <td colspan="6" class="px-4 py-8 text-center text-gray-500">No syllabus rows to show.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="isAdmin" class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="font-medium">Carry forward to next year</h3>
                    <p class="mt-1 text-sm text-gray-600">Clone this syllabus as a draft for a new academic year, then edit changes.</p>
                    <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="submitCarryForward">
                        <div>
                            <InputLabel value="Target academic year" />
                            <select v-model="carryForm.academic_year_id" class="mt-1 rounded-md border-gray-300" required>
                                <option value="" disabled>Select year</option>
                                <option
                                    v-for="year in academicYears"
                                    :key="year.id"
                                    :value="year.id"
                                    :disabled="year.id === version.academic_year_id"
                                >
                                    {{ year.name }}
                                </option>
                            </select>
                        </div>
                        <PrimaryButton :disabled="carryForm.processing">Carry forward</PrimaryButton>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.syllabus-field {
    resize: vertical;
    min-height: 3.5rem;
    line-height: 1.4;
    overflow: hidden;
}
</style>
