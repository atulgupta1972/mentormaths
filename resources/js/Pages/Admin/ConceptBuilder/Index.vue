<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    uploaderMode: { type: Boolean, default: false },
    gradeLevel: { type: Object, default: null },
    chapters: { type: Array, default: () => [] },
    books: { type: Array, default: () => [] },
    storeUrl: { type: String, default: '' },
    createUrl: { type: String, default: '' },
});

const page = usePage();
const uploadForId = ref(null);

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

const groupedByBoard = computed(() => {
    const groups = [];
    let current = null;

    for (const row of props.chapters) {
        const key = row.board_code || row.board_name || 'Syllabus';
        if (! current || current.key !== key) {
            current = {
                key,
                label: row.board_code ? `${row.board_code} syllabus` : (row.board_name || 'Syllabus'),
                rows: [],
            };
            groups.push(current);
        }
        current.rows.push(row);
    }

    return groups;
});

const booksForRow = (row) => {
    const boardId = row.board_id ? Number(row.board_id) : null;
    const linked = new Set((row.linked_textbook_ids || []).map(Number));

    return (props.books || []).filter((book) => {
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
        pdf: form.pdf,
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
    });
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
                        Class-wise syllabus chapters → pick the textbook → upload PDF if needed → build teach/check cards.
                        {{ gradeLevel ? `Showing ${gradeLevel.name}.` : 'Select a class from the top bar.' }}
                    </p>
                </div>
                <Link v-if="createUrl" :href="createUrl">
                    <PrimaryButton type="button">Upload chapter PDF</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg border border-violet-200 bg-violet-50/70 px-4 py-3 text-sm text-violet-950">
                    Each syllabus chapter can have PDFs from different books (e.g. Ganita Prakash and RD Sharma).
                    Choose the book, upload its chapter PDF if missing, then build concepts for that book.
                </div>

                <div v-if="!gradeLevel" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    Choose a class in the top bar to list syllabus chapters.
                </div>

                <div v-else-if="!chapters.length" class="rounded-lg border border-slate-200 bg-white px-4 py-6 text-sm text-slate-600">
                    No syllabus chapters found for this class / year. Set up the syllabus first under Setup.
                </div>

                <div
                    v-for="group in groupedByBoard"
                    :key="group.key"
                    class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                >
                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-bold uppercase tracking-wide text-slate-700">
                        {{ group.label }}
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <li
                            v-for="row in group.rows"
                            :key="row.syllabus_chapter_id"
                            class="px-4 py-3"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold text-slate-900">{{ row.label }}</p>
                                    <div v-if="row.uploads?.length" class="mt-2 space-y-2">
                                        <div
                                            v-for="upload in row.uploads"
                                            :key="upload.id"
                                            class="flex flex-wrap items-center gap-2 rounded-md border border-slate-100 bg-slate-50/80 px-2.5 py-2"
                                        >
                                            <div class="min-w-0 flex-1 text-xs text-slate-700">
                                                <span class="font-semibold text-slate-900">
                                                    {{ upload.book_name }}
                                                    <span class="font-normal text-slate-500">({{ upload.book_code }})</span>
                                                </span>
                                                <span
                                                    class="ml-2 rounded-full px-1.5 py-px text-[10px] font-semibold"
                                                    :class="upload.has_pdf ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'"
                                                >
                                                    {{ upload.has_pdf ? 'PDF ready' : 'No PDF' }}
                                                </span>
                                                <span
                                                    v-if="upload.concept_path_status"
                                                    class="ml-1 rounded-full bg-violet-100 px-1.5 py-px text-[10px] font-semibold text-violet-900"
                                                >
                                                    {{ upload.concept_path_status_label }}
                                                </span>
                                            </div>
                                            <div class="flex flex-wrap gap-1.5">
                                                <Link
                                                    v-if="upload.run_url"
                                                    :href="upload.run_url"
                                                    class="rounded-md bg-emerald-700 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white hover:bg-emerald-800"
                                                >
                                                    Run
                                                </Link>
                                                <Link
                                                    v-if="upload.concept_path_url"
                                                    :href="upload.concept_path_url"
                                                    class="rounded-md px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide"
                                                    :class="upload.run_url
                                                        ? 'border border-violet-300 bg-white text-violet-800 hover:bg-violet-50'
                                                        : 'bg-violet-700 text-white hover:bg-violet-800'"
                                                >
                                                    {{ upload.run_url ? 'Edit concepts' : 'Build concepts' }}
                                                </Link>
                                                <Link
                                                    v-else
                                                    :href="upload.upload_url"
                                                    class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-slate-800 hover:bg-slate-50"
                                                >
                                                    Open · upload PDF
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-else class="mt-1 text-xs text-slate-600">
                                        No textbook chapter linked yet — choose a book and upload its PDF.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="rounded-md border border-indigo-300 bg-white px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-indigo-800 hover:bg-indigo-50"
                                    @click="uploadForId === row.syllabus_chapter_id ? closeUpload() : openUpload(row)"
                                >
                                    {{ uploadForId === row.syllabus_chapter_id ? 'Cancel' : (row.uploads?.length ? 'Add book / upload PDF' : 'Upload chapter PDF') }}
                                </button>
                            </div>

                            <form
                                v-if="uploadForId === row.syllabus_chapter_id"
                                class="mt-3 space-y-3 rounded-lg border border-indigo-200 bg-indigo-50/50 p-3"
                                @submit.prevent="submitUpload"
                            >
                                <p class="text-xs font-semibold text-indigo-950">
                                    Upload PDF for <span class="font-bold">{{ row.label }}</span> — pick which book this PDF belongs to.
                                </p>

                                <div class="flex flex-wrap gap-4 text-xs text-slate-800">
                                    <label class="inline-flex items-center gap-1.5">
                                        <input v-model="uploadUi.mode" type="radio" value="existing" @change="onModeChange(row)">
                                        Existing book
                                    </label>
                                    <label class="inline-flex items-center gap-1.5">
                                        <input v-model="uploadUi.mode" type="radio" value="new" @change="onModeChange(row)">
                                        New book
                                    </label>
                                </div>

                                <div v-if="uploadUi.mode === 'existing'" class="max-w-md">
                                    <label class="mb-1 block text-xs font-medium text-slate-700">Book</label>
                                    <select
                                        v-model="form.textbook_id"
                                        class="w-full rounded-md border-gray-300 text-sm"
                                        required
                                    >
                                        <option value="" disabled>Choose book…</option>
                                        <option
                                            v-for="book in booksForRow(row)"
                                            :key="book.id"
                                            :value="String(book.id)"
                                        >
                                            {{ book.label }}
                                        </option>
                                    </select>
                                    <p v-if="!booksForRow(row).length" class="mt-1 text-xs text-amber-800">
                                        All class books already have this chapter linked. Create a new book instead.
                                    </p>
                                    <InputError :message="form.errors.textbook_id" class="mt-1" />
                                </div>

                                <div v-else class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-slate-700">Book name</label>
                                        <input
                                            v-model="form.book_name"
                                            type="text"
                                            class="w-full rounded-md border-gray-300 text-sm"
                                            placeholder="e.g. RD Sharma"
                                            required
                                        >
                                        <InputError :message="form.errors.book_name" class="mt-1" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-slate-700">Book code</label>
                                        <input
                                            v-model="form.book_code"
                                            type="text"
                                            class="w-full rounded-md border-gray-300 text-sm"
                                            placeholder="e.g. rds"
                                            required
                                        >
                                        <InputError :message="form.errors.book_code" class="mt-1" />
                                    </div>
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-700">Chapter PDF</label>
                                    <input
                                        type="file"
                                        accept="application/pdf"
                                        class="block w-full text-sm"
                                        required
                                        @change="onPdfChange"
                                    >
                                    <InputError :message="uploadUi.error || form.errors.pdf" class="mt-1" />
                                </div>

                                <PrimaryButton type="submit" class="!text-xs" :disabled="form.processing || !!uploadUi.error">
                                    {{ form.processing ? 'Uploading…' : 'Upload PDF & open concept path' }}
                                </PrimaryButton>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
