<script setup>
/**
 * Shared FIND + formula match board for student play and admin try.
 * One FIND card at a time — student picks the formula only.
 */
import MensurationDiagram from '@/Components/MensurationDiagram.vue';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    play: { type: Object, required: true },
    submitting: { type: Boolean, default: false },
});

const emit = defineEmits(['answer']);

const lastWrongFormula = ref(null);

const matchedFormulas = computed(() => {
    const set = new Set();
    for (const item of props.play?.items || []) {
        if (item.matched && item.matched_formula) {
            set.add(item.matched_formula);
        }
    }
    return set;
});

const currentItem = computed(() => (props.play?.items || []).find((item) => !item.matched) || null);

const progressLabel = computed(() => {
    const items = props.play?.items || [];
    const done = items.filter((i) => i.matched).length;
    return `${done} / ${items.length} matched`;
});

watch(
    () => props.play?.score,
    () => {
        lastWrongFormula.value = null;
    },
);

/**
 * Split story so a matched formula can render inside the blank.
 * @returns {Array<{type: 'text'|'formula', value: string}>}
 */
function storyParts(item) {
    const story = String(item.story || '');
    if (!item.matched || !item.matched_formula) {
        return [{ type: 'text', value: story }];
    }

    const blank = /_{2,}|…+/;
    if (blank.test(story)) {
        const [before, ...rest] = story.split(blank);
        const after = rest.join('____');
        return [
            { type: 'text', value: before },
            { type: 'formula', value: item.matched_formula },
            { type: 'text', value: after },
        ];
    }

    return [
        { type: 'text', value: `${story.trimEnd()} ` },
        { type: 'formula', value: item.matched_formula },
    ];
}

function pickFormula(formula) {
    if (props.play.status === 'completed' || props.submitting) {
        return;
    }
    if (matchedFormulas.value.has(formula)) {
        return;
    }
    const item = currentItem.value;
    if (!item) {
        return;
    }

    emit('answer', {
        item_key: item.key,
        formula,
    });
}

function clearSelection() {
    lastWrongFormula.value = null;
}

defineExpose({ clearSelection });
</script>

<template>
    <div class="space-y-5">
        <p class="text-center text-sm font-semibold text-slate-600">
            {{ progressLabel }}
            <span v-if="play.status !== 'completed' && currentItem" class="text-slate-500">
                · pick the formula for this card
            </span>
        </p>

        <div
            v-if="currentItem"
            class="rounded-xl border border-indigo-200 bg-white p-5 shadow-sm"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="shrink-0 rounded-lg bg-slate-50 p-1">
                    <MensurationDiagram :diagram="currentItem.diagram" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">
                        {{ currentItem.title || 'Find' }}
                    </p>
                    <p class="mt-2 text-base font-medium leading-relaxed text-slate-900">
                        <template v-for="(part, idx) in storyParts(currentItem)" :key="idx">
                            <span
                                v-if="part.type === 'formula'"
                                class="mx-0.5 inline-block rounded bg-emerald-200/90 px-1.5 py-0.5 font-mono font-semibold text-emerald-950"
                            >{{ part.value }}</span>
                            <span v-else>{{ part.value }}</span>
                        </template>
                    </p>
                    <p v-if="currentItem.hint" class="mt-2 text-xs capitalize text-slate-500">
                        Looking for: {{ currentItem.hint }}
                    </p>
                </div>
            </div>
        </div>

        <div v-else class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-center text-emerald-900">
            All cards matched for this board.
        </div>

        <div class="space-y-3">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Formulas</h4>
            <p class="text-xs text-slate-500">Tap the formula that fills the blank.</p>
            <div class="grid gap-2 sm:grid-cols-2">
                <button
                    v-for="formula in play.formulas"
                    :key="formula"
                    type="button"
                    class="rounded-xl border px-4 py-3 text-left font-mono text-sm font-semibold transition"
                    :class="
                        matchedFormulas.has(formula)
                            ? 'cursor-default border-emerald-200 bg-emerald-50 text-emerald-800 opacity-70'
                            : lastWrongFormula === formula
                              ? 'border-rose-400 bg-rose-50 text-rose-900'
                              : 'border-slate-200 bg-white text-slate-800 hover:border-indigo-300'
                    "
                    :disabled="play.status === 'completed' || matchedFormulas.has(formula) || submitting || !currentItem"
                    @click="pickFormula(formula)"
                >
                    {{ formula }}
                    <span v-if="matchedFormulas.has(formula)" class="ml-2 text-[10px] font-sans font-semibold uppercase tracking-wide">used</span>
                </button>
            </div>
        </div>

        <div v-if="(play.items || []).some((i) => i.matched)" class="border-t border-slate-100 pt-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Done</p>
            <ul class="mt-2 space-y-1 text-xs text-slate-600">
                <li v-for="item in play.items.filter((i) => i.matched)" :key="item.key">
                    ✓ {{ item.title }} — {{ item.matched_formula }}
                </li>
            </ul>
        </div>
    </div>
</template>
