<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    tasks: { type: Array, default: () => [] },
});

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;
</script>

<template>
    <Head title="My content tasks" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">My content tasks</h2>
                <p class="text-sm text-gray-500">Review rate, upload chapter MCQs, verify every question, then submit for publish.</p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-3 px-4 sm:px-6">
                <div
                    v-for="task in tasks"
                    :key="task.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200"
                >
                    <div>
                        <p class="font-semibold text-gray-900">
                            {{ task.chapter?.grade_name }} · Ch {{ task.chapter?.chapter_number }} — {{ task.chapter?.title }}
                        </p>
                        <p class="text-sm text-gray-500">{{ task.status_label }} · {{ formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}</p>
                    </div>
                    <Link :href="route('content.tasks.show', task.id)" class="text-sm font-medium text-indigo-600 hover:underline">
                        Open →
                    </Link>
                </div>
                <p v-if="!tasks.length" class="rounded-lg bg-white p-8 text-center text-gray-500 shadow-sm ring-1 ring-gray-200">
                    No assignments yet.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
