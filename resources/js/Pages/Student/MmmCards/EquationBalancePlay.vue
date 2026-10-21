<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    exercise: { type: Object, required: true },
    card: { type: Object, required: true },
    exercise_index: { type: Number, default: 0 },
    check_url: { type: String, required: true },
});

const emit = defineEmits(['next']);

const play = computed(() => props.exercise?.balance || {});
const steps = computed(() => play.value.steps || []);
const currentIndex = ref(0);
const currentValue = ref('');
const completed = ref({});
const submitting = ref(false);
const message = ref('');
const allCorrect = ref(false);
const stepOk = ref(false);

const currentStep = computed(() => steps.value[currentIndex.value] || null);
const currentKey = computed(() => currentStep.value?.key || '');

const cellClass = computed(() => {
    if (message.value === '' && ! stepOk.value) {
        return 'border-slate-300';
    }
    return stepOk.value
        ? 'border-emerald-500 bg-emerald-50'
        : (message.value && ! stepOk.value ? 'border-rose-500 bg-rose-50' : 'border-slate-300');
});

watch(
    () => props.exercise?.id,
    () => {
        currentIndex.value = 0;
        currentValue.value = '';
        completed.value = {};
        message.value = '';
        allCorrect.value = false;
        stepOk.value = false;
    },
);

const formatAdd = (raw) => {
    const text = String(raw ?? '').trim().replace(/[−–—]/g, '-');
    if (text === '') {
        return null;
    }
    if (text.startsWith('+') || text.startsWith('-')) {
        return text.startsWith('-') ? `−${text.slice(1)}` : text;
    }

    return `+ ${text}`;
};

const displayAdd = computed(() => formatAdd(currentValue.value));
const addedSigned = computed(() => formatAdd(completed.value.add || (currentStep.value?.type === 'balance' ? currentValue.value : '')));
const xValue = computed(() => completed.value.impact || completed.value.x || '');

const showFrozenBalance = computed(() => !! completed.value.add);

const frozenNumber = computed(() => {
    const from = currentStep.value?.frozen_from;
    if (! from) {
        return '';
    }

    return completed.value[from] ?? '';
});

const cleanMath = (value) => String(value ?? '')
    .replace(/[−–—﹣－]/g, '-')
    .replace(/[＋﹢]/g, '+')
    .replace(/\s+/g, '');

