<script setup>
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    exercise: { type: Object, required: true },
    card: { type: Object, required: true },
    exercise_index: { type: Number, default: 0 },
    check_url: { type: String, required: true },
});

const emit = defineEmits(['next']);

const letters = [
    { id: 'B', name: 'Brackets' },
    { id: 'O', name: 'Of' },
    { id: 'D', name: 'Division' },
    { id: 'M', name: 'Multiplication' },
    { id: 'A', name: 'Addition' },
    { id: 'S', name: 'Subtraction' },
];

const play = computed(() => props.exercise?.bodmas || {});
const steps = computed(() => play.value.steps || []);
const currentIndex = ref(0);
const currentStep = computed(() => steps.value[currentIndex.value] || null);
const currentKey = computed(() => currentStep.value?.key || '');
const selected = ref([]);
const boxes = reactive({});
const cellResults = ref({});
const trail = ref([]);
const message = ref('');
const stepOk = ref(false);
const submitting = ref(false);
const locked = ref(false);
const allCorrect = ref(false);

const seenLetters = computed(() => {
    const set = new Set();
    steps.value.slice(0, currentIndex.value).forEach((step) => {
        if (step.letter) {
            set.add(step.letter);
        }
    });
    if (allCorrect.value && currentStep.value?.letter) {
        set.add(currentStep.value.letter);
    }

    return set;
});

const canGoNext = computed(() => {
    const total = Number(props.card?.exercises_total ?? 0);

    return total > 0 && Number(props.exercise_index ?? 0) + 1 < total;
});

const boxKeys = computed(() => (currentStep.value?.pieces || [])
    .filter((piece) => piece.box)
    .map((piece) => piece.box));

const canCheck = computed(() => {
    const step = currentStep.value;
    if (! step || submitting.value || locked.value || allCorrect.value) {
        return false;
    }
    if (step.type === 'tap') {
        return selected.value.length > 0;
    }
    if (step.type === 'next') {
        return true;
    }

    return boxKeys.value.length > 0 && boxKeys.value.every((key) => String(boxes[key] || '').trim() !== '');
});

const resetBoxes = () => {
    Object.keys(boxes).forEach((key) => {
        delete boxes[key];
    });
};

const reset = () => {
    currentIndex.value = 0;
    selected.value = [];
    resetBoxes();
    cellResults.value = {};
    trail.value = [];
    message.value = '';
    stepOk.value = false;
    submitting.value = false;
    locked.value = false;
    allCorrect.value = false;
};

watch(() => props.exercise?.id, () => reset());

const letterClass = (id) => {
    if (id === currentStep.value?.letter && ! allCorrect.value) {
        return 'bg-amber-500 text-white shadow-sm';
    }
    if (seenLetters.value.has(id)) {
        return 'bg-emerald-100 text-emerald-900';
    }

    return 'bg-slate-100 text-slate-400';
};

const chipClass = (id) => {
    const result = cellResults.value[id];
    if (result === true) {
        return 'border-emerald-500 bg-emerald-50 text-emerald-950';
    }
    if (result === false) {
        return 'border-rose-500 bg-rose-50 text-rose-950';
    }
    if (selected.value.includes(id)) {
        return 'border-amber-500 bg-amber-50 text-amber-950 shadow-sm';
    }

    return 'border-slate-300 bg-white text-slate-900 hover:border-amber-400';
};

const boxClass = (key) => {
    const result = cellResults.value[key];
    if (result === true) {
        return 'border-emerald-500 bg-emerald-50';
    }
    if (result === false) {
        return 'border-rose-500 bg-rose-50';
    }

    return 'border-slate-300 bg-white';
};

const cleanMath = (value) => String(value ?? '')
    .replace(/[−–—﹣－]/g, '-')
    .replace(/[＋﹢]/g, '+')
    .replace(/\s+/g, '');

const toggle = (id) => {
    if (submitting.value || locked.value || allCorrect.value || currentStep.value?.type !== 'tap') {
        return;
    }
    cellResults.value = {};
    message.value = '';
    stepOk.value = false;
    selected.value = selected.value.includes(id)
        ? selected.value.filter((item) => item !== id)
        : [...selected.value, id];
    const need = currentStep.value?.hits?.length || 0;
    if (need > 0 && selected.value.length === need) {
        checkStep();
    }
};

