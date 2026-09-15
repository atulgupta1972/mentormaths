<script setup>
/**
 * Shared FIND + formula match board for student play and admin try.
 */
import MensurationDiagram from '@/Components/MensurationDiagram.vue';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    play: { type: Object, required: true },
    submitting: { type: Boolean, default: false },
});

const emit = defineEmits(['answer']);

const selectedFormula = ref(null);
const lastTap = ref(null);
const lastItemKey = ref(null);

const matchedFormulas = computed(() => {
    const set = new Set();
    for (const item of props.play?.items || []) {
        if (item.matched && item.matched_formula) {
            set.add(item.matched_formula);
        }
    }
    return set;
});

watch(
    () => props.play?.score,
    () => {
        if (selectedFormula.value && matchedFormulas.value.has(selectedFormula.value)) {
            selectedFormula.value = null;
        }
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
    if (props.play.status === 'completed' || props.submitting) return;
    if (matchedFormulas.value.has(formula)) return;
    selectedFormula.value = formula;
    lastTap.value = { type: 'formula', value: formula };

    // Allow either order: tap FIND/story first, then formula.
    const pendingItem = (props.play.items || []).find(
        (item) => !item.matched && lastItemKey.value === item.key,
    );
    if (pendingItem) {
        emit('answer', {
            item_key: pendingItem.key,
            formula,
        });
    }
}

function pickItem(item) {
    if (props.play.status === 'completed' || props.submitting) return;
    if (item.matched) return;
    lastItemKey.value = item.key;
    lastTap.value = { type: 'item', value: item.key };
    if (!selectedFormula.value) return;

    emit('answer', {
        item_key: item.key,
        formula: selectedFormula.value,
    });
}

function clearSelection() {
    selectedFormula.value = null;
    lastItemKey.value = null;
}

defineExpose({ clearSelection });
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-5">
        <div class="space-y-3 lg:col-span-3 lg:order-1">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Find</h4>
            <button
                v-for="item in play.items"
                :key="item.key"
                type="button"
                class="w-full rounded-xl border p-4 text-left transition"
                :class="
                    item.matched
                        ? 'border-emerald-300 bg-emerald-50'
                        : lastTap?.type === 'item' && lastTap.value === item.key
                          ? 'border-amber-400 bg-amber-50'
                          : 'border-slate-200 bg-white hover:border-indigo-300'
                "
                :disabled="item.matched || play.status === 'completed' || submitting"
                @click="pickItem(item)"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div class="shrink-0 rounded-lg bg-slate-50 p-1">
                        <MensurationDiagram :diagram="item.diagram" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium leading-relaxed text-slate-900">
                            <template v-for="(part, idx) in storyParts(item)" :key="idx">
                                <span
                                    v-if="part.type === 'formula'"
                                    class="mx-0.5 inline-block rounded bg-emerald-200/90 px-1.5 py-0.5 font-mono font-semibold text-emerald-950"
                                >{{ part.value }}</span>
                                <span v-else>{{ part.value }}</span>
                            </template>
                        </p>
                        <p v-if="item.matched" class="mt-2 text-xs font-semibold uppercase tracking-wide text-emerald-700">
                            Done
                        </p>
                        <p v-else-if="item.hint" class="mt-2 text-xs capitalize text-slate-500">
                            Looking for: {{ item.hint }}
                        </p>
                    </div>
                </div>
            </button>
        </div>

        <div class="space-y-3 lg:col-span-2 lg:order-2">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Formulas</h4>
            <p class="text-xs text-slate-500">Tap a formula, then tap the FIND card. The blank fills only when the match is correct.</p>
            <button
                v-for="formula in play.formulas"
                :key="formula"
                type="button"
                class="block w-full rounded-xl border px-4 py-3 text-left font-mono text-sm font-semibold transition"
                :class="
                    matchedFormulas.has(formula)
                        ? 'cursor-default border-emerald-200 bg-emerald-50 text-emerald-800 opacity-70'
                        : selectedFormula === formula
                          ? 'border-indigo-500 bg-indigo-50 text-indigo-900 ring-2 ring-indigo-200'
                          : 'border-slate-200 bg-white text-slate-800 hover:border-indigo-300'
                "
                :disabled="play.status === 'completed' || matchedFormulas.has(formula) || submitting"
                @click="pickFormula(formula)"
            >
                {{ formula }}
                <span v-if="matchedFormulas.has(formula)" class="ml-2 text-[10px] font-sans font-semibold uppercase tracking-wide">used</span>
            </button>
        </div>
    </div>
</template>
