<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    sheets: { type: Array, default: () => [] },
    gradeLevel: { type: Object, default: null },
});
</script>

<template>
    <Head title="Written sheets" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Written sheets</h2>
                    <p class="text-sm text-gray-500">
                        Generate printable homework, verify, assign, then AI checks student uploads.
                    </p>
                </div>
                <Link :href="route('admin.written-sheets.create')">
                    <PrimaryButton>Create written sheet</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl sm:px-6 lg:px-8">
                <div v-if="gradeLevel" class="mb-4 text-sm text-gray-600">
                    Filtered for <strong>{{ gradeLevel.name }}</strong> (change class from the top bar)
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Set</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Type</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Chapter / topic</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Status</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-600">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="sheet in sheets" :key="sheet.id">
                                <td class="px-4 py-3">
                                    <div class="font-mono font-bold text-indigo-600">{{ sheet.set_code }}</div>
                                    <div class="text-xs text-gray-500">{{ sheet.questions_count }} sums</div>
                                </td>
                                <td class="px-4 py-3">{{ sheet.kind_label }}</td>
                                <td class="px-4 py-3">
                                    <div>{{ sheet.chapter_name || '—' }}</div>
                                    <div v-if="sheet.topic_name" class="text-xs text-gray-500">{{ sheet.topic_name }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
                                        {{ sheet.written_status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex flex-wrap items-center justify-end gap-3">
                                        <Link :href="route('admin.written-sheets.show', sheet.id)" class="text-indigo-600 hover:underline">
                                            Open
                                        </Link>
                                        <Link
                                            v-if="sheet.can_assign"
                                            :href="`${route('admin.written-sheets.show', sheet.id)}#assign`"
                                            class="font-medium text-emerald-700 hover:underline"
                                        >
                                            Assign
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!sheets.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                    No written sheets yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