const checkStep = async () => {
    if (! canCheck.value || ! currentKey.value) {
        return;
    }
    submitting.value = true;
    message.value = '';
    stepOk.value = false;
    const step = currentStep.value;
    try {
        const payload = {
            exercise_id: props.exercise.id,
            step_key: currentKey.value,
            value: step?.type === 'next' ? (step.value || 'ok') : selected.value.join(','),
        };
        if (step?.type === 'tap') {
            payload.values = { hits: [...selected.value] };
        }
        if (step?.type === 'fill') {
            const values = {};
            boxKeys.value.forEach((key) => {
                values[key] = cleanMath(boxes[key]);
            });
            payload.values = values;
        }
        const { data } = await axios.post(props.check_url, payload, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        });
        const ok = !! data.ok;
        stepOk.value = ok;
        cellResults.value = data.cell_results || {};
        message.value = data.message || '';
        if (ok) {
            locked.value = true;
            const line = step?.done_pieces?.length
                ? { pieces: step.done_pieces }
                : (step?.done ? { text: step.done } : null);
            if (line) {
                trail.value = [...trail.value, line];
            }
            if (data.all_ok) {
                allCorrect.value = true;
                locked.value = false;
            } else {
                window.setTimeout(() => {
                    currentIndex.value += 1;
                    selected.value = [];
                    resetBoxes();
                    cellResults.value = {};
                    message.value = '';
                    stepOk.value = false;
                    locked.value = false;
                }, 550);
            }
        }
    } catch (e) {
        message.value = e?.response?.data?.message || 'Could not check this step. Try again.';
        stepOk.value = false;
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <div class="mt-4 space-y-4">
        <ol class="grid grid-cols-6 gap-1">
            <li
                v-for="letter in letters"
                :key="letter.id"
                class="rounded-lg px-1 py-1.5 text-center"
                :class="letterClass(letter.id)"
            >
                <div class="text-sm font-black leading-none">{{ letter.id }}</div>
                <div class="mt-0.5 text-[9px] font-semibold leading-tight sm:text-[10px]">{{ letter.name }}</div>
            </li>
        </ol>

        <p class="text-center font-mono text-xl font-black tracking-tight text-slate-900 sm:text-2xl">
            {{ play.expression }}
        </p>

        <div v-if="trail.length" class="space-y-1 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-950">
            <div
                v-for="(line, index) in trail"
                :key="index"
                class="flex flex-nowrap items-center gap-1 overflow-x-auto font-mono"
            >
                <template v-if="line.pieces">
                    <template v-for="(piece, pi) in line.pieces" :key="`${index}-${pi}`">
                        <span v-if="piece.num" class="inline-flex flex-col items-center leading-none">
                            <span class="px-1">{{ piece.num }}</span>
                            <span class="my-0.5 h-[2px] w-full min-w-[1.25rem] rounded-full bg-emerald-800" />
                            <span>{{ piece.den }}</span>
                        </span>
                        <span v-else class="whitespace-nowrap">{{ piece.text }}</span>
                    </template>
                </template>
                <span v-else>{{ line.text }}</span>
            </div>
        </div>

        <div v-if="currentStep && !allCorrect" class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
            <p class="text-sm font-semibold leading-relaxed text-slate-800">
                {{ currentStep.prompt }}
            </p>

            <div
                v-if="currentStep.above?.length"
                class="flex flex-nowrap items-center justify-start gap-2 overflow-x-auto py-1 font-mono text-lg font-extrabold text-slate-900"
            >
                <template v-for="(piece, index) in currentStep.above" :key="`above-${index}`">
                    <span v-if="piece.num" class="inline-flex flex-col items-center leading-none">
                        <span class="px-1">{{ piece.num }}</span>
                        <span class="my-1 h-[2px] w-full min-w-[1.5rem] rounded-full bg-slate-800" />
                        <span>{{ piece.den }}</span>
                    </span>
                    <span v-else class="whitespace-nowrap">{{ piece.text }}</span>
                </template>
            </div>

            <div class="flex flex-nowrap items-center justify-start gap-2 overflow-x-auto py-1 font-mono text-lg font-extrabold text-slate-900 sm:text-xl">
                <template v-for="(piece, index) in currentStep.pieces || []" :key="`${currentStep.key}-${index}`">
                    <input
                        v-if="piece.box"
                        v-model="boxes[piece.box]"
                        type="text"
                        inputmode="text"
                        class="h-10 w-16 rounded-md border px-1 text-center font-mono text-lg font-extrabold"
                        :class="boxClass(piece.box)"
                        placeholder="?"
                        autocomplete="off"
                        :aria-label="piece.label || piece.box"
                        @keyup.enter="checkStep"
                    >
                    <span v-else-if="piece.num" class="inline-flex flex-col items-center leading-none">
                        <span class="px-1">{{ piece.num }}</span>
                        <span class="my-1 h-[2px] w-full min-w-[1.5rem] rounded-full bg-slate-800" />
                        <span>{{ piece.den }}</span>
                    </span>
                    <button
                        v-else-if="currentStep.type === 'tap' && piece.id"
                        type="button"
                        class="rounded-lg border-2 px-2.5 py-1 whitespace-nowrap underline-offset-4"
                        :class="[chipClass(piece.id), selected.includes(piece.id) ? 'underline decoration-amber-600 decoration-2' : '']"
                        @click="toggle(piece.id)"
                    >
                        {{ piece.text }}
                    </button>
                    <span v-else class="whitespace-nowrap">{{ piece.text }}</span>
                </template>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-md bg-amber-700 px-4 py-2 text-sm font-extrabold text-white hover:bg-amber-800 disabled:opacity-50"
                    :disabled="!canCheck"
                    @click="checkStep"
                >
                    {{ submitting ? 'Checking…' : (currentStep.button || (currentStep.type === 'next' ? 'Continue' : 'Check')) }}
                </button>
                <button
                    type="button"
                    class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700"
                    @click="reset"
                >
                    Start again
                </button>
            </div>
        </div>

        <p
            v-if="message && !allCorrect"
            class="rounded-md px-3 py-2 text-sm font-medium"
            :class="stepOk ? 'bg-emerald-50 text-emerald-900' : 'bg-rose-50 text-rose-900'"
        >
            {{ message }}
        </p>

        <div
            v-if="allCorrect"
            class="space-y-3 rounded-xl border-2 border-emerald-400 bg-emerald-50 px-4 py-4"
        >
            <p class="text-center font-mono text-3xl font-black text-emerald-950">
                {{ play.tally }}
            </p>
            <p class="text-sm font-medium text-emerald-900">{{ message }}</p>
            <button
                v-if="canGoNext"
                type="button"
                class="rounded-md bg-amber-700 px-4 py-2 text-sm font-extrabold uppercase tracking-wide text-white hover:bg-amber-800"
                @click="emit('next')"
            >
                Next sum →
            </button>
            <p v-else class="text-sm font-semibold text-emerald-900">
                You finished all sums on this method card.
            </p>
        </div>
    </div>
</template>
