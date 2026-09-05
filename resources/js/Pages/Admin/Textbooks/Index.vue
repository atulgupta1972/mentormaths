<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    chapters: { type: Array, default: () => [] },
    books: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ book_id: null }) },
    gradeLevel: { type: Object, default: null },
});

const page = usePage();

const selectedBookId = computed(() => props.filters?.book_id ?? '');

const groupedChapters = computed(() => {
    const groups = [];
    let current = null;

    for (const row of props.chapters) {
        if (! current || current.textbook_id !== row.textbook_id) {
            current = {
                textbook_id: row.textbook_id,
                book_name: row.book_name,
                book_code: row.book_code,
                grade_name: row.grade_name,
                rows: [],
            };
            groups.push(current);
        }

        current.rows.push(row);
    }

    return groups;
});

const setBookFilter = (event) => {
    const bookId = event.target.value || undefined;

    router.get(route('admin.textbooks.index'), {
        book_id: bookId,
    }, {
        preserveState: true,
        replace: true,
    });
};
</script>

<template>
    <Head title="Textbook content" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Textbook content</h2>
                    <p class="text-sm text-gray-500">
                        Upload chapter PDFs → copy AI prompt → paste MCQ JSON → publish sets like <strong>C9-GP-CH08-M</strong>.
                    </p>
                </div>
                <Link :href="route('admin.textbooks.create')">
                    <PrimaryButton>Upload chapter</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div class="mb-4 flex flex-wrap items-end gap-4">
                    <div v-if="gradeLevel" class="text-sm text-gray-600">
                        Class: <strong>{{ gradeLevel.name }}</strong> (change from the top bar)
                    </div>
                    <div v-if="books.length" class="min-w-[14rem]">
                        <label for="book_filter" class="block text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Book
                        </label>
                        <select
                            id="book_filter"
                            :value="selectedBookId"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            @change="setBookFilter"
                        >
                            <option value="">All books</option>
                            <option
                                v-for="book in books"
                                :key="book.id"
                                :value="book.id"
                            >
                                {{ book.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Book</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Chapter</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Status</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Sets</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-600">Action</th>
                            </tr>
                        </thead>
                        <template v-for="group in groupedChapters" :key="`book-${group.textbook_id}`">
                            <tbody v-if="!selectedBookId" class="divide-y divide-gray-100 border-t-2 border-indigo-100">
                                <tr class="bg-indigo-50/70">
                                    <td colspan="5" class="px-4 py-2 text-xs font-bold uppercase tracking-wide text-indigo-900">
                                        {{ group.book_name }} · {{ group.book_code }} · {{ group.grade_name }}
                                    </td>
                                </tr>
                            </tbody>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="row in group.rows" :key="row.id">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900">{{ row.book_name }}</div>
                                        <div class="text-xs text-gray-500">{{ row.book_code }} · {{ row.grade_name }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium">{{ row.label || `Ch ${row.chapter_number} — ${row.title}` }}</div>
                                        <div class="text-xs text-gray-500">{{ row.items_count }} question(s) extracted</div>
                                        <div v-if="row.concept_path_status_label" class="mt-0.5 text-[11px] text-violet-800">
                                            Concepts: {{ row.concept_path_status_label }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="rounded-full px-2 py-1 text-xs font-medium"
                                            :class="{
                                                'bg-emerald-100 text-emerald-800': row.status === 'published',
                                                'bg-amber-100 text-amber-900': row.status === 'review',
                                                'bg-sky-100 text-sky-800': row.status === 'extracting',
                                                'bg-rose-100 text-rose-800': row.status === 'failed',
                                                'bg-slate-100 text-slate-700': row.status === 'draft',
                                            }"
                                        >
                                            {{ row.status_label }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600">
                                        <div v-if="row.mcq_set_codes?.length">
                                            MCQ: {{ row.mcq_set_codes.join(', ') }}
                                        </div>
                                        <div v-else-if="row.mcq_set_code">MCQ: {{ row.mcq_set_code }}</div>
                                        <div v-if="row.fill_blank_set_code">Fill-blank: {{ row.fill_blank_set_code }}</div>
                                        <div v-if="row.written_set_code">Written: {{ row.written_set_code }}</div>
                                        <div v-if="row.fill_blank_ready_count && !row.fill_blank_set_code" class="text-violet-700">
                                            {{ row.fill_blank_ready_count }} fill-blank ready (not published)
                                        </div>
                                        <span v-if="!row.mcq_set_code && !row.mcq_set_codes?.length && !row.written_set_code && !row.fill_blank_set_code && !row.fill_blank_ready_count">—</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex flex-col items-end gap-1">
                                            <Link :href="route('admin.textbooks.show', row.id)" class="text-indigo-600 hover:underline">
                                                Open
                                            </Link>
                                            <Link
                                                v-if="row.has_pdf"
                                                :href="route('admin.textbooks.concept-path', row.id)"
                                                class="text-xs font-semibold text-violet-700 hover:underline"
                                            >
                                                Concept path
                                            </Link>
                                            <Link
                                                v-if="row.can_convert_fill_blank"
                                                :href="route('admin.textbooks.convert-gemini', row.id)"
                                                class="text-xs font-semibold text-violet-700 hover:underline"
                                            >
                                                Gemini fill-blank
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </template>
                        <tbody v-if="!chapters.length">
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                    No textbook chapters yet. Upload a chapter PDF to start.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
