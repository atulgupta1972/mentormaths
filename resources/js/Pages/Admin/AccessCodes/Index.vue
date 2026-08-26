<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    codes: Object,
    filters: Object,
    trialDays: Number,
    types: Array,
    statuses: Array,
});

const extendCode = (id) => {
    router.post(route('admin.access-codes.extend', id), { days: props.trialDays }, { preserveScroll: true });
};

const resendCode = (id) => {
    router.post(route('admin.access-codes.resend', id), {}, { preserveScroll: true });
};

const filter = (key, value) => {
    router.get(route('admin.access-codes.index'), {
        ...props.filters,
        [key]: value || undefined,
    }, { preserveState: true, replace: true });
};
</script>

<template>
    <Head title="Access codes" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Access codes (tcode)</h2>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-6xl space-y-4 px-4 sm:px-6 lg:px-8">
                <p class="text-sm text-gray-600">
                    Code master for self-serve trials. Extend adds {{ trialDays }} days (future payment link can hook here).
                </p>

                <div class="flex flex-wrap gap-2">
                    <select
                        class="rounded-md border-gray-300 text-sm"
                        :value="filters.type || ''"
                        @change="filter('type', $event.target.value)"
                    >
                        <option value="">All types</option>
                        <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                    </select>
                    <select
                        class="rounded-md border-gray-300 text-sm"
                        :value="filters.status || ''"
                        @change="filter('status', $event.target.value)"
                    >
                        <option value="">All statuses</option>
                        <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Code</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">User</th>
                                <th class="px-4 py-3">Expires</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="row in codes.data" :key="row.id">
                                <td class="px-4 py-3 font-mono font-semibold">{{ row.code }}</td>
                                <td class="px-4 py-3">{{ row.type }}</td>
                                <td class="px-4 py-3">
                                    <div>{{ row.user?.name }}</div>
                                    <div class="text-xs text-gray-500">{{ row.user?.email }}</div>
                                </td>
                                <td class="px-4 py-3">{{ row.expires_at }}</td>
                                <td class="px-4 py-3">{{ row.status }}</td>
                                <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                                    <PrimaryButton type="button" class="text-xs" @click="resendCode(row.id)">
                                        Resend email
                                    </PrimaryButton>
                                    <PrimaryButton type="button" class="text-xs" @click="extendCode(row.id)">
                                        Extend +{{ trialDays }}d
                                    </PrimaryButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="codes.prev_page_url || codes.next_page_url" class="flex gap-3">
                    <Link
                        v-if="codes.prev_page_url"
                        :href="codes.prev_page_url"
                        class="text-sm text-indigo-600"
                    >Previous</Link>
                    <Link
                        v-if="codes.next_page_url"
                        :href="codes.next_page_url"
                        class="text-sm text-indigo-600"
                    >Next</Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
