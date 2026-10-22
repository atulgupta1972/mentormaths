<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import EquationFraction from '@/Pages/Student/MmmCards/EquationFraction.vue';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

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
const crossRight = ref('');
const frac = reactive({ n1: '', n2: '', d1: '', d2: '' });
const flip = reactive({ n: '', d: '' });
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
        crossRight.value = '';
        frac.n1 = '';
        frac.n2 = '';
        frac.d1 = '';
        frac.d2 = '';
        flip.n = '';
        flip.d = '';
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
const xValue = computed(() => completed.value.x || completed.value.impact || '');
const scaleTip = computed(() => play.value.scale_tip
    || 'Both pans already match. Whatever you add on the left, add the same on the right.');
const isTermFlow = computed(() => steps.value.some((step) => [
    'divide', 'multiply', 'cross', 'write_frac', 'keep_flip', 'cut', 'isolate_term', 'term_value', 'open_bracket', 'rhs_value', 'take_number',
].includes(step.type)));
const canCheckStep = computed(() => {
    if (currentStep.value?.type === 'write_frac') {
        return frac.n1.trim() !== '' && frac.n2.trim() !== '' && frac.d1.trim() !== '' && frac.d2.trim() !== '';
    }
    if (currentStep.value?.type === 'keep_flip') {
        return flip.n.trim() !== '' && flip.d.trim() !== '';
    }
    if (currentValue.value.trim() === '') {
        return false;
    }
    if ((currentStep.value?.type === 'cross' || currentStep.value?.type === 'cut') && crossRight.value.trim() === '') {
        return false;
    }

    return true;
});
const divideRight = computed(() => {
    const step = currentStep.value;
    if (! step) {
        return '';
    }
    if (step.right) {
        return step.right;
    }
    if (step.right_from) {
        return completed.value[step.right_from] ?? '';
    }

    return play.value.given_right || '';
});
const workingLines = computed(() => {
    const lines = [];
    for (const step of steps.value) {
        const v = completed.value[step.key];
        if (v === undefined || v === null || v === '') {
            continue;
        }
        if (step.type === 'multiply') {
            continue;
        }
        if (step.type === 'divide') {
            const right = step.right || completed.value[step.right_from] || play.value.given_right;
            lines.push(`${step.left || play.value.given_left} ÷ ${v} = ${right} ÷ ${v}`);
        } else if (step.type === 'write_frac' || step.type === 'cut') {
            continue;
        } else if (step.type === 'cross') {
            const other = completed.value[step.pair_key];
            if (other !== undefined && other !== null && other !== '') {
                lines.push(`${step.left_num} × ${v} = ${step.right_num} × ${other}`);
            }
        } else if (step.type === 'isolate_term' || step.type === 'take_number') {
            lines.push(`${step.keep} = ${step.frozen} ${step.op || '−'} ${v}`);
        } else if (step.type === 'term_value' || step.type === 'rhs_value') {
            lines.push(`${step.keep} = ${v}`);
        } else if (step.type === 'open_bracket') {
            lines.push(`(${step.expression}) = ${v}`);
        } else if (step.type === 'solve') {
            lines.push(`x = ${v}`);
        }
    }

    return lines;
});

const showFrozenBalance = computed(() => !! completed.value.add);

const frozenNumber = computed(() => {
    const from = currentStep.value?.frozen_from;
    if (! from) {
        return '';
    }

    return completed.value[from] ?? '';
});

