<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    users: Object,
    groups: Array,
    filters: Object,
});

const applyFilters = (overrides = {}) => {
    router.get(route('admin.users.index'), { ...props.filters, ...overrides }, { preserveState: true });
};

const toggleActive = (user) => {
    if (!confirm(`${user.is_active ? 'Deactivate' : 'Activate'} ${user.name}?`)) {
        return;
    }

    router.post(route('admin.users.toggle-active', user.id));
};

const groupBadgeClass = (code) => ({
    admin: 'bg-purple-100 text-purple-800',
    teacher: 'bg-blue-100 text-blue-800',
    student: 'bg-green-100 text-green-800',
    parent: 'bg-amber-100 text-amber-800',
}[code] ?? 'bg-gray-100 text-gray-800');
</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Users &amp; Access</h2>
                    <p class="text-sm text-gray-500">Manage logins, groups, and active status</p>
                </div>
                <div class="flex gap-2">
                    <Link :href="route('admin.groups.index')">
                        <SecondaryButton>Manage Groups</SecondaryButton>
                    </Link>
                    <Link :href="route('admin.users.create')">
                        <PrimaryButton>Add User</PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
                    <div>
                        <label class="block text-xs font-medium uppercase text-gray-500">Search</label>
                        <input
                            type="search"
                            class="mt-1 rounded-md border-gray-300 text-sm shadow-sm"
                            :value="filters.search"
                            placeholder="Name, email, mobile"
                            @change="applyFilters({ search: $event.target.value })"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase text-gray-500">Status</label>
                        <select
                            class="mt-1 rounded-md border-gray-300 text-sm shadow-sm"
                            :value="filters.status"
                            @change="applyFilters({ status: $event.target.value })"
                        >
                            <option value="">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase text-gray-500">Group</label>
                        <select
                            class="mt-1 rounded-md border-gray-300 text-sm shadow-sm"
                            :value="filters.group_id"
                            @change="applyFilters({ group_id: $event.target.value })"
                        >
                            <option value="">All groups</option>
                            <option v-for="group in groups" :key="group.id" :value="group.id">
                                {{ group.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">User</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Groups</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="user in users.data" :key="user.id">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ user.name }}</div>
                                    <div class="text-sm text-gray-500">{{ user.email }}</div>
                                    <div v-if="user.mobile" class="text-xs text-gray-400">{{ user.mobile }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="group in user.groups"
                                            :key="group.id"
                                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="groupBadgeClass(group.code)"
                                        >
                                            {{ group.name }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="user.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                    >
                                        {{ user.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-sm">
                                    <Link
                                        :href="route('admin.users.edit', user.id)"
                                        class="text-indigo-600 hover:text-indigo-800"
                                    >
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        class="ms-3"
                                        :class="user.is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800'"
                                        @click="toggleActive(user)"
                                    >
                                        {{ user.is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="users.data.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">
                                    No users found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
