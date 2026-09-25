<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    uploaderMode: { type: Boolean, default: false },
    gradeLevel: { type: Object, default: null },
    chapters: { type: Array, default: () => [] },
    books: { type: Array, default: () => [] },
    contentUploaders: { type: Array, default: () => [] },
    defaultConceptAmountInr: { type: Number, default: 50 },
    storeUrl: { type: String, default: '' },
    createUrl: { type: String, default: '' },
});

const page = usePage();
const uploadForId = ref(null);
const assignForms = reactive({});
const assignOpenId = ref(null);
const selectedIds = ref([]);
const batchAssigning = ref(false);

/** Concept paths belong on school textbooks — hide RD Sharma by default. */
const hideRdSharma = ref(true);
const bookFilterId = ref('');

const batchForm = reactive({
    assigned_to_user_id: props.contentUploaders[0]?.id ?? '',
    offered_amount_inr: props.defaultConceptAmountInr,
});

const ensureAssignForm = (uploadId) => {
    if (!assignForms[uploadId]) {
        assignForms[uploadId] = {
            assigned_to_user_id: props.contentUploaders[0]?.id ?? '',
            offered_amount_inr: props.defaultConceptAmountInr,
        };
    }
    return assignForms[uploadId];
};

const isRdSharmaBook = (bookOrUpload) => Boolean(bookOrUpload?.is_rd_sharma);

const visibleBooks = computed(() => {
    return (props.books || []).filter((book) => {
        if (hideRdSharma.value && isRdSharmaBook(book)) {
            return false;
        }
        if (bookFilterId.value && String(book.id) !== String(bookFilterId.value)) {
            return false;
        }
        return true;
    });
});

const bookFilterOptions = computed(() => {
    return (props.books || []).filter((book) => !(hideRdSharma.value && isRdSharmaBook(book)));
});

const filteredUploads = (uploads) => {
    return (uploads || []).filter((upload) => {
        if (hideRdSharma.value && isRdSharmaBook(upload)) {
            return false;
        }
        if (bookFilterId.value && String(upload.textbook_id) !== String(bookFilterId.value)) {
            return false;
        }
        return true;
    });
};

const hiddenRdSharmaCount = computed(() => {
    if (!hideRdSharma.value) {
        return 0;
    }
    let n = 0;
    for (const row of props.chapters || []) {
        for (const upload of row.uploads || []) {
            if (isRdSharmaBook(upload)) {
                n += 1;
            }
        }
    }
    return n;
});

const isBuiltUpload = (upload) => Boolean(upload?.is_approved);

/** Flat table rows: one per book upload (or empty chapter needing a link). */
const tableRows = computed(() => {
    const rows = [];

    for (const chapter of props.chapters || []) {
        const uploads = filteredUploads(chapter.uploads);
        if (uploads.length) {
            for (const upload of uploads) {
                rows.push({
                    key: `u-${upload.id}`,
                    chapter,
                    upload,
                    built: isBuiltUpload(upload),
                });
            }
        } else if (!bookFilterId.value) {
            // Only show empty chapters when not filtering to a specific book.
            const onlyRdHidden = hideRdSharma.value && (chapter.uploads || []).length > 0;
            rows.push({
                key: `c-${chapter.syllabus_chapter_id}`,
                chapter,
                upload: null,
                built: false,
                onlyRdHidden,
            });
        }
    }

    return rows;
});

const pendingRows = computed(() => tableRows.value.filter((r) => !r.built));
const builtRows = computed(() => tableRows.value.filter((r) => r.built));

const assignablePendingRows = computed(() =>
    pendingRows.value.filter((r) => r.upload?.can_assign_concept),
);

const selectedCount = computed(() => selectedIds.value.length);

const allAssignableSelected = computed(() => {
    const ids = assignablePendingRows.value.map((r) => r.upload.id);
    return ids.length > 0 && ids.every((id) => selectedIds.value.includes(id));
});

const toggleSelect = (uploadId) => {
    const id = Number(uploadId);
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((x) => x !== id);
    } else {
        selectedIds.value = [...selectedIds.value, id];
    }
};

const toggleSelectAllAssignable = () => {
    if (allAssignableSelected.value) {
        selectedIds.value = [];
        return;
    }
    selectedIds.value = assignablePendingRows.value.map((r) => r.upload.id);
};

