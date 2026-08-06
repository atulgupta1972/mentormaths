<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    task: { type: Object, required: true },
    activeSeconds: { type: Number, default: 0 },
});

const publishForm = useForm({});

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;
const formatDuration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m ${s}s`;
};
</script>

<template>
    <Head :title="`Task · Ch ${task.chapter?.chapter_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        {{ task.chapter?.grade_name }} · Ch {{ task.chapter?.chapter_number }} — {{ task.chapter?.title }}
                    </h2>
                    <p class="text-sm text-gray-500">{{ task.status_label }}</p>
                </div>
                <Link :href="route('admin.content-tasks.index')" class="text-sm text-indigo-600 hover:underline">← All tasks</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-xs uppercase text-gray-500">Uploader</p>
                        <p class="font-semibold">{{ task.assignee?.name }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-xs uppercase text-gray-500">Agreed rate</p>
                        <p class="font-semibold">{{ task.agreed_amount_inr ? formatInr(task.agreed_amount_inr) : formatInr(task.offered_amount_inr) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-xs uppercase text-gray-500">Active time</p>
                        <p class="font-semibold">{{ formatDuration(activeSeconds) }}</p>
                    </div>
                </div>

                <div v-if="task.duplicate_override_reason" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm">
                    <strong>Duplicate override:</strong> {{ task.duplicate_override_reason }}
                </div>

                <div v-if="task.status === 'submitted_for_publish'" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-sm text-emerald-900">Uploader submitted this chapter for publish. Review textbook chapter, then mark task published.</p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <Link :href="route('admin.textbooks.show', task.chapter.id)" class="text-sm font-medium text-indigo-600 hover:underline">
                            Open textbook chapter →
                        </Link>
                        <PrimaryButton type="button" :disabled="publishForm.processing" @click="publishForm.post(route('admin.content-tasks.publish', task.id))">
                            Mark task published
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
