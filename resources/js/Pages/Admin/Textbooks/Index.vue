<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({
    chapters: { type: Array, default: () => [] },
    gradeLevel: { type: Object, default: null },
});

const page = usePage();
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

                <div v-if="gradeLevel" class="mb-4 text-sm text-gray-600">
                    Filtered for <strong>{{ gradeLevel.name }}</strong> (change class from the top bar)
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
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="row in chapters" :key="row.id">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ row.book_name }}</div>
                                    <div class="text-xs text-gray-500">{{ row.book_code }} · {{ row.grade_name }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">Ch {{ row.chapter_number }} — {{ row.title }}</div>
                                    <div class="text-xs text-gray-500">{{ row.items_count }} question(s) extracted</div>
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
                                    <div v-if="row.mcq_set_code">MCQ: {{ row.mcq_set_code }}</div>
                                    <div v-if="row.written_set_code">Written: {{ row.written_set_code }}</div>
                                    <span v-if="!row.mcq_set_code && !row.written_set_code">—</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="route('admin.textbooks.show', row.id)" class="text-indigo-600 hover:underline">
                                        Open
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!chapters.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                    No textbook chapters yet. Upload Class 9 Chapter 8 to start.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
