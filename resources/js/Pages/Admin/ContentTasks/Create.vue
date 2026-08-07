<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    uploaders: { type: Array, default: () => [] },
    gradeLevel: { type: Object, default: null },
    textbooks: { type: Array, default: () => [] },
    syllabusChapters: { type: Array, default: () => [] },
});

const useNewBook = ref(props.textbooks.length === 0);
const selectedTextbookId = ref(props.textbooks[0]?.id ?? '');

watch(
    () => props.textbooks,
    (books) => {
        if (books.length === 0) {
            useNewBook.value = true;
            selectedTextbookId.value = '';
        } else if (!selectedTextbookId.value) {
            selectedTextbookId.value = books[0].id;
            useNewBook.value = false;
        }
    },
    { immediate: true },
);

const form = useForm({
    assigned_to_user_id: props.uploaders[0]?.id ?? '',
    textbook_id: '',
    book_name: 'Ganita Prakash Part I',
    book_code: 'GP',
    syllabus_chapter_ids: [],
    offered_amount_inr: '',
    duplicate_override_reason: '',
    admin_notes: '',
});

const toggleChapter = (chapterId) => {
    const ids = new Set(form.syllabus_chapter_ids);
    if (ids.has(chapterId)) {
        ids.delete(chapterId);
    } else {
        ids.add(chapterId);
    }
    form.syllabus_chapter_ids = [...ids];
};

const formatInr = (amount) => (amount > 0 ? `₹${Number(amount).toLocaleString('en-IN')}` : '—');

const selectedRatePreview = computed(() => {
    const selected = props.syllabusChapters.filter((ch) => form.syllabus_chapter_ids.includes(ch.id));
    if (!selected.length) {
        return null;
    }
    const amounts = [...new Set(selected.map((ch) => ch.default_amount_inr).filter((a) => a > 0))];
    if (amounts.length === 1) {
        return formatInr(amounts[0]);
    }
    if (amounts.length > 1) {
        return `${formatInr(Math.min(...amounts))} – ${formatInr(Math.max(...amounts))}`;
    }
    return 'Set rates in matrix first';
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        textbook_id: useNewBook.value ? null : selectedTextbookId.value,
        book_name: useNewBook.value ? data.book_name : null,
        book_code: useNewBook.value ? data.book_code : null,
        offered_amount_inr: data.offered_amount_inr === '' ? null : Number(data.offered_amount_inr),
    })).post(route('admin.content-tasks.store'));
};
</script>

<template>
    <Head title="Assign content chapters" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Assign chapters to uploader</h2>
                <p class="text-sm text-gray-500">Class → textbook master → syllabus chapters. One uploader per chapter.</p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6">
                <div v-if="!gradeLevel" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
                    Select a class from the top bar first (e.g. Class 7), then return to this page.
                </div>

                <form v-else class="space-y-4 rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200" @submit.prevent="submit">
                    <div class="rounded-md bg-sky-50 px-4 py-3 text-sm text-sky-900">
                        Assigning for <strong>{{ gradeLevel.name }}</strong>.
                        Default rates come from the <Link :href="route('admin.content-rate-cards.index')" class="underline">rate matrix</Link>.
                    </div>

                    <div>
                        <InputLabel value="Content uploader" />
                        <select
                            v-model="form.assigned_to_user_id"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                            :disabled="uploaders.length === 0"
                        >
                            <option value="" disabled>Select content uploader</option>
                            <option v-for="u in uploaders" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Showing {{ uploaders.length }} user(s) with the Content uploader group
                            (not all mentors). Manage groups under
                            <Link :href="route('admin.users.index')" class="text-indigo-600 underline">People → Users</Link>.
                        </p>
                        <p v-if="uploaders.length < 2" class="mt-1 text-xs text-amber-800">
                            Expected more people? Open their mentor application and click
                            <strong>Grant content uploader access</strong>, or edit the user and tick the Content uploader group.
                        </p>
                        <InputError :message="form.errors.assigned_to_user_id" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel value="Textbook master" />
                        <div v-if="textbooks.length" class="mt-2 space-y-2">
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="useNewBook" type="radio" :value="false" class="border-gray-300">
                                Choose existing book
                            </label>
                            <select
                                v-if="!useNewBook"
                                v-model="selectedTextbookId"
                                class="block w-full rounded-md border-gray-300 text-sm"
                            >
                                <option v-for="book in textbooks" :key="book.id" :value="book.id">{{ book.label }}</option>
                            </select>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="useNewBook" type="radio" :value="true" class="border-gray-300">
                                Add new book master
                            </label>
                        </div>
                        <div v-if="useNewBook || !textbooks.length" class="mt-2 grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Book name" />
                                <TextInput v-model="form.book_name" class="mt-1 block w-full" required />
                                <InputError :message="form.errors.book_name" class="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Book code" />
                                <TextInput v-model="form.book_code" class="mt-1 block w-full" required />
                                <p class="mt-1 text-xs text-gray-500">e.g. GP for Ganita Prakash</p>
                                <InputError :message="form.errors.book_code" class="mt-1" />
                            </div>
                        </div>
                        <InputError :message="form.errors.textbook_id" class="mt-1" />
                    </div>

                    <div v-if="syllabusChapters.length">
                        <InputLabel value="Syllabus chapters" />
                        <div class="mt-2 max-h-64 space-y-2 overflow-y-auto rounded-md border border-gray-200 p-3">
                            <label
                                v-for="chapter in syllabusChapters"
                                :key="chapter.id"
                                class="flex cursor-pointer items-start justify-between gap-2 text-sm"
                                :class="chapter.has_task ? 'text-gray-400' : 'text-gray-800'"
                            >
                                <span class="flex items-start gap-2">
                                    <input
                                        type="checkbox"
                                        class="mt-1 rounded border-gray-300"
                                        :disabled="chapter.has_task"
                                        :checked="form.syllabus_chapter_ids.includes(chapter.id)"
                                        @change="toggleChapter(chapter.id)"
                                    >
                                    <span>
                                        {{ chapter.label }}
                                        <span v-if="chapter.has_task" class="text-xs">(already assigned)</span>
                                    </span>
                                </span>
                                <span class="shrink-0 text-xs text-gray-500">{{ formatInr(chapter.default_amount_inr) }}</span>
                            </label>
                        </div>
                        <p v-if="selectedRatePreview" class="mt-2 text-xs text-gray-600">Matrix rate for selection: {{ selectedRatePreview }}</p>
                        <InputError :message="form.errors.syllabus_chapter_ids" class="mt-1" />
                    </div>
                    <div v-else class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        No syllabus chapters found for {{ gradeLevel.name }}. Check academic year and syllabus setup.
                    </div>

                    <div>
                        <InputLabel value="Rate override (₹, optional — applies to all selected chapters)" />
                        <input v-model="form.offered_amount_inr" type="number" min="100" class="mt-1 block w-full rounded-md border-gray-300 text-sm" placeholder="Leave blank to use matrix per chapter">
                        <InputError :message="form.errors.offered_amount_inr" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel value="Duplicate override reason (only if re-assigning existing content)" />
                        <textarea v-model="form.duplicate_override_reason" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        <InputError :message="form.errors.duplicate_override_reason" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel value="Notes to uploader" />
                        <textarea v-model="form.admin_notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                    </div>

                    <div class="flex gap-3">
                        <PrimaryButton :disabled="form.processing || !syllabusChapters.length">Create assignment(s)</PrimaryButton>
                        <Link :href="route('admin.content-tasks.index')" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700">Cancel</Link>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
