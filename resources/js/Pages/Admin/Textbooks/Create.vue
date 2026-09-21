<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    gradeLevel: { type: Object, default: null },
    syllabusChapters: { type: Array, default: () => [] },
    books: { type: Array, default: () => [] },
    sourceRefOptions: { type: Array, default: () => [] },
});

const params = new URLSearchParams(window.location.search);
const presetChapterId = params.get('syllabus_chapter_id') || '';

const form = useForm({
    mode: props.books?.length ? 'existing' : 'new',
    textbook_id: props.books?.[0] ? String(props.books[0].id) : '',
    book_name: props.books[0]?.name || 'MentorMaths 1',
    book_code: props.books[0]?.code || 'MM1',
    practice_line: props.books[0]?.practice_line || 'standard',
    source_ref: props.books[0]?.source_ref || '',
    syllabus_chapter_id: presetChapterId,
    pdf: null,
});

const uploadError = ref('');
const isMentorMaths = computed(() => form.practice_line === 'mentormaths');

const selectedChapter = computed(() =>
    props.syllabusChapters.find((chapter) => String(chapter.id) === String(form.syllabus_chapter_id)),
);

const formatMb = (bytes) => `${(bytes / (1024 * 1024)).toFixed(1)} MB`;

const onModeChange = () => {
    if (form.mode === 'existing' && props.books?.[0]) {
        form.textbook_id = String(props.books[0].id);
        form.book_name = props.books[0].name;
        form.book_code = props.books[0].code;
        form.practice_line = props.books[0].practice_line || 'standard';
        form.source_ref = props.books[0].source_ref || '';
    } else if (form.mode === 'new') {
        form.practice_line = 'mentormaths';
        form.book_name = 'MentorMaths 1';
        form.book_code = 'MM1';
        form.source_ref = props.sourceRefOptions?.[0]?.value || '';
    }
};

const onBookSelect = () => {
    const book = props.books.find((row) => String(row.id) === String(form.textbook_id));
    if (book) {
        form.book_name = book.name;
        form.book_code = book.code;
        form.practice_line = book.practice_line || 'standard';
        form.source_ref = book.source_ref || '';
    }
};

const onPracticeLineChange = () => {
    if (form.practice_line === 'mentormaths' && form.mode === 'new') {
        form.book_name = form.book_name?.startsWith('MentorMaths') ? form.book_name : 'MentorMaths 1';
        const code = String(form.book_code || '').toLowerCase();
        if (!code || code === 'gp' || !code.startsWith('mm')) {
            form.book_code = 'MM1';
        }
        if (!form.source_ref && props.sourceRefOptions?.length) {
            form.source_ref = props.sourceRefOptions[0].value;
        }
    }
};

const onPdfChange = (event) => {
    uploadError.value = '';
    const file = event.target.files?.[0] || null;
    form.pdf = file;

    if (!file) {
        return;
    }

    if (file.size > 50 * 1024 * 1024) {
        uploadError.value = 'PDF must be under 50 MB.';
    } else if (file.size > 2 * 1024 * 1024) {
        uploadError.value = `Selected file is ${formatMb(file.size)}. If upload fails, the server PHP limit may still be 2 MB — ask hosting to set upload_max_filesize to 20M or higher.`;
    }
};

const submit = () => {
    form.transform((data) => {
        if (data.mode === 'existing') {
            const book = props.books.find((row) => String(row.id) === String(data.textbook_id));
            return {
                book_name: book?.name || data.book_name,
                book_code: book?.code || data.book_code,
                practice_line: book?.practice_line || data.practice_line || 'standard',
                source_ref: book?.source_ref || data.source_ref || null,
                syllabus_chapter_id: data.syllabus_chapter_id,
                pdf: data.pdf,
            };
        }

        return {
            book_name: data.book_name,
            book_code: data.book_code,
            practice_line: data.practice_line || 'standard',
            source_ref: data.source_ref || null,
            syllabus_chapter_id: data.syllabus_chapter_id,
            pdf: data.pdf,
        };
    }).post(route('admin.textbooks.store'), {
        forceFormData: true,
        onFinish: () => form.transform((data) => data),
    });
};
</script>

