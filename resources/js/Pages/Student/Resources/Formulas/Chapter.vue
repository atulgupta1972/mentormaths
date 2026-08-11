<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    chapter: { type: Object, required: true },
    context: {
        type: Object,
        default: () => ({ grade_name: null, board_name: null, board_code: null }),
    },
    formulas_count: { type: Number, default: 0 },
    topics: { type: Array, default: () => [] },
});

const revealedIds = ref(new Set());

const subtitle = computed(() => {
    const parts = [props.context?.grade_name, props.context?.board_code || props.context?.board_name].filter(Boolean);

    return parts.length ? parts.join(' · ') : '';
});

const chapterTitle = computed(() => {
    const number = String(props.chapter?.chapter_number ?? '').trim();
    const name = props.chapter?.name || 'Chapter';

    if (!number) {
        return name;
    }

    const prefix = number.toLowerCase().startsWith('ch') ? number : `Ch ${number}`;

    return `${prefix} — ${name}`;
});

const isRevealed = (cardId) => revealedIds.value.has(cardId);

const toggleReveal = (cardId) => {
    const next = new Set(revealedIds.value);
    if (next.has(cardId)) {
        next.delete(cardId);
    } else {
        next.add(cardId);
    }
    revealedIds.value = next;
};

const revealAll = () => {
    revealedIds.value = new Set(
        props.topics.flatMap((topic) => (topic.cards || []).map((card) => card.id)),
    );
};

const hideAll = () => {
    revealedIds.value = new Set();
};
</script>

<template>
    <Head :title="`Formulas · ${chapter.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Resources · Formulas</p>
                    <h2 class="text-xl font-semibold text-gray-800">{{ chapterTitle }}</h2>
                    <p class="text-sm text-gray-500">
                        {{ subtitle }}
                        <span v-if="subtitle"> · </span>
                        {{ formulas_count }} card{{ formulas_count === 1 ? '' : 's' }}
                    </p>
                </div>
                <Link
                    :href="route('student.resources.formulas.index')"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    ← All chapters
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-slate-600">
                        Tap a card to show the answer. Go through at your own pace.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-950 hover:bg-amber-100"
                            @click="revealAll"
                        >
                            Show all answers
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            @click="hideAll"
                        >
                            Hide answers
                        </button>
                    </div>
                </div>

                <div v-if="!topics.length" class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-600">
                    No formula cards in this chapter yet.
                </div>

                <section
                    v-for="topic in topics"
                    :key="topic.topic_name"
                    class="space-y-3"
                >
                    <h3 class="text-sm font-semibold text-slate-800">{{ topic.topic_name }}</h3>

                    <div
                        v-for="card in topic.cards"
                        :key="card.id"
                        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                    >
                        <button
                            type="button"
                            class="w-full px-4 py-3 text-left hover:bg-slate-50"
                            @click="toggleReveal(card.id)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                        Card {{ card.number }}
                                    </p>
                                    <p class="mt-1 text-sm font-medium text-slate-900">{{ card.question_text }}</p>
                                </div>
                                <span class="shrink-0 text-[11px] font-semibold text-amber-800">
                                    {{ isRevealed(card.id) ? 'Hide' : 'Show answer' }}
                                </span>
                            </div>
                        </button>

                        <div
                            v-if="isRevealed(card.id)"
                            class="border-t border-amber-100 bg-amber-50 px-4 py-3"
                        >
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-800">Answer</p>
                            <p class="mt-1 text-sm font-semibold text-amber-950">
                                {{ card.correct_answer || '—' }}
                            </p>
                            <p v-if="card.explanation" class="mt-2 text-xs leading-relaxed text-amber-900/80">
                                {{ card.explanation }}
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
