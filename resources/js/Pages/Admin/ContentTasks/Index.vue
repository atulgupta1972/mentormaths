<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    tasks: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    pendingPublishCount: { type: Number, default: 0 },
});

const formatInr = (amount) => (amount ? `₹${Number(amount).toLocaleString('en-IN')}` : '—');
</script>

<template>
    <Head title="Content upload tasks" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Content upload tasks</h2>
                    <p class="text-sm text-gray-500">Assign chapters, track agreement, and publish when verified.</p>
                </div>
                <div class="flex gap-2">
                    <Link :href="route('admin.content-rate-cards.index')" class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Rate matrix
                    </Link>
                    <Link :href="route('admin.content-tasks.create')">
                        <PrimaryButton>Assign chapters</PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-4 px-4 sm:px-6">
                <div v-if="pendingPublishCount" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    <strong>{{ pendingPublishCount }}</strong> chapter(s) submitted and waiting for admin publish.
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Chapter</th>
                                <th class="px-4 py-3">Uploader</th>
                                <th class="px-4 py-3">Rate</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="task in tasks.data" :key="task.id">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-gray-900">
                                        {{ task.chapter?.grade_name }} · Ch {{ task.chapter?.chapter_number }}
                                    </p>
                                    <p class="text-xs text-gray-500">{{ task.chapter?.title }}</p>
                                </td>
                                <td class="px-4 py-3">{{ task.assignee?.name }}</td>
                                <td class="px-4 py-3">{{ formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}</td>
                                <td class="px-4 py-3">{{ task.status_label }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="route('admin.content-tasks.show', task.id)" class="text-indigo-600 hover:underline">View</Link>
                                </td>
                            </tr>
                            <tr v-if="!tasks.data.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">No tasks yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