const clearSelection = () => {
    selectedIds.value = [];
};

const assignConcept = (upload) => {
    const form = ensureAssignForm(upload.id);
    if (!form.assigned_to_user_id) {
        return;
    }

    router.post(route('admin.content-tasks.assign-concept-path'), {
        textbook_chapter_id: upload.id,
        assigned_to_user_id: form.assigned_to_user_id,
        offered_amount_inr: form.offered_amount_inr || props.defaultConceptAmountInr,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            assignOpenId.value = null;
            selectedIds.value = selectedIds.value.filter((id) => id !== upload.id);
        },
    });
};

const assignSelected = () => {
    if (!selectedIds.value.length || !batchForm.assigned_to_user_id) {
        return;
    }

    batchAssigning.value = true;
    router.post(route('admin.content-tasks.assign-concept-path'), {
        textbook_chapter_ids: selectedIds.value,
        assigned_to_user_id: batchForm.assigned_to_user_id,
        offered_amount_inr: batchForm.offered_amount_inr || props.defaultConceptAmountInr,
    }, {
        preserveScroll: true,
        onFinish: () => {
            batchAssigning.value = false;
        },
        onSuccess: () => {
            selectedIds.value = [];
            assignOpenId.value = null;
        },
    });
};

const form = useForm({
    syllabus_chapter_id: '',
    textbook_id: '',
    book_name: '',
    book_code: '',
    pdf: null,
});

const uploadUi = reactive({
    mode: 'existing',
    error: '',
});

const booksForRow = (row) => {
    const boardId = row.board_id ? Number(row.board_id) : null;
    const linked = new Set((row.linked_textbook_ids || []).map(Number));

    return visibleBooks.value.filter((book) => {
        if (linked.has(Number(book.id))) {
            return false;
        }
        if (! boardId || book.board_id == null) {
            return true;
        }

        return Number(book.board_id) === boardId;
    });
};

const openUpload = (row) => {
    uploadForId.value = row.syllabus_chapter_id;
    form.clearErrors();
    form.reset();
    form.syllabus_chapter_id = row.syllabus_chapter_id;
    form.pdf = null;
    uploadUi.error = '';

    const available = booksForRow(row);
    if (available.length) {
        uploadUi.mode = 'existing';
        form.textbook_id = String(available[0].id);
        form.book_name = '';
        form.book_code = '';
    } else {
        uploadUi.mode = 'new';
        form.textbook_id = '';
        form.book_name = '';
        form.book_code = '';
    }
};

const closeUpload = () => {
    uploadForId.value = null;
    form.reset();
    uploadUi.error = '';
};

const onModeChange = (row) => {
    form.clearErrors();
    uploadUi.error = '';
    if (uploadUi.mode === 'existing') {
        const available = booksForRow(row);
        form.textbook_id = available[0] ? String(available[0].id) : '';
        form.book_name = '';
        form.book_code = '';
    } else {
        form.textbook_id = '';
    }
};

const onPdfChange = (event) => {
    uploadUi.error = '';
    const file = event.target.files?.[0] || null;
    form.pdf = file;
    if (file && file.size > 50 * 1024 * 1024) {
        uploadUi.error = 'PDF must be under 50 MB.';
    }
};

const submitUpload = () => {
    if (uploadUi.error) {
        return;
    }

    const payload = {
        syllabus_chapter_id: form.syllabus_chapter_id,
        pdf: form.pdf || null,
    };

    if (uploadUi.mode === 'existing') {
        payload.textbook_id = form.textbook_id;
        payload.book_name = null;
        payload.book_code = null;
    } else {
        payload.textbook_id = null;
        payload.book_name = form.book_name;
        payload.book_code = form.book_code;
    }

    form.transform(() => payload).post(props.storeUrl, {
        forceFormData: true,
        onFinish: () => form.transform((data) => data),
        onSuccess: () => closeUpload(),
    });
};

const statusTone = (upload) => {
    if (!upload) {
        return 'bg-slate-100 text-slate-700';
    }
    if (upload.is_approved) {
        return 'bg-emerald-100 text-emerald-900';
    }
    if (upload.concept_path_status === 'draft') {
        return 'bg-amber-100 text-amber-900';
    }
    if (upload.concept_path_status) {
        return 'bg-violet-100 text-violet-900';
    }
    return upload.has_pdf ? 'bg-sky-100 text-sky-900' : 'bg-rose-100 text-rose-900';
};

