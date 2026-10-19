<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    cards: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="MentorMaths methods (MMM)" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">MentorMaths methods (MMM)</h2>
        </template>

        <div class="mx-auto max-w-4xl space-y-4 p-4 sm:p-6">
            <p class="text-sm text-gray-600">
                Method cards that sit beside book concept cards. Link each method to one or more board / class / chapters.
            </p>

            <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-3 py-2">Heading</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Exercises</th>
                            <th class="px-3 py-2">Chapters</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="card in cards"
                            :key="card.id"
                            class="border-t border-slate-100"
                        >
                            <td class="px-3 py-2 font-semibold text-slate-900">
                                {{ card.heading }}
                            </td>
                            <td class="px-3 py-2 capitalize text-slate-700">{{ card.status }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ card.exercises_count }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ card.chapters_count }}</td>
                            <td class="px-3 py-2 text-right">
                                <Link
                                    :href="route('admin.mmm-cards.show', card.id)"
                                    class="text-sm font-semibold text-teal-700 hover:underline"
                                >
                                    Open
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="!cards.length">
                            <td colspan="5" class="px-3 py-6 text-center text-slate-500">
                                No MMM cards yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
