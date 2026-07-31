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
});

const form = useForm({
    book_name: props.books[0]?.name || 'Ganita Manjari Part I',
    book_code: props.books[0]?.code || 'iemh1',
    syllabus_chapter_id: '',
    pdf: null,
});

const uploadError = ref('');

const selectedChapter = computed(() =>
    props.syllabusChapters.find((chapter) => String(chapter.id) === String(form.syllabus_chapter_id)),
);

const formatMb = (bytes) => `${(bytes / (1024 * 1024)).toFixed(1)} MB`;

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
    form.post(route('admin.textbooks.store'), {
        forceFormData: true,
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
                        AI will extract examples, inline exercises, and end-of-chapter questions (not Think &amp; Reflect).
                    </div>

                    <div>
                        <InputLabel for="book_name" value="Book name" />
                        <TextInput id="book_name" v-model="form.book_name" class="mt-1 block w-full" required />
                        <InputError :message="form.errors.book_name" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="book_code" value="Book code" />
                        <TextInput id="book_code" v-model="form.book_code" class="mt-1 block w-full" required />
                        <p class="mt-1 text-xs text-gray-500">e.g. iemh1 for Ganita Manjari Part I — chapter file iemh108.pdf is Ch 8.</p>
                        <InputError :message="form.errors.book_code" class="mt-1" />
                    </div>

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