const checkStep = async () => {
    if (! currentKey.value || submitting.value) {
        return;
    }
    submitting.value = true;
    message.value = '';
    stepOk.value = false;
    try {
        const { data } = await axios.post(props.check_url, {
            exercise_id: props.exercise.id,
            step_key: currentKey.value,
            value: cleanMath(currentValue.value),
        }, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        });
        const ok = !! data.ok;
        stepOk.value = ok;
        message.value = data.message || '';
        if (ok) {
            completed.value = {
                ...completed.value,
                [currentKey.value]: cleanMath(currentValue.value),
            };
            if (data.all_ok) {
                allCorrect.value = true;
            } else {
                window.setTimeout(() => {
                    currentIndex.value += 1;
                    currentValue.value = '';
                    stepOk.value = false;
                    message.value = '';
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

const reset = () => {
    currentIndex.value = 0;
    currentValue.value = '';
    completed.value = {};
    message.value = '';
    allCorrect.value = false;
    stepOk.value = false;
};

const canGoNext = computed(() => {
    const total = Number(props.card?.exercises_total ?? 0);
    const index = Number(props.exercise_index ?? 0);

    return total > 0 && index + 1 < total;
});

const onEnter = (event) => {
    event.preventDefault();
    checkStep();
};
</script>

<template>
    <div class="mt-3 space-y-4">
        <p
            v-if="exercise.variable_tip"
            class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm leading-relaxed text-amber-950"
        >
            {{ exercise.variable_tip }}
        </p>
        <div class="rounded-2xl bg-gradient-to-b from-rose-50 to-white px-4 py-5 ring-1 ring-rose-100">
            <p class="text-center text-[10px] font-extrabold uppercase tracking-[0.18em] text-rose-800">
                Keep the scale level
            </p>
            <div class="mt-3 flex items-end justify-center gap-3 sm:gap-8">
                <div class="min-w-[8rem] flex-1 rounded-xl border-2 border-rose-200 bg-white px-3 py-4 text-center shadow-sm">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Left</p>
                    <p class="mt-1 font-mono text-xl font-extrabold text-slate-900 sm:text-2xl">
                        {{ play.given_left }}
                    </p>
                </div>
                <div class="mb-4 flex flex-col items-center">
                    <span class="font-mono text-2xl font-black text-rose-700">=</span>
                    <div class="mt-2 h-1.5 w-16 rounded-full bg-rose-300" />
                    <div class="h-8 w-0 border-l-4 border-rose-400" />
                    <div class="h-0 w-0 border-x-8 border-t-8 border-x-transparent border-t-rose-400" />
                </div>
                <div class="min-w-[8rem] flex-1 rounded-xl border-2 border-rose-200 bg-white px-3 py-4 text-center shadow-sm">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Right</p>
                    <p class="mt-1 font-mono text-xl font-extrabold text-slate-900 sm:text-2xl">
                        {{ play.given_right }}
                    </p>
                </div>
            </div>
            <p class="mt-3 text-center text-xs text-slate-600">
                Both pans already match. Whatever you add on the left, add the same on the right.
            </p>
        </div>

        <div class="flex items-center gap-2 text-xs font-semibold text-slate-500">
            <span
                v-for="(step, i) in steps"
                :key="step.key"
                class="rounded-full px-2.5 py-1"
                :class="i < currentIndex || allCorrect
                    ? 'bg-emerald-100 text-emerald-800'
                    : i === currentIndex
                        ? 'bg-rose-100 text-rose-900'
                        : 'bg-slate-100 text-slate-500'"
            >
                Step {{ i + 1 }}
            </span>
        </div>

        <p v-if="currentStep?.hint && !allCorrect" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">
            {{ currentStep.hint }}
        </p>

        <div class="space-y-3">
        <div v-if="!allCorrect && currentStep?.type === 'balance'" class="space-y-3">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-600">
                Add the same number on both sides
            </p>
            <div class="flex flex-wrap items-center justify-center gap-2 sm:gap-3">
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-lg font-semibold text-slate-900">
                    <span>{{ play.given_left }}</span>
                    <span class="text-rose-700">+</span>
                    <input
                        v-model="currentValue"
                        type="text"
                        inputmode="text"
                        class="w-16 rounded-md border px-1 py-1 text-center font-mono text-lg font-extrabold focus:border-rose-400 focus:ring-rose-200"
                        :class="cellClass"
                        placeholder="?"
                        autocomplete="off"
                        aria-label="Add on the left"
                        @keyup.enter="onEnter"
                    >
                </div>
                <span class="font-mono text-2xl font-black text-rose-700">=</span>
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-lg font-semibold text-slate-900">
                    <span>{{ play.given_right }}</span>
                    <span class="text-rose-700">+</span>
                    <input
                        :value="currentValue"
                        type="text"
                        inputmode="text"
                        class="w-16 rounded-md border px-1 py-1 text-center font-mono text-lg font-extrabold focus:border-rose-400 focus:ring-rose-200"
                        :class="cellClass"
                        placeholder="?"
                        autocomplete="off"
                        aria-label="Add on the right"
                        @input="currentValue = $event.target.value"
                        @keyup.enter="onEnter"
                    >
                </div>
            </div>
            <p class="text-center text-[11px] text-slate-500">
                One box fills the other — the scale stays level.
                <span v-if="displayAdd" class="font-mono font-semibold text-slate-700"> You are adding {{ displayAdd }}.</span>
            </p>
        </div>

        <div
            v-if="showFrozenBalance && currentStep?.type === 'impact'"
            class="flex flex-wrap items-center justify-center gap-2 font-mono text-base font-semibold text-slate-500"
        >
            <span>{{ play.given_left }}</span>
            <span class="text-rose-600">+</span>
            <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-800">({{ addedSigned }})</span>
            <span class="text-rose-700">=</span>
            <span>{{ play.given_right }}</span>
            <span class="text-rose-600">+</span>
            <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-800">({{ addedSigned }})</span>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'impact'" class="space-y-2">
            <p class="text-center text-xs font-bold uppercase tracking-wide text-slate-600">
                Impact — only fill the empty box
            </p>
            <div class="flex flex-wrap items-center justify-center gap-2 font-mono text-xl font-extrabold">
                <template v-if="currentStep.box_side === 'left'">
                    <input
                        v-model="currentValue"
                        type="text"
                        inputmode="numeric"
                        class="w-20 rounded-md border px-2 py-1 text-center font-mono text-xl font-black"
                        :class="cellClass"
                        placeholder="?"
                        autocomplete="off"
                        aria-label="Number on the left"
                        @keyup.enter="onEnter"
                    >
                    <span class="text-rose-700">=</span>
                    <span class="rounded-lg bg-slate-100 px-3 py-1 text-slate-800">{{ currentStep.x_expr }}</span>
                </template>
                <template v-else>
                    <span class="rounded-lg bg-slate-100 px-3 py-1 text-slate-800">{{ currentStep.x_expr }}</span>
                    <span class="text-rose-700">=</span>
                    <input
                        v-model="currentValue"
                        type="text"
                        inputmode="numeric"
                        class="w-20 rounded-md border px-2 py-1 text-center font-mono text-xl font-black"
                        :class="cellClass"
                        placeholder="?"
                        autocomplete="off"
                        aria-label="Number on the right"
                        @keyup.enter="onEnter"
                    >
                </template>
            </div>
        </div>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'simplify_right'" class="space-y-2">
            <div class="flex flex-wrap items-center justify-center gap-2 font-mono text-lg font-semibold">
                <span>{{ play.given_left }}</span>
                <span class="text-rose-700">=</span>
                <span class="text-slate-400 line-through">{{ play.given_right }}</span>
                <input
                    v-model="currentValue"
                    type="text"
                    inputmode="numeric"
                    class="w-20 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold"
                    :class="cellClass"
                    placeholder="?"
                    autocomplete="off"
                    aria-label="Value of the right side"
                    @keyup.enter="onEnter"
                >
            </div>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'simplify_left'" class="space-y-2">
            <div class="flex flex-wrap items-center justify-center gap-2 font-mono text-lg font-semibold">
                <span class="text-slate-400 line-through">{{ play.given_left }}</span>
                <input
                    v-model="currentValue"
                    type="text"
                    inputmode="numeric"
                    class="w-20 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold"
                    :class="cellClass"
                    placeholder="?"
                    autocomplete="off"
                    aria-label="Value of the left side"
                    @keyup.enter="onEnter"
                >
                <span class="text-rose-700">=</span>
                <span>{{ play.given_right }}</span>
            </div>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'move'" class="space-y-2">
            <div class="flex flex-wrap items-center justify-center gap-2 font-mono text-lg font-semibold">
                <span>x</span>
                <span class="text-rose-700">=</span>
                <span class="rounded-md bg-emerald-50 px-2 py-1 text-emerald-900">{{ frozenNumber || '…' }}</span>
                <span>{{ currentStep.op || '+' }}</span>
                <input
                    v-model="currentValue"
                    type="text"
                    inputmode="text"
                    class="w-20 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold"
                    :class="cellClass"
                    placeholder="?"
                    autocomplete="off"
                    aria-label="Number to add to free x"
                    @keyup.enter="onEnter"
                >
            </div>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'solve'" class="space-y-2">
            <div class="flex flex-wrap items-center justify-center gap-2 font-mono text-2xl font-extrabold">
                <span>x</span>
                <span class="text-rose-700">=</span>
                <input
                    v-model="currentValue"
                    type="text"
                    inputmode="numeric"
                    class="w-24 rounded-md border px-2 py-1 text-center font-mono text-2xl font-black"
                    :class="cellClass"
                    placeholder="?"
                    autocomplete="off"
                    aria-label="Value of x"
                    @keyup.enter="onEnter"
                >
            </div>
        </div>

        <div v-if="!allCorrect" class="flex flex-wrap gap-2">
            <PrimaryButton type="button" :disabled="submitting || currentValue.trim() === ''" @click="checkStep">
                {{ submitting ? 'Checking…' : 'Check this step' }}
            </PrimaryButton>
            <SecondaryButton type="button" @click="reset">Start again</SecondaryButton>
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
            <p class="font-mono text-2xl font-black text-emerald-950">
                x = {{ xValue }}
            </p>
            <p v-if="play.tally" class="text-sm font-medium text-emerald-900">
                {{ play.tally }}
            </p>
            <p v-else class="text-sm font-medium text-emerald-900">{{ message }}</p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-if="canGoNext"
                    type="button"
                    class="rounded-md bg-rose-700 px-4 py-2 text-sm font-extrabold uppercase tracking-wide text-white hover:bg-rose-800"
                    @click="emit('next')"
                >
                    Next sum →
                </button>
                <p v-else class="text-sm font-semibold text-emerald-900">
                    You finished all sums on this method card.
                </p>
            </div>
        </div>
    </div>
</template>