const statusLabel = (upload) => {
    if (!upload) {
        return 'No book';
    }
    if (upload.is_approved) {
        return upload.concept_path_card_count
            ? `Built · ${upload.concept_path_card_count} cards`
            : 'Built';
    }
    if (upload.concept_path_status_label) {
        return upload.concept_path_status_label;
    }
    return upload.has_pdf ? 'PDF ready' : 'No PDF';
};

const isFirstPendingForChapter = (row) => {
    const first = pendingRows.value.find((r) => r.chapter.syllabus_chapter_id === row.chapter.syllabus_chapter_id);
    return first?.key === row.key;
};
</script>

<template>
    <Head title="Concept builder" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Concept builder</h2>
                    <p class="text-sm text-gray-500">
                        Link school textbook → assign / build teach-check cards.
                        {{ gradeLevel ? `Showing ${gradeLevel.name}.` : 'Select a class from the top bar.' }}
                    </p>
                </div>
                <Link v-if="createUrl" :href="createUrl">
                    <PrimaryButton type="button">Upload chapter PDF</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-6xl space-y-3 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-900">
                    {{ page.props.flash.error }}
                </div>

                <div
                    v-if="gradeLevel && chapters.length"
                    class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"
                >
                    <label class="inline-flex items-center gap-2 font-medium">
                        <input v-model="hideRdSharma" type="checkbox" class="rounded border-gray-300 text-violet-700 focus:ring-violet-500">
                        Hide RD Sharma
                    </label>
                    <span v-if="hideRdSharma && hiddenRdSharmaCount" class="text-xs text-slate-500">
                        {{ hiddenRdSharmaCount }} hidden
                    </span>
                    <span class="text-xs text-slate-500">
                        Needs work {{ pendingRows.length }} · Built {{ builtRows.length }}
                    </span>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="concept-book-filter">Book</label>
                        <select
                            id="concept-book-filter"
                            v-model="bookFilterId"
                            class="rounded-md border-gray-300 py-1 text-sm"
                        >
                            <option value="">All books</option>
                            <option
                                v-for="book in bookFilterOptions"
                                :key="book.id"
                                :value="String(book.id)"
                            >
                                {{ book.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <div v-if="!gradeLevel" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">
                    Choose a class in the top bar to list syllabus chapters.
                </div>

                <div v-else-if="!chapters.length" class="rounded-lg border border-slate-200 bg-white px-4 py-6 text-sm text-slate-600">
                    No syllabus chapters found for this class / year. Set up the syllabus first under Setup.
                </div>

                <template v-else>
                    <!-- Needs work -->
                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-200 bg-amber-50 px-3 py-1.5">
                            <h3 class="text-xs font-bold uppercase tracking-wide text-amber-950">
                                Needs concept builder
                            </h3>
                            <span class="text-[11px] font-semibold text-amber-800">{{ pendingRows.length }}</span>
                        </div>

                        <div
                            v-if="!uploaderMode && assignablePendingRows.length"
                            class="sticky top-0 z-10 flex flex-wrap items-end gap-2 border-b border-fuchsia-200 bg-fuchsia-50 px-3 py-2"
                        >
                            <p class="mr-auto text-xs text-fuchsia-950">
                                <strong>{{ selectedCount }}</strong> selected
                                <button
                                    v-if="selectedCount"
                                    type="button"
                                    class="ml-2 text-[10px] font-semibold uppercase underline"
                                    @click="clearSelection"
                                >
                                    Clear
                                </button>
                            </p>
                            <div>
                                <label class="text-[10px] font-semibold uppercase text-fuchsia-800">Uploader</label>
                                <select
                                    v-model="batchForm.assigned_to_user_id"
                                    class="mt-0.5 block rounded-md border-gray-300 py-1 text-xs"
                                >
                                    <option value="" disabled>Select</option>
                                    <option v-for="person in contentUploaders" :key="person.id" :value="person.id">{{ person.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] font-semibold uppercase text-fuchsia-800">₹ each</label>
                                <input
                                    v-model="batchForm.offered_amount_inr"
                                    type="number"
                                    min="1"
                                    class="mt-0.5 w-16 rounded-md border-gray-300 py-1 text-xs"
                                >
                            </div>
                            <button
                                type="button"
                                class="rounded-md bg-fuchsia-700 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white hover:bg-fuchsia-800 disabled:opacity-50"
                                :disabled="!selectedCount || !batchForm.assigned_to_user_id || batchAssigning"
                                @click="assignSelected"
                            >
                                {{ batchAssigning ? 'Assigning…' : `Assign ${selectedCount || ''} selected` }}
                            </button>
                        </div>

                        <div v-if="!pendingRows.length" class="px-3 py-4 text-sm text-slate-500">
                            All visible chapters have approved concept paths.
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th v-if="!uploaderMode" class="w-8 px-2 py-1.5">
                                            <input
                                                type="checkbox"
                                                class="rounded border-gray-300 text-fuchsia-700 focus:ring-fuchsia-500"
                                                :checked="allAssignableSelected"
                                                :disabled="!assignablePendingRows.length"
                                                title="Select all assignable"
                                                @change="toggleSelectAllAssignable"
                                            >
                                        </th>
                                        <th class="px-3 py-1.5">Chapter</th>
                                        <th class="px-3 py-1.5">Book</th>
                                        <th class="px-3 py-1.5">Status</th>
                                        <th class="px-3 py-1.5">Assign</th>
                                        <th class="px-3 py-1.5 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <template v-for="row in pendingRows" :key="row.key">
                                        <tr
                                            class="align-middle hover:bg-slate-50/80"
                                            :class="row.upload && selectedIds.includes(row.upload.id) ? 'bg-fuchsia-50/50' : ''"
                                        >
                                            <td v-if="!uploaderMode" class="px-2 py-1.5">
                                                <input
                                                    v-if="row.upload?.can_assign_concept"
                                                    type="checkbox"
                                                    class="rounded border-gray-300 text-fuchsia-700 focus:ring-fuchsia-500"
                                                    :checked="selectedIds.includes(row.upload.id)"
                                                    @change="toggleSelect(row.upload.id)"
                                                >
                                            </td>
                                            <td class="max-w-[14rem] px-3 py-1.5">
                                                <p class="truncate font-medium text-slate-900" :title="row.chapter.label">
                                                    {{ row.chapter.label }}
                                                </p>
                                                <p v-if="row.chapter.board_code" class="text-[10px] text-slate-500">
                                                    {{ row.chapter.board_code }}
                                                </p>
                                            </td>
                                            <td class="px-3 py-1.5">
                                                <template v-if="row.upload">
                                                    <p class="truncate font-medium text-slate-800" :title="row.upload.book_name">
                                                        {{ row.upload.book_name }}
                                                    </p>
                                                    <p class="text-[10px] text-slate-500">{{ row.upload.book_code }}</p>
                                                </template>
                                                <span v-else-if="row.onlyRdHidden" class="text-xs text-slate-500">RD Sharma only (hidden)</span>
                                                <span v-else class="text-xs text-slate-500">Not linked</span>
                                            </td>
                                            <td class="px-3 py-1.5">
                                                <span
                                                    class="inline-block rounded px-1.5 py-px text-[10px] font-semibold"
                                                    :class="statusTone(row.upload)"
                                                >
                                                    {{ statusLabel(row.upload) }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-1.5">
                                                <template v-if="row.upload?.concept_job">
                                                    <p class="text-xs text-fuchsia-900">
                                                        {{ row.upload.concept_job.assignee_name }} · ₹{{ row.upload.concept_job.amount_inr }}
                                                    </p>
                                                    <Link :href="row.upload.concept_job.task_url" class="text-[10px] font-semibold text-fuchsia-800 underline">
                                                        {{ row.upload.concept_job.status_label }}
                                                    </Link>
                                                </template>
                                                <template v-else-if="row.upload?.can_assign_concept">
                                                    <button
                                                        type="button"
                                                        class="rounded border border-fuchsia-300 bg-fuchsia-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-fuchsia-900 hover:bg-fuchsia-100"
                                                        @click="assignOpenId = assignOpenId === row.upload.id ? null : row.upload.id"
                                                    >
                                                        {{ assignOpenId === row.upload.id ? 'Cancel' : 'Assign 1' }}
                                                    </button>
                                                </template>
                                                <span v-else class="text-xs text-slate-400">—</span>
                                            </td>
                                            <td class="px-3 py-1.5">
                                                <div class="flex flex-wrap items-center justify-end gap-1">
                                                    <template v-if="row.upload">
                                                        <Link
                                                            v-if="row.upload.run_url"
                                                            :href="row.upload.run_url"
                                                            class="rounded bg-emerald-700 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white hover:bg-emerald-800"
                                                        >
                                                            Run
                                                        </Link>
                                                        <Link
                                                            v-if="row.upload.concept_path_url"
                                                            :href="row.upload.concept_path_url"
                                                            class="rounded bg-violet-700 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white hover:bg-violet-800"
                                                        >
                                                            {{ row.upload.concept_path_status === 'draft' ? 'Edit' : 'Build' }}
                                                        </Link>
                                                        <Link
                                                            v-else
                                                            :href="row.upload.upload_url"
                                                            class="rounded border border-slate-300 bg-white px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-800 hover:bg-slate-50"
                                                        >
                                                            {{ row.upload.has_pdf ? 'Open' : 'Upload PDF' }}
                                                        </Link>
                                                    </template>
                                                    <button
                                                        type="button"
                                                        class="rounded border border-indigo-300 bg-white px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-indigo-800 hover:bg-indigo-50"
                                                        @click="uploadForId === row.chapter.syllabus_chapter_id ? closeUpload() : openUpload(row.chapter)"
                                                    >
                                                        {{ uploadForId === row.chapter.syllabus_chapter_id ? 'Cancel' : 'Link' }}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr v-if="row.upload && assignOpenId === row.upload.id && row.upload.can_assign_concept">
                                            <td :colspan="uploaderMode ? 5 : 6" class="bg-fuchsia-50/70 px-3 py-2">
                                                <form class="flex flex-wrap items-end gap-2" @submit.prevent="assignConcept(row.upload)">
                                                    <div>
                                                        <label class="text-[10px] font-semibold uppercase text-fuchsia-800">Uploader</label>
                                                        <select
                                                            v-model="ensureAssignForm(row.upload.id).assigned_to_user_id"
                                                            required
                                                            class="mt-0.5 block rounded-md border-gray-300 py-1 text-xs"
                                                        >
                                                            <option value="" disabled>Select</option>
                                                            <option v-for="person in contentUploaders" :key="person.id" :value="person.id">{{ person.name }}</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="text-[10px] font-semibold uppercase text-fuchsia-800">₹</label>
                                                        <input
                                                            v-model="ensureAssignForm(row.upload.id).offered_amount_inr"
                                                            type="number"
                                                            min="1"
                                                            class="mt-0.5 w-16 rounded-md border-gray-300 py-1 text-xs"
                                                        >
                                                    </div>
                                                    <button type="submit" class="rounded-md bg-fuchsia-700 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white hover:bg-fuchsia-800">
                                                        {{ row.upload.has_pdf ? `Assign @ ₹${defaultConceptAmountInr}` : 'Assign · uploader uploads PDF' }}
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <tr v-if="uploadForId === row.chapter.syllabus_chapter_id && isFirstPendingForChapter(row)">
                                            <td :colspan="uploaderMode ? 5 : 6" class="bg-indigo-50/60 px-3 py-2">
                                                <form class="space-y-2" @submit.prevent="submitUpload">
                                                    <p class="text-xs font-semibold text-indigo-950">
                                                        Link {{ row.chapter.label }}
                                                        <span v-if="!uploaderMode" class="font-normal">— PDF optional</span>
                                                    </p>
                                                    <div class="flex flex-wrap gap-3 text-xs">
                                                        <label class="inline-flex items-center gap-1">
                                                            <input v-model="uploadUi.mode" type="radio" value="existing" @change="onModeChange(row.chapter)">
                                                            Existing book
                                                        </label>
                                                        <label class="inline-flex items-center gap-1">
                                                            <input v-model="uploadUi.mode" type="radio" value="new" @change="onModeChange(row.chapter)">
                                                            New book
                                                        </label>
                                                    </div>
                                                    <div class="flex flex-wrap items-end gap-2">
                                                        <div v-if="uploadUi.mode === 'existing'" class="min-w-[12rem] flex-1">
                                                            <select v-model="form.textbook_id" class="w-full rounded-md border-gray-300 py-1 text-xs" required>
                                                                <option value="" disabled>Choose book…</option>
                                                                <option v-for="book in booksForRow(row.chapter)" :key="book.id" :value="String(book.id)">{{ book.label }}</option>
                                                            </select>
                                                            <InputError :message="form.errors.textbook_id" class="mt-0.5" />
                                                        </div>
                                                        <template v-else>
                                                            <input v-model="form.book_name" type="text" class="rounded-md border-gray-300 py-1 text-xs" placeholder="Book name" required>
                                                            <input v-model="form.book_code" type="text" class="w-24 rounded-md border-gray-300 py-1 text-xs" placeholder="Code" required>
                                                        </template>
                                                        <input
                                                            type="file"
                                                            accept="application/pdf"
                                                            class="text-xs"
                                                            :required="uploaderMode"
                                                            @change="onPdfChange"
                                                        >
                                                        <PrimaryButton type="submit" class="!px-2 !py-1 !text-[10px]" :disabled="form.processing || !!uploadUi.error">
                                                            <template v-if="form.processing">Saving…</template>
                                                            <template v-else-if="uploaderMode || form.pdf">Upload &amp; open</template>
                                                            <template v-else>Link book</template>
                                                        </PrimaryButton>
                                                    </div>
                                                    <InputError :message="uploadUi.error || form.errors.pdf" />
                                                </form>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Already built -->
                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between border-b border-emerald-200 bg-emerald-50 px-3 py-1.5">
                            <h3 class="text-xs font-bold uppercase tracking-wide text-emerald-950">
                                Concepts already built
                            </h3>
                            <span class="text-[11px] font-semibold text-emerald-800">{{ builtRows.length }}</span>
                        </div>

                        <div v-if="!builtRows.length" class="px-3 py-4 text-sm text-slate-500">
                            No approved concept paths yet for this view.
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-3 py-1.5">Chapter</th>
                                        <th class="px-3 py-1.5">Book</th>
                                        <th class="px-3 py-1.5">Status</th>
                                        <th class="px-3 py-1.5">Job</th>
                                        <th class="px-3 py-1.5 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr
                                        v-for="row in builtRows"
                                        :key="row.key"
                                        class="align-middle hover:bg-emerald-50/40"
                                    >
                                        <td class="max-w-[14rem] px-3 py-1.5">
                                            <p class="truncate font-medium text-slate-900" :title="row.chapter.label">
                                                {{ row.chapter.label }}
                                            </p>
                                            <p v-if="row.chapter.board_code" class="text-[10px] text-slate-500">
                                                {{ row.chapter.board_code }}
                                            </p>
                                        </td>
                                        <td class="px-3 py-1.5">
                                            <p class="truncate font-medium text-slate-800" :title="row.upload.book_name">
                                                {{ row.upload.book_name }}
                                            </p>
                                            <p class="text-[10px] text-slate-500">{{ row.upload.book_code }}</p>
                                        </td>
                                        <td class="px-3 py-1.5">
                                            <span
                                                class="inline-block rounded px-1.5 py-px text-[10px] font-semibold"
                                                :class="statusTone(row.upload)"
                                            >
                                                {{ statusLabel(row.upload) }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-1.5">
                                            <template v-if="row.upload.concept_job">
                                                <p class="text-xs text-fuchsia-900">
                                                    {{ row.upload.concept_job.assignee_name }} · ₹{{ row.upload.concept_job.amount_inr }}
                                                </p>
                                                <Link :href="row.upload.concept_job.task_url" class="text-[10px] font-semibold text-fuchsia-800 underline">
                                                    {{ row.upload.concept_job.status_label }}
                                                </Link>
                                            </template>
                                            <span v-else class="text-xs text-slate-400">—</span>
                                        </td>
                                        <td class="px-3 py-1.5">
                                            <div class="flex flex-wrap items-center justify-end gap-1">
                                                <Link
                                                    v-if="row.upload.run_url"
                                                    :href="row.upload.run_url"
                                                    class="rounded bg-emerald-700 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white hover:bg-emerald-800"
                                                >
                                                    Run
                                                </Link>
                                                <Link
                                                    v-if="row.upload.concept_path_url"
                                                    :href="row.upload.concept_path_url"
                                                    class="rounded border border-violet-300 bg-white px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-violet-800 hover:bg-violet-50"
                                                >
                                                    Edit
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
