<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateTime } from '@/utils/dates';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    items: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Help history" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-500">Asked for help</p>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">Doubt history</h2>
                </div>
                <Link
                    :href="route('dashboard')"
                    class="text-sm font-semibold text-indigo-600 hover:text-indigo-800"
                >
                    Back to dashboard
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <div v-if="items.length" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="divide-y divide-gray-100">
                        <article
                            v-for="item in items"
                            :key="item.id"
                            class="p-4 sm:p-5"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p v-if="item.set_code" class="font-mono text-sm font-semibold text-indigo-600">
                                    {{ item.set_code }}
                                </p>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-600">
                                    {{ item.clearance_label }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm text-gray-800">{{ item.question_text }}</p>
                            <p v-if="item.topic_label" class="mt-1 text-xs text-gray-500">{{ item.topic_label }}</p>
                            <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                <div class="rounded-lg bg-rose-50 px-3 py-2 text-rose-900">
                                    <dt class="font-semibold uppercase tracking-wide text-[10px] text-rose-700">Asked for help</dt>
                                    <dd class="mt-0.5">{{ formatDateTime(item.gave_up_at) }}</dd>
                                </div>
                                <div class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-900">
                                    <dt class="font-semibold uppercase tracking-wide text-[10px] text-emerald-700">Cleared</dt>
                                    <dd class="mt-0.5">{{ formatDateTime(item.resolved_at) }}</dd>
                                </div>
                            </dl>
                        </article>
                    </div>
                </div>

                <div v-else class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600">
                    No cleared doubts yet. When you tick items off your help list, they will appear here with dates.
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