<template>
    <Head title="Upload textbook chapter" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Upload textbook chapter</h2>
                    <p class="text-sm text-gray-500">Class → book → chapter PDF. Topics are not needed.</p>
                </div>
                <Link :href="route('admin.textbooks.index')" class="text-sm text-indigo-600">Back</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div v-if="!gradeLevel" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
                    Select a class from the top bar first (e.g. Class 9).
                </div>

                <form
                    v-else
                    class="space-y-5 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200"
                    @submit.prevent="submit"
                >
                    <div class="rounded-md bg-sky-50 px-4 py-3 text-sm text-sky-900">
                        Uploading for <strong>{{ gradeLevel.name }}</strong>.
                        <template v-if="isMentorMaths">
                            MentorMaths line: PDF is private working material → transform to fill-in-blanks only (rewrite + new numbers/names).
                        </template>
                        <template v-else>
                            Step 1: store the chapter PDF here. Step 2: use the AI prompt on the next page with Claude/Cursor/Gemini.
                        </template>
                    </div>

                    <div class="flex flex-wrap gap-4 text-sm text-slate-800">
                        <label class="inline-flex items-center gap-1.5">
                            <input v-model="form.mode" type="radio" value="existing" :disabled="!books?.length" @change="onModeChange">
                            Existing book
                        </label>
                        <label class="inline-flex items-center gap-1.5">
                            <input v-model="form.mode" type="radio" value="new" @change="onModeChange">
                            New book
                        </label>
                    </div>

                    <div v-if="form.mode === 'existing'">
                        <InputLabel for="textbook_id" value="Book" />
                        <select
                            id="textbook_id"
                            v-model="form.textbook_id"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                            required
                            @change="onBookSelect"
                        >
                            <option value="" disabled>Choose book…</option>
                            <option v-for="book in books" :key="book.id" :value="String(book.id)">
                                {{ book.name }} ({{ book.code }})<template v-if="book.is_mentormaths"> · MentorMaths</template>
                            </option>
                        </select>
                        <InputError :message="form.errors.book_name || form.errors.book_code" class="mt-1" />
                    </div>

                    <template v-else>
                        <div>
                            <InputLabel value="Practice line" />
                            <div class="mt-2 flex flex-wrap gap-4 text-sm text-slate-800">
                                <label class="inline-flex items-center gap-1.5">
                                    <input v-model="form.practice_line" type="radio" value="standard" @change="onPracticeLineChange">
                                    Standard (NCERT / GP — MCQ + fill-blank)
                                </label>
                                <label class="inline-flex items-center gap-1.5">
                                    <input v-model="form.practice_line" type="radio" value="mentormaths" @change="onPracticeLineChange">
                                    MentorMaths (fill-blank only · transform from PDF)
                                </label>
                            </div>
                            <InputError :message="form.errors.practice_line" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="book_name" value="Book name (student-facing)" />
                            <TextInput id="book_name" v-model="form.book_name" class="mt-1 block w-full" required />
                            <p v-if="isMentorMaths" class="mt-1 text-xs text-gray-500">
                                Use MentorMaths 1, MentorMaths 2… — never put publisher names here.
                            </p>
                            <InputError :message="form.errors.book_name" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="book_code" value="Book code" />
                            <TextInput id="book_code" v-model="form.book_code" class="mt-1 block w-full" required />
                            <p class="mt-1 text-xs text-gray-500">
                                Short book code for set names — e.g. <strong>MM1</strong> → set <strong>C9-MM1-CH08-F1</strong>.
                            </p>
                            <InputError :message="form.errors.book_code" class="mt-1" />
                        </div>

                        <div v-if="isMentorMaths">
                            <InputLabel for="source_ref" value="Internal source ref (admin only)" />
                            <select
                                id="source_ref"
                                v-model="form.source_ref"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                required
                            >
                                <option value="" disabled>Choose source…</option>
                                <option
                                    v-for="opt in sourceRefOptions"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-amber-800">
                                Never shown to students. Private working note only.
                            </p>
                            <InputError :message="form.errors.source_ref" class="mt-1" />
                        </div>
                    </template>

                    <div>
                        <InputLabel for="syllabus_chapter_id" value="Chapter" />
                        <select
                            id="syllabus_chapter_id"
                            v-model="form.syllabus_chapter_id"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                            required
                        >
                            <option value="" disabled>Choose chapter…</option>
                            <option v-for="chapter in syllabusChapters" :key="chapter.id" :value="chapter.id">
                                {{ chapter.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.syllabus_chapter_id" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="pdf" value="Chapter PDF" />
                        <input
                            id="pdf"
                            type="file"
                            accept="application/pdf"
                            class="mt-1 block w-full text-sm"
                            required
                            @change="onPdfChange"
                        >
                        <p v-if="selectedChapter" class="mt-1 text-xs text-gray-500">
                            Selected syllabus chapter: {{ selectedChapter.name }}
                        </p>
                        <InputError :message="uploadError" class="mt-1" />
                        <InputError :message="form.errors.pdf" class="mt-1" />
                    </div>

                    <PrimaryButton :disabled="form.processing">
                        {{ form.processing ? 'Uploading…' : 'Upload & start extraction' }}
                    </PrimaryButton>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