const moveStep = computed(() => steps.value.find((step) => step.type === 'move') || null);
const tidyValue = computed(() => {
    const from = moveStep.value?.frozen_from;
    if (! from) {
        return frozenNumber.value;
    }

    return completed.value[from] ?? frozenNumber.value;
});
const moveValueSigned = computed(() => formatAdd(completed.value.move || ''));
const showTidyTrail = computed(() => {
    const type = currentStep.value?.type;
    if (! moveStep.value) {
        return false;
    }

    return type === 'move' || type === 'solve' || allCorrect.value;
});
const showTermSolve = computed(() => ! moveStep.value && currentStep.value?.type === 'solve' && ! allCorrect.value);

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
        const payload = {
            exercise_id: props.exercise.id,
            step_key: currentKey.value,
            value: cleanMath(currentValue.value),
        };
        if (currentStep.value?.type === 'write_frac') {
            payload.values = {
                n1: cleanMath(frac.n1),
                n2: cleanMath(frac.n2),
                d1: cleanMath(frac.d1),
                d2: cleanMath(frac.d2),
            };
        }
        if (currentStep.value?.type === 'keep_flip') {
            payload.values = {
                flip_n: cleanMath(flip.n),
                flip_d: cleanMath(flip.d),
            };
        }
        if ((currentStep.value?.type === 'cross' || currentStep.value?.type === 'cut') && currentStep.value.pair_key) {
            payload.values = {
                [currentKey.value]: cleanMath(currentValue.value),
                [currentStep.value.pair_key]: cleanMath(crossRight.value),
            };
        }
        const { data } = await axios.post(props.check_url, payload, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        });
        const ok = !! data.ok;
        stepOk.value = ok;
        message.value = data.message || '';
        if (ok) {
            const nextCompleted = {
                ...completed.value,
                [currentKey.value]: cleanMath(currentValue.value),
            };
            if (currentStep.value?.type === 'write_frac') {
                nextCompleted.n1 = cleanMath(frac.n1);
                nextCompleted.n2 = cleanMath(frac.n2);
                nextCompleted.d1 = cleanMath(frac.d1);
                nextCompleted.d2 = cleanMath(frac.d2);
            }
            if (currentStep.value?.type === 'keep_flip') {
                nextCompleted.flip_n = cleanMath(flip.n);
                nextCompleted.flip_d = cleanMath(flip.d);
            }
            if ((currentStep.value?.type === 'cross' || currentStep.value?.type === 'cut') && currentStep.value.pair_key) {
                nextCompleted[currentStep.value.pair_key] = cleanMath(crossRight.value);
            }
            completed.value = nextCompleted;
            if (data.all_ok) {
                allCorrect.value = true;
            } else {
                window.setTimeout(() => {
                    currentIndex.value += 1;
                    currentValue.value = '';
                    crossRight.value = '';
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
    crossRight.value = '';
    frac.n1 = '';
    frac.n2 = '';
    frac.d1 = '';
    frac.d2 = '';
    flip.n = '';
    flip.d = '';
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

const multiplyStep = computed(() => steps.value.find((step) => step.type === 'multiply') || null);
const multiplyTimes = computed(() => {
    if (! multiplyStep.value) {
        return '';
    }

    return completed.value[multiplyStep.value.key] || '';
});
const showMultiplyTrail = computed(() => !! multiplyTimes.value);
const showFracTrail = computed(() => !! (completed.value.n1 && completed.value.n2 && completed.value.d1 && completed.value.d2));
const showKeepFlipTrail = computed(() => !! (completed.value.flip_n && completed.value.flip_d));
const keepFlipStep = computed(() => steps.value.find((step) => step.type === 'keep_flip') || null);
const cutStep = computed(() => steps.value.find((step) => step.type === 'cut') || null);
const cutLeftValue = computed(() => (cutStep.value ? completed.value[cutStep.value.key] : '') || '');
const cutRightValue = computed(() => (cutStep.value?.pair_key ? completed.value[cutStep.value.pair_key] : '') || '');
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
            <div class="mx-auto mt-3 flex w-fit items-center gap-3 rounded-xl border-2 border-rose-200 bg-white px-4 py-3 shadow-sm">
                <div class="text-center">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Left</p>
                    <div class="mt-1 flex justify-center text-xl sm:text-2xl">
                        <EquationFraction :expr="play.given_left" />
                    </div>
                </div>
                <span class="font-mono text-2xl font-black text-rose-700">=</span>
                <div class="text-center">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Right</p>
                    <div class="mt-1 flex justify-center text-xl sm:text-2xl">
                        <EquationFraction :expr="play.given_right" />
                    </div>
                </div>
            </div>
            <p class="mt-3 text-center text-xs text-slate-600">
                {{ scaleTip }}
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

        <div v-if="isTermFlow && (workingLines.length || showMultiplyTrail || showFracTrail || showKeepFlipTrail)" class="space-y-2 rounded-xl border border-emerald-100 bg-emerald-50/70 px-3 py-2">
            <div
                v-if="showMultiplyTrail"
                class="flex flex-wrap items-center justify-center gap-3"
            >
                <EquationFraction
                    :expr="multiplyStep.left || play.given_left"
                    :times="multiplyTimes"
                />
                <span class="font-mono text-xl font-black text-rose-700">=</span>
                <EquationFraction
                    :expr="multiplyStep.right || play.given_right"
                    :times="multiplyTimes"
                />
            </div>
            <div
                v-if="showKeepFlipTrail"
                class="flex flex-wrap items-center justify-center gap-3 font-mono font-extrabold text-emerald-950"
            >
                <span>x</span>
                <span class="text-rose-700">=</span>
                <EquationFraction :expr="keepFlipStep?.keep || play.given_right" />
                <span class="text-rose-700">×</span>
                <EquationFraction :expr="`${completed.flip_n} / ${completed.flip_d}`" />
            </div>
            <div
                v-if="showFracTrail"
                class="flex flex-wrap items-center justify-center gap-3 font-mono font-extrabold text-emerald-950"
            >
                <span>x</span>
                <span class="text-rose-700">=</span>
                <span class="inline-flex flex-col items-center leading-none">
                    <span>
                        <span :class="cutLeftValue ? 'line-through decoration-slate-400' : ''">{{ completed.n1 }}</span>
                        ×
                        <span :class="cutRightValue ? 'line-through decoration-slate-400' : ''">{{ completed.n2 }}</span>
                    </span>
                    <span class="my-1 h-[2px] w-full min-w-[4.5rem] rounded-full bg-emerald-800" />
                    <span>
                        <span :class="cutLeftValue ? 'line-through decoration-slate-400' : ''">{{ completed.d1 }}</span>
                        ×
                        <span :class="cutRightValue ? 'line-through decoration-slate-400' : ''">{{ completed.d2 }}</span>
                    </span>
                </span>
                <template v-if="cutLeftValue && cutRightValue">
                    <span class="text-slate-400">→</span>
                    <span>{{ cutLeftValue }} × {{ cutRightValue }}</span>
                </template>
            </div>
            <p
                v-for="(line, i) in workingLines"
                :key="`work-${i}`"
                class="text-center font-mono text-sm font-semibold text-emerald-950"
            >
                {{ line }}
            </p>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'divide'" class="space-y-3">
            <p
                v-if="currentStep.rewrite_note"
                class="text-center text-sm font-medium text-slate-700"
            >
                {{ currentStep.rewrite_note }}
            </p>
            <div
                v-if="currentStep.rewrite_left"
                class="flex flex-wrap items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 font-mono text-lg font-extrabold text-emerald-950"
            >
                <span class="text-[10px] font-bold uppercase tracking-wide text-emerald-700">Now it is</span>
                <span>{{ currentStep.rewrite_left }}</span>
                <span class="text-rose-700">=</span>
                <span>{{ currentStep.rewrite_right }}</span>
            </div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-600">
                Divide both sides by the same number
            </p>
            <div class="flex flex-wrap items-center justify-center gap-2 sm:gap-3">
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-lg font-semibold text-slate-900">
                    <span>{{ currentStep.left || play.given_left }}</span>
                    <span class="text-rose-700">÷</span>
                    <input
                        v-model="currentValue"
                        type="text"
                        inputmode="numeric"
                        class="w-16 rounded-md border px-1 py-1 text-center font-mono text-lg font-extrabold focus:border-rose-400 focus:ring-rose-200"
                        :class="cellClass"
                        placeholder="?"
                        autocomplete="off"
                        aria-label="Divide on the left"
                        @keyup.enter="onEnter"
                    >
                </div>
                <span class="font-mono text-2xl font-black text-rose-700">=</span>
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-lg font-semibold text-slate-900">
                    <span>{{ divideRight }}</span>
                    <span class="text-rose-700">÷</span>
                    <input
                        :value="currentValue"
                        type="text"
                        inputmode="numeric"
                        class="w-16 rounded-md border px-1 py-1 text-center font-mono text-lg font-extrabold focus:border-rose-400 focus:ring-rose-200"
                        :class="cellClass"
                        placeholder="?"
                        autocomplete="off"
                        aria-label="Divide on the right"
                        @input="currentValue = $event.target.value"
                        @keyup.enter="onEnter"
                    >
                </div>
            </div>
            <p class="text-center text-[11px] text-slate-500">
                One box fills the other — the scale stays level.
            </p>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'multiply'" class="space-y-3">
            <p
                v-if="currentStep.warning"
                class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950"
            >
                {{ currentStep.warning }}
            </p>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-600">
                Put the same number on both numerators
            </p>
            <div class="flex flex-wrap items-center justify-center gap-4 sm:gap-6">
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <EquationFraction
                        :expr="currentStep.left || play.given_left"
                        show-times-slot
                    >
                        <input
                            v-model="currentValue"
                            type="text"
                            inputmode="numeric"
                            class="h-9 w-12 rounded-md border px-1 text-center font-mono text-lg font-extrabold focus:border-rose-400 focus:ring-rose-200"
                            :class="cellClass"
                            placeholder="?"
                            autocomplete="off"
                            aria-label="Multiply on the left numerator"
                            @keyup.enter="onEnter"
                        >
                    </EquationFraction>
                </div>
                <span class="font-mono text-2xl font-black text-rose-700">=</span>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <EquationFraction
                        :expr="currentStep.right || play.given_right"
                        show-times-slot
                    >
                        <input
                            :value="currentValue"
                            type="text"
                            inputmode="numeric"
                            class="h-9 w-12 rounded-md border px-1 text-center font-mono text-lg font-extrabold focus:border-rose-400 focus:ring-rose-200"
                            :class="cellClass"
                            placeholder="?"
                            autocomplete="off"
                            aria-label="Multiply on the right"
                            @input="currentValue = $event.target.value"
                            @keyup.enter="onEnter"
                        >
                    </EquationFraction>
                </div>
            </div>
            <p class="text-center text-[11px] text-slate-500">
                The extra number sits on the numerator. One box fills the other.
            </p>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'keep_flip'" class="space-y-3">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-600">
                Keep {{ currentStep.keep || play.given_right }}. Flip {{ currentStep.flip_from || 'the number in front of x' }}.
            </p>
            <div class="mx-auto flex w-fit items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 sm:gap-3 sm:px-4">
                <span class="font-mono text-2xl font-black text-slate-900">x</span>
                <span class="font-mono text-2xl font-black text-rose-700">=</span>
                <div class="text-center">
                    <p class="mb-1 text-[10px] font-bold uppercase tracking-wide text-emerald-800">Stays</p>
                    <EquationFraction :expr="currentStep.keep || play.given_right" />
                </div>
                <span class="font-mono text-2xl font-black text-rose-700">×</span>
                <div class="text-center">
                    <p class="mb-1 text-[10px] font-bold uppercase tracking-wide text-rose-800">
                        {{ currentStep.flip_from || '2 / 5' }} flips
                    </p>
                    <div class="inline-flex flex-col items-center font-mono font-extrabold leading-none text-slate-900">
                        <input
                            v-model="flip.n"
                            type="text"
                            inputmode="numeric"
                            class="h-8 w-10 rounded-md border px-1 text-center text-lg focus:border-rose-400 focus:ring-rose-200"
                            :class="cellClass"
                            placeholder="?"
                            autocomplete="off"
                            aria-label="Flipped numerator"
                            @keyup.enter="onEnter"
                        >
                        <span class="my-0.5 h-[2px] w-full min-w-[2.5rem] rounded-full bg-slate-800" />
                        <input
                            v-model="flip.d"
                            type="text"
                            inputmode="numeric"
                            class="h-8 w-10 rounded-md border px-1 text-center text-lg focus:border-rose-400 focus:ring-rose-200"
                            :class="cellClass"
                            placeholder="?"
                            autocomplete="off"
                            aria-label="Flipped denominator"
                            @keyup.enter="onEnter"
                        >
                    </div>
                </div>
            </div>
            <p class="text-center text-[11px] text-slate-500">
                {{ currentStep.keep || play.given_right }} does not move. {{ currentStep.flip_from || '2 / 5' }} crosses as its flip.
            </p>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'write_frac'" class="space-y-3">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-600">
                Write x as a fraction — do not multiply yet
            </p>
            <div class="flex flex-wrap items-center justify-center gap-4">
                <span class="font-mono text-2xl font-black text-slate-900">x</span>
                <span class="font-mono text-2xl font-black text-rose-700">=</span>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="inline-flex flex-col items-center font-mono font-extrabold leading-none text-slate-900">
                        <div class="flex items-center gap-1">
                            <input
                                v-model="frac.n1"
                                type="text"
                                inputmode="numeric"
                                class="h-9 w-12 rounded-md border px-1 text-center text-lg focus:border-rose-400 focus:ring-rose-200"
                                :class="cellClass"
                                placeholder="?"
                                autocomplete="off"
                                aria-label="First number on top"
                                @keyup.enter="onEnter"
                            >
                            <span class="text-rose-700">×</span>
                            <input
                                v-model="frac.n2"
                                type="text"
                                inputmode="numeric"
                                class="h-9 w-12 rounded-md border px-1 text-center text-lg focus:border-rose-400 focus:ring-rose-200"
                                :class="cellClass"
                                placeholder="?"
                                autocomplete="off"
                                aria-label="Second number on top"
                                @keyup.enter="onEnter"
                            >
                        </div>
                        <span class="my-1 h-[2px] w-full min-w-[6.5rem] rounded-full bg-slate-800" />
                        <div class="flex items-center gap-1">
                            <input
                                v-model="frac.d1"
                                type="text"
                                inputmode="numeric"
                                class="h-9 w-12 rounded-md border px-1 text-center text-lg focus:border-rose-400 focus:ring-rose-200"
                                :class="cellClass"
                                placeholder="?"
                                autocomplete="off"
                                aria-label="First number on the bottom"
                                @keyup.enter="onEnter"
                            >
                            <span class="text-rose-700">×</span>
                            <input
                                v-model="frac.d2"
                                type="text"
                                inputmode="numeric"
                                class="h-9 w-12 rounded-md border px-1 text-center text-lg focus:border-rose-400 focus:ring-rose-200"
                                :class="cellClass"
                                placeholder="?"
                                autocomplete="off"
                                aria-label="Second number on the bottom"
                                @keyup.enter="onEnter"
                            >
                        </div>
                    </div>
                </div>
            </div>
            <p class="text-center text-[11px] text-slate-500">
                Top: other numerator × this denominator. Bottom: number in front of x × other denominator.
            </p>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'cut'" class="space-y-3">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-600">
                Cut matching numbers
            </p>
            <p class="text-center text-sm text-slate-700">
                Cancel a top with a bottom. Write what each pair becomes.
            </p>
            <div class="flex flex-wrap items-center justify-center gap-6">
                <div class="flex items-center gap-2 font-mono text-lg font-extrabold">
                    <span>{{ currentStep.cut_left }}</span>
                    <span class="text-rose-700">=</span>
                    <input
                        v-model="currentValue"
                        type="text"
                        inputmode="numeric"
                        class="h-9 w-14 rounded-md border px-1 text-center font-mono text-lg font-extrabold focus:border-rose-400 focus:ring-rose-200"
                        :class="cellClass"
                        placeholder="?"
                        autocomplete="off"
                        :aria-label="currentStep.cut_left"
                        @keyup.enter="onEnter"
                    >
                </div>
                <div class="flex items-center gap-2 font-mono text-lg font-extrabold">
                    <span>{{ currentStep.cut_right }}</span>
                    <span class="text-rose-700">=</span>
                    <input
                        v-model="crossRight"
                        type="text"
                        inputmode="numeric"
                        class="h-9 w-14 rounded-md border px-1 text-center font-mono text-lg font-extrabold focus:border-rose-400 focus:ring-rose-200"
                        :class="cellClass"
                        placeholder="?"
                        autocomplete="off"
                        :aria-label="currentStep.cut_right"
                        @keyup.enter="onEnter"
                    >
                </div>
            </div>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'cross'" class="space-y-3">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-600">
                Cross-multiply
            </p>
            <p class="text-center text-sm text-slate-700">
                Each numerator times the other side’s denominator.
            </p>
            <div class="flex flex-wrap items-center justify-center gap-6">
                <EquationFraction :expr="play.given_left" />
                <span class="font-mono text-xl font-black text-rose-700">=</span>
                <EquationFraction :expr="play.given_right" />
            </div>
            <div class="flex flex-wrap items-center justify-center gap-2 sm:gap-3">
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-lg font-semibold text-slate-900">
                    <span>{{ currentStep.left_num }}</span>
                    <span class="text-rose-700">×</span>
                    <input
                        v-model="currentValue"
                        type="text"
                        inputmode="numeric"
                        class="w-16 rounded-md border px-1 py-1 text-center font-mono text-lg font-extrabold focus:border-rose-400 focus:ring-rose-200"
                        :class="cellClass"
                        placeholder="?"
                        autocomplete="off"
                        aria-label="Times the other denominator on the left"
                        @keyup.enter="onEnter"
                    >
                </div>
                <span class="font-mono text-2xl font-black text-rose-700">=</span>
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-lg font-semibold text-slate-900">
                    <span>{{ currentStep.right_num }}</span>
                    <span class="text-rose-700">×</span>
                    <input
                        v-model="crossRight"
                        type="text"
                        inputmode="numeric"
                        class="w-16 rounded-md border px-1 py-1 text-center font-mono text-lg font-extrabold focus:border-rose-400 focus:ring-rose-200"
                        :class="cellClass"
                        placeholder="?"
                        autocomplete="off"
                        aria-label="Times the other denominator on the right"
                        @keyup.enter="onEnter"
                    >
                </div>
            </div>
        </div>

        <div v-if="!allCorrect && (currentStep?.type === 'isolate_term' || currentStep?.type === 'take_number')" class="space-y-2">
            <p class="text-center text-xs font-bold uppercase tracking-wide text-slate-600">
                {{ currentStep.type === 'take_number' ? 'Take the number across' : 'Keep the x-term together' }}
            </p>
            <div class="flex flex-wrap items-center justify-center gap-2 font-mono text-lg font-semibold">
                <span>{{ currentStep.keep }}</span>
                <span class="text-rose-700">=</span>
                <span class="rounded-md bg-emerald-50 px-2 py-1 text-emerald-900">{{ currentStep.frozen }}</span>
                <span>{{ currentStep.op || '−' }}</span>
                <input
                    v-model="currentValue"
                    type="text"
                    inputmode="numeric"
                    class="w-20 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold"
                    :class="cellClass"
                    placeholder="?"
                    autocomplete="off"
                    :aria-label="currentStep.type === 'take_number' ? 'Number to take across' : 'Number to take off the x-term'"
                    @keyup.enter="onEnter"
                >
            </div>
        </div>

        <div v-if="!allCorrect && (currentStep?.type === 'term_value' || currentStep?.type === 'rhs_value')" class="space-y-2">
            <p class="text-center text-xs font-bold uppercase tracking-wide text-slate-600">
                {{ currentStep.type === 'rhs_value' ? 'Work out the right-hand side' : 'Work out that number' }}
            </p>
            <div
                v-if="currentStep.working"
                class="flex flex-wrap items-center justify-center gap-2 font-mono text-sm text-slate-500"
            >
                <span>{{ currentStep.keep }}</span>
                <span>=</span>
                <span class="line-through decoration-slate-300">{{ currentStep.struck || play.given_right }}</span>
                <span>{{ currentStep.working }}</span>
            </div>
            <div class="flex flex-wrap items-center justify-center gap-2 font-mono text-lg font-semibold">
                <span>{{ currentStep.keep }}</span>
                <span class="text-rose-700">=</span>
                <input
                    v-model="currentValue"
                    type="text"
                    inputmode="text"
                    class="w-20 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold"
                    :class="cellClass"
                    placeholder="?"
                    autocomplete="off"
                    aria-label="Value of this side"
                    @keyup.enter="onEnter"
                >
            </div>
        </div>

        <div v-if="!allCorrect && currentStep?.type === 'open_bracket'" class="space-y-2">
            <p class="text-center text-xs font-bold uppercase tracking-wide text-slate-600">
                Open the bracket first
            </p>
            <div class="flex flex-wrap items-center justify-center gap-2 font-mono text-lg font-semibold">
                <span>({{ currentStep.expression }})</span>
                <span class="text-rose-700">=</span>
                <input
                    v-model="currentValue"
                    type="text"
                    inputmode="numeric"
                    class="w-20 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold"
                    :class="cellClass"
                    placeholder="?"
                    autocomplete="off"
                    aria-label="Value inside the bracket"
                    @keyup.enter="onEnter"
                >
            </div>
        </div>

        <div v-if="showTermSolve" class="space-y-2">
            <p class="text-center text-xs font-bold uppercase tracking-wide text-slate-600">
                Now write x
            </p>
            <p
                v-if="currentStep.rewrite_note"
                class="text-center text-sm font-medium text-slate-700"
            >
                {{ currentStep.rewrite_note }}
            </p>
            <div class="flex flex-wrap items-center justify-center gap-2 font-mono text-2xl font-extrabold">
                <span>x</span>
                <span class="text-rose-700">=</span>
                <template v-if="cutLeftValue && cutRightValue">
                    <span>{{ cutLeftValue }} × {{ cutRightValue }}</span>
                    <span class="text-slate-400">=</span>
                </template>
                <input
                    v-model="currentValue"
                    type="text"
                    inputmode="text"
                    class="w-24 rounded-md border px-2 py-1 text-center font-mono text-2xl font-black"
                    :class="cellClass"
                    placeholder="?"
                    autocomplete="off"
                    aria-label="Value of x"
                    @keyup.enter="onEnter"
                >
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

        <div v-if="showTidyTrail" class="space-y-3">
            <div class="flex flex-wrap items-center justify-center gap-2 font-mono text-sm text-slate-500">
                <span class="line-through decoration-slate-300">{{ play.given_left }}</span>
                <span>=</span>
                <span class="line-through decoration-slate-300">{{ play.given_right }}</span>
            </div>
            <div
                v-if="moveStep?.rewrite_left"
                class="flex flex-wrap items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 font-mono text-lg font-extrabold text-emerald-950"
            >
                <span class="text-[10px] font-bold uppercase tracking-wide text-emerald-700">Now it is</span>
                <span>{{ moveStep.rewrite_left }}</span>
                <span class="text-rose-700">=</span>
                <span>{{ tidyValue || '…' }}</span>
            </div>
            <div
                v-if="!allCorrect && currentStep?.type === 'move'"
                class="space-y-2"
            >
                <p class="text-center text-xs font-bold uppercase tracking-wide text-slate-600">
                    Add the opposite so x is alone
                </p>
                <div class="flex flex-wrap items-center justify-center gap-2 font-mono text-lg font-semibold">
                    <span>x</span>
                    <span class="text-rose-700">=</span>
                    <span class="rounded-md bg-emerald-50 px-2 py-1 text-emerald-900">{{ tidyValue || '…' }}</span>
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
            <div
                v-else-if="completed.move"
                class="flex flex-wrap items-center justify-center gap-2 font-mono text-lg font-semibold text-slate-700"
            >
                <span>x</span>
                <span class="text-rose-700">=</span>
                <span class="rounded-md bg-emerald-50 px-2 py-1 text-emerald-900">{{ tidyValue }}</span>
                <span class="rounded-md bg-emerald-50 px-2 py-1 text-emerald-900">{{ moveValueSigned }}</span>
            </div>
            <div
                v-if="!allCorrect && currentStep?.type === 'solve'"
                class="space-y-2"
            >
                <p class="text-center text-xs font-bold uppercase tracking-wide text-slate-600">
                    Now write x
                </p>
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
        </div>

        <div v-if="!allCorrect" class="flex flex-wrap gap-2">
            <PrimaryButton type="button" :disabled="submitting || !canCheckStep" @click="checkStep">
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
