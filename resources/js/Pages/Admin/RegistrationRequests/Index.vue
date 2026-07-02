<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    requests: Object,
    filters: Object,
    statuses: Array,
});

const setStatus = (status) => {
    router.get(route('admin.registration-requests.index'), { status }, { preserveState: true });
};

const statusClass = (status) => {
    return {
        pending: 'bg-yellow-100 text-yellow-800',
        approved: 'bg-green-100 text-green-800',
        rejected: 'bg-red-100 text-red-800',
    }[status] ?? 'bg-gray-100 text-gray-800';
};
</script>

<template>
    <Head title="Registration Requests" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Registration Requests
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-full px-3 py-1 text-sm"
                        :class="filters.status === '' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200'"
                        @click="setStatus('')"
                    >
                        All
                    </button>
                    <button
                        v-for="status in statuses"
                        :key="status"
                        type="button"
                        class="rounded-full px-3 py-1 text-sm capitalize"
                        :class="filters.status === status ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200'"
                        @click="setStatus(status)"
                    >
                        {{ status }}
                    </button>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Student</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Class</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Board</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Year</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Submitted</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr v-for="item in requests.data" :key="item.id">
                                <td class="px-4 py-3">
                                    <Link
                                        :href="route('admin.registration-requests.show', item.id)"
                                        class="font-medium text-indigo-600 hover:text-indigo-800"
                                    >
                                        {{ item.student_name }}
                                    </Link>
                                    <p class="text-sm text-gray-500">{{ item.parent1_mobile }}</p>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ item.grade_level?.name }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ item.board?.code }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ item.academic_year?.name }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2 py-1 text-xs font-medium capitalize"
                                        :class="statusClass(item.status)"
                                    >
                                        {{ item.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    {{ new Date(item.created_at).toLocaleDateString() }}
                                </td>
                            </tr>
                            <tr v-if="requests.data.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                    No registration requests found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="requests.links.length > 3" class="flex flex-wrap gap-2">
                    <Link
                        v-for="link in requests.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        class="rounded px-3 py-1 text-sm"
                        :class="link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200'"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
