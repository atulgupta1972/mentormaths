<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    context: {
        type: Object,
        default: () => ({ grade_name: null, board_name: null, board_code: null }),
    },
    chapters: { type: Array, default: () => [] },
    total_formulas: { type: Number, default: 0 },
});

const subtitle = computed(() => {
    const parts = [props.context?.grade_name, props.context?.board_code || props.context?.board_name].filter(Boolean);

    return parts.length ? parts.join(' · ') : 'Your class formulas';
});

const chaptersWithFormulas = computed(() => props.chapters.filter((chapter) => chapter.formulas_count > 0));
const emptyChapters = computed(() => props.chapters.filter((chapter) => !chapter.formulas_count));
</script>

<template>
    <Head title="Formulas" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Resources</p>
                <h2 class="text-xl font-semibold text-gray-800">Formulas</h2>
                <p class="text-sm text-gray-500">{{ subtitle }} · {{ total_formulas }} cards</p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6">
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    Browse formula and concept cards chapter by chapter whenever you want — no timer, no score. Use this to revise before exams or your daily drill.
                </div>

                <div v-if="!chapters.length" class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-600">
                    No syllabus chapters found for your class yet.
                </div>

                <section v-else class="space-y-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Chapters with formulas · {{ chaptersWithFormulas.length }}
                    </h3>

                    <div v-if="!chaptersWithFormulas.length" class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-sm text-slate-600">
                        No formula cards published for your class yet.
                    </div>

                    <div v-else class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <Link
                            v-for="chapter in chaptersWithFormulas"
                            :key="chapter.id"
                            :href="route('student.resources.formulas.chapter', chapter.id)"
                            class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-b-0 hover:bg-amber-50/70"
                        >
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">{{ chapter.name }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ chapter.topics_count }} topic{{ chapter.topics_count === 1 ? '' : 's' }}
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-950 ring-1 ring-amber-300">
                                {{ chapter.formulas_count }} formula{{ chapter.formulas_count === 1 ? '' : 's' }} →
                            </span>
                        </Link>
                    </div>
                </section>

                <section v-if="emptyChapters.length" class="space-y-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                        No formulas yet · {{ emptyChapters.length }}
                    </h3>
                    <ul class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-500">
                        <li v-for="chapter in emptyChapters" :key="`empty-${chapter.id}`" class="py-1">
                            {{ chapter.name }}
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
