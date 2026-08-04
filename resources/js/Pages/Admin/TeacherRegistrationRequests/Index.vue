<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    applications: Object,
    filters: Object,
    statuses: Array,
});
</script>

<template>
    <Head title="Mentor applications" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Mentor applications</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div class="mb-4 flex flex-wrap gap-2">
                    <Link
                        :href="route('admin.teacher-registrations.index')"
                        class="rounded-full px-3 py-1 text-xs font-medium"
                        :class="!filters.status ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700'"
                    >
                        All
                    </Link>
                    <Link
                        v-for="status in statuses"
                        :key="status"
                        :href="route('admin.teacher-registrations.index', { status })"
                        class="rounded-full px-3 py-1 text-xs font-medium capitalize"
                        :class="filters.status === status ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700'"
                    >
                        {{ status.replace(/_/g, ' ') }}
                    </Link>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Name</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Email</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Tracks</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Rate</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="row in applications.data" :key="row.id" class="hover:bg-gray-50">
                                <td class="px-4 py-2">
                                    <Link :href="route('admin.teacher-registrations.show', row.id)" class="font-medium text-indigo-600 hover:underline">
                                        {{ row.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-2 text-gray-600">{{ row.email }}</td>
                                <td class="px-4 py-2 text-xs text-gray-600">
                                    <span v-if="row.interested_in_content_creation">
                                        Question bank<span v-if="row.interested_in_book_content_upload"> (+ book upload)</span>
                                    </span>
                                    <span v-if="row.interested_in_content_creation && row.interested_in_doubt_solving"> · </span>
                                    <span v-if="row.interested_in_doubt_solving">Mentoring</span>
                                </td>
                                <td class="px-4 py-2 text-gray-600">
                                    <span v-if="row.proposed_hourly_rate_inr">₹{{ row.proposed_hourly_rate_inr }}/hr</span>
                                    <span v-else-if="row.proposed_rate_per_set_inr">₹{{ row.proposed_rate_per_set_inr }}/set</span>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-4 py-2 capitalize text-gray-700">{{ row.status.replace(/_/g, ' ') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
