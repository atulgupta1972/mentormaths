<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    cards: { type: Array, default: () => [] },
});

const mapped = computed(() => props.cards.filter((card) => card.places?.length));
const unmapped = computed(() => props.cards.filter((card) => ! card.places?.length));

const placeLabel = (place) => [place.board, place.class].filter(Boolean).join(' · ');
const topicLabel = (card) => [card.chapter_head, card.sub_heading].filter(Boolean).join(' · ');
</script>

<template>
    <Head title="MentorMaths methods (MMM)" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">MentorMaths methods (MMM)</h2>
        </template>

        <div class="mx-auto max-w-4xl space-y-6 p-4 sm:p-6">
            <p class="text-sm text-gray-600">
                Open a method to set its chapter head, sub heading, short description, and the classes that should see it in Learn Methods.
            </p>

            <section class="space-y-2">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                    Mapped
                    <span class="rounded-full bg-teal-50 px-2 py-0.5 text-xs font-bold text-teal-800">{{ mapped.length }}</span>
                </h3>
                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                            <tr>
                                <th class="px-3 py-2">Heading</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Exercises</th>
                                <th class="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="card in mapped"
                                :key="card.id"
                                class="border-t border-slate-100 align-top"
                            >
                                <td class="px-3 py-2">
                                    <p class="font-semibold text-slate-900">{{ card.heading }}</p>
                                    <p v-if="topicLabel(card)" class="mt-0.5 text-[11px] font-medium text-indigo-800">
                                        {{ topicLabel(card) }}
                                    </p>
                                    <p v-if="card.classes?.length" class="text-[11px] text-teal-800">
                                        {{ card.classes.join(', ') }}
                                    </p>
                                    <p
                                        v-for="(place, index) in card.places"
                                        :key="`${card.id}-${index}`"
                                        class="mt-0.5 text-[11px] leading-snug text-slate-500"
                                    >
                                        <span class="font-semibold text-slate-700">{{ placeLabel(place) }}</span>
                                        <span v-if="place.chapters?.length"> · {{ place.chapters.join(' · ') }}</span>
                                    </p>
                                </td>
                                <td class="px-3 py-2 capitalize text-slate-700">{{ card.status }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ card.exercises_count }}</td>
                                <td class="px-3 py-2 text-right">
                                    <Link
                                        :href="route('admin.mmm-cards.show', card.id)"
                                        class="text-sm font-semibold text-teal-700 hover:underline"
                                    >
                                        Open
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!mapped.length">
                                <td colspan="4" class="px-3 py-6 text-center text-slate-500">
                                    No card is linked to a chapter yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-2">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                    Not mapped
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700">{{ unmapped.length }}</span>
                </h3>
                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                            <tr>
                                <th class="px-3 py-2">Heading</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Exercises</th>
                                <th class="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="card in unmapped"
                                :key="card.id"
                                class="border-t border-slate-100"
                            >
                                <td class="px-3 py-2">
                                    <p class="font-semibold text-slate-900">{{ card.heading }}</p>
                                    <p v-if="topicLabel(card)" class="mt-0.5 text-[11px] font-medium text-indigo-800">
                                        {{ topicLabel(card) }}
                                    </p>
                                    <p v-if="card.classes?.length" class="text-[11px] text-teal-800">
                                        {{ card.classes.join(', ') }}
                                    </p>
                                </td>
                                <td class="px-3 py-2 capitalize text-slate-700">{{ card.status }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ card.exercises_count }}</td>
                                <td class="px-3 py-2 text-right">
                                    <Link
                                        :href="route('admin.mmm-cards.show', card.id)"
                                        class="text-sm font-semibold text-teal-700 hover:underline"
                                    >
                                        Open
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!unmapped.length">
                                <td colspan="4" class="px-3 py-6 text-center text-slate-500">
                                    Every card is linked to a class and chapter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
