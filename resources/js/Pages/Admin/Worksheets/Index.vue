<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    worksheets: Array,
});
</script>

<template>
    <Head title="Worksheets" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">Worksheets</h2>
                <Link
                    :href="route('admin.worksheets.create')"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    Create worksheet
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Title</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Questions</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="ws in worksheets" :key="ws.id">
                                <td class="px-4 py-3">
                                    <Link :href="route('admin.worksheets.show', ws.id)" class="font-medium text-indigo-600">
                                        {{ ws.title }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">{{ ws.questions_count }}</td>
                                <td class="px-4 py-3 capitalize">{{ ws.status }}</td>
                            </tr>
                            <tr v-if="worksheets.length === 0">
                                <td colspan="3" class="px-4 py-8 text-center text-gray-500">
                                    No worksheets yet. Save questions first, then create a worksheet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
