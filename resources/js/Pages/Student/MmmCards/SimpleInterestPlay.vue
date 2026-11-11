<script setup>
import SeriesFinished from '@/Pages/Student/MmmCards/SeriesFinished.vue';
import axios from 'axios';
import { computed, reactive, watch } from 'vue';

const props = defineProps({
    exercise: { type: Object, required: true },
    check_url: { type: String, required: true },
    canGoNext: { type: Boolean, default: false },
    nextPart: { type: Object, default: null },
    seriesLabel: { type: String, default: '' },
});

const emit = defineEmits(['next']);

const play = computed(() => props.exercise?.simple_interest || {});
const givenRows = computed(() => play.value.given_rows || []);
const calcSteps = computed(() => play.value.calc_steps || []);
const givenKeys = computed(() => givenRows.value.map((row) => `given.${row.key}`));
const stepKeys = computed(() => calcSteps.value.map((step) => `step.${step.key}`));

const answers = reactive({});
const status = reactive({});
const messages = reactive({});
const wrongCount = reactive({});
const helpOpen = reactive({});
const checking = reactive({});
const timers = {};

const reset = () => {
    const keys = [...givenKeys.value, 'to_find', 'formula', ...stepKeys.value];
    keys.forEach((key) => {
        answers[key] = '';
        status[key] = null;
        messages[key] = '';
        wrongCount[key] = 0;
        helpOpen[key] = false;
        checking[key] = false;
        if (timers[key]) {
            window.clearTimeout(timers[key]);
        }
    });
};

watch(() => props.exercise?.id, reset, { immediate: true });

const givenReady = computed(() => givenKeys.value.length > 0 && givenKeys.value.every((key) => status[key] === true));
const findReady = computed(() => givenReady.value && status.to_find === true);
const formulaReady = computed(() => findReady.value && status.formula === true);
const allReady = computed(() => formulaReady.value && stepKeys.value.every((key) => status[key] === true));

const cleanMath = (value) => String(value ?? '')
    .replace(/[−–—﹣－]/g, '-')
    .replace(/[＝﹦]/g, '=')
    .replace(/[＋﹢]/g, '+')
    .replace(/[×⋅]/g, '*')
    .replace(/\s+/g, ' ')
    .trim();

const cellClass = (key) => {
    if (status[key] === true) {
        return 'border-emerald-500 bg-emerald-50';
    }
    if (status[key] === false) {
        return 'border-rose-500 bg-rose-50';
    }

    return 'border-slate-300 bg-white';
};

const collected = () => {
    const out = {};
    Object.keys(answers).forEach((key) => {
        const text = cleanMath(answers[key]);
        if (text !== '') {
            out[key] = text;
        }
    });

    return out;
};

const checkStep = async (key) => {
    const value = cleanMath(answers[key]);
    if (value === '' || checking[key] || status[key] === true) {
        return;
    }
    if (key === 'to_find' && ! givenReady.value) {
        return;
    }
    if (key === 'formula' && ! findReady.value) {
        return;
    }
    if (key.startsWith('step.') && ! formulaReady.value) {
        return;
    }
    checking[key] = true;
    try {
        const { data } = await axios.post(props.check_url, {
            exercise_id: props.exercise.id,
            step_key: key,
            value,
            values: { ...collected(), [key]: value },
        }, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        });
        const ok = !! data.ok;
        status[key] = ok;
        messages[key] = data.message || (ok ? 'Right.' : 'Try again.');
        if (ok) {
            helpOpen[key] = false;
        } else {
            wrongCount[key] = (wrongCount[key] || 0) + 1;
            if (wrongCount[key] >= 2) {
                helpOpen[key] = true;
            }
        }
    } catch (e) {
        status[key] = false;
        messages[key] = e?.response?.data?.message || 'Could not check. Try again.';
    } finally {
        checking[key] = false;
    }
};

const scheduleCheck = (key) => {
    if (timers[key]) {
        window.clearTimeout(timers[key]);
    }
    if (status[key] === true) {
        return;
    }
    timers[key] = window.setTimeout(() => checkStep(key), 700);
};

const onInput = (key, event) => {
    answers[key] = event.target.value;
    if (status[key] !== true) {
        status[key] = null;
        messages[key] = '';
    }
    scheduleCheck(key);
};

const hintFor = (key) => (play.value.hints || {})[key] || '';
</script>

<template>
    <div class="mt-3 space-y-4">
        <article class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-emerald-900">
                Simple interest sum
            </p>
            <p class="mt-1 text-base font-medium leading-relaxed text-slate-900">
                {{ play.story }}
            </p>
        </article>

        <section class="overflow-x-auto rounded-xl border border-teal-200">
            <div class="border-b border-teal-200 bg-teal-50 px-3 py-2">
                <p class="text-[11px] font-extrabold uppercase tracking-wide text-teal-950">
                    1. Given — tabulate the sum
                </p>
                <p class="mt-0.5 text-sm text-teal-950">
                    {{ play.table_cue || 'Read the question. Fill each given value next to its letter.' }}
                </p>
            </div>
            <table class="min-w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-white">
                        <th class="border border-slate-200 px-3 py-2 text-left text-xs font-extrabold uppercase tracking-wide text-slate-700">
                            Given
                        </th>
                        <th class="border border-slate-200 px-3 py-2 text-left text-xs font-extrabold uppercase tracking-wide text-slate-700">
                            Notation
                        </th>
                        <th class="border border-slate-200 px-3 py-2 text-left text-xs font-extrabold uppercase tracking-wide text-slate-700">
                            Value
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in givenRows" :key="row.key" class="bg-white">
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left font-semibold text-slate-900">
                            {{ row.label }}
                        </th>
                        <td class="border border-slate-200 px-3 py-2 text-center font-mono text-base font-extrabold text-teal-900">
                            {{ row.notation }}
                        </td>
                        <td class="border border-slate-200 px-2 py-2">
                            <input
                                :value="answers[`given.${row.key}`]"
                                type="text"
                                inputmode="decimal"
                                class="w-28 rounded-md border px-2 py-1 text-center font-mono text-base font-extrabold focus:border-teal-500 focus:ring-teal-200 sm:w-36"
                                :class="cellClass(`given.${row.key}`)"
                                :placeholder="row.placeholder || ''"
                                autocomplete="off"
                                :aria-label="`${row.label} value`"
                                :disabled="status[`given.${row.key}`] === true"
                                @input="onInput(`given.${row.key}`, $event)"
                                @blur="checkStep(`given.${row.key}`)"
                                @keyup.enter="checkStep(`given.${row.key}`)"
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="space-y-1 px-3 py-2">
                <template v-for="row in givenRows" :key="`note-given-${row.key}`">
                    <p v-if="messages[`given.${row.key}`] && status[`given.${row.key}`] === false" class="text-sm text-rose-900">
                        {{ messages[`given.${row.key}`] }}
                    </p>
                    <button
                        v-if="status[`given.${row.key}`] !== true && status[`given.${row.key}`] !== null"
                        type="button"
                        class="text-sm font-medium text-teal-900 hover:underline"
                        @click="helpOpen[`given.${row.key}`] = true"
                    >
                        I cannot fill {{ row.notation }}
                    </button>
                    <p
                        v-if="helpOpen[`given.${row.key}`] && status[`given.${row.key}`] !== true && hintFor(`given.${row.key}`)"
                        class="rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-950"
                    >
                        {{ hintFor(`given.${row.key}`) }}
                    </p>
                </template>
            </div>
        </section>

        <article
            class="rounded-xl border px-4 py-3"
            :class="findReady
                ? 'border-emerald-300 bg-emerald-50/70'
                : givenReady
                    ? 'border-violet-200 bg-white'
                    : 'border-slate-200 bg-slate-50'"
        >
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-violet-900">
                2. To find
            </p>
            <template v-if="givenReady">
                <p class="mt-1 text-sm text-violet-950">
                    {{ play.to_find_cue || 'What is the question asking for? Write SI, Amount (A), R, or T.' }}
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-slate-800">To find</span>
                    <input
                        :value="answers.to_find"
                        type="text"
                        class="w-40 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold focus:border-violet-500 focus:ring-violet-200 sm:w-48"
                        :class="cellClass('to_find')"
                        autocomplete="off"
                        aria-label="What to find"
                        :disabled="status.to_find === true"
                        @input="onInput('to_find', $event)"
                        @blur="checkStep('to_find')"
                        @keyup.enter="checkStep('to_find')"
                    >
                </div>
                <p v-if="messages.to_find && status.to_find === false" class="mt-2 text-sm text-rose-900">
                    {{ messages.to_find }}
                </p>
                <button
                    v-if="status.to_find !== true"
                    type="button"
                    class="mt-2 text-sm font-medium text-violet-900 hover:underline"
                    @click="helpOpen.to_find = true"
                >
                    I cannot decide
                </button>
                <p
                    v-if="helpOpen.to_find && status.to_find !== true"
                    class="mt-2 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-sm text-violet-950"
                >
                    {{ play.to_find_hint || hintFor('to_find') }}
                </p>
            </template>
            <p v-else class="mt-1 text-sm text-slate-600">
                Fill every given value first. Then write what you must find.
            </p>
        </article>

        <article
            class="rounded-xl border px-4 py-3"
            :class="formulaReady
                ? 'border-emerald-300 bg-emerald-50/70'
                : findReady
                    ? 'border-amber-200 bg-white'
                    : 'border-slate-200 bg-slate-50'"
        >
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-amber-900">
                3. Formula
            </p>
            <template v-if="findReady">
                <p class="mt-1 text-sm text-amber-950">
                    {{ play.formula_cue || 'Write the formula you will use.' }}
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-slate-800">Formula</span>
                    <input
                        :value="answers.formula"
                        type="text"
                        class="w-full max-w-md rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold focus:border-amber-500 focus:ring-amber-200"
                        :class="cellClass('formula')"
                        placeholder="e.g. SI = PRT/100"
                        autocomplete="off"
                        aria-label="Formula"
                        :disabled="status.formula === true"
                        @input="onInput('formula', $event)"
                        @blur="checkStep('formula')"
                        @keyup.enter="checkStep('formula')"
                    >
                </div>
                <p v-if="messages.formula && status.formula === false" class="mt-2 text-sm text-rose-900">
                    {{ messages.formula }}
                </p>
                <button
                    v-if="status.formula !== true"
                    type="button"
                    class="mt-2 text-sm font-medium text-amber-900 hover:underline"
                    @click="helpOpen.formula = true"
                >
                    I cannot write the formula
                </button>
                <p
                    v-if="helpOpen.formula && status.formula !== true"
                    class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950"
                >
                    {{ play.formula_hint || hintFor('formula') }}
                </p>
            </template>
            <p v-else class="mt-1 text-sm text-slate-600">
                Decide what to find first. Then write the matching formula.
            </p>
        </article>

        <article
            class="rounded-xl border px-4 py-3"
            :class="allReady
                ? 'border-emerald-300 bg-emerald-50/70'
                : formulaReady
                    ? 'border-sky-200 bg-white'
                    : 'border-slate-200 bg-slate-50'"
        >
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-sky-900">
                4. Calculate
            </p>
            <template v-if="formulaReady">
                <p class="mt-1 text-sm text-sky-950">
                    {{ play.calc_cue || 'Substitute the values and calculate each step.' }}
                </p>
                <div
                    v-for="step in calcSteps"
                    :key="step.key"
                    class="mt-3"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="min-w-[8rem] text-sm font-semibold text-slate-800">
                            {{ step.label }}
                        </span>
                        <input
                            :value="answers[`step.${step.key}`]"
                            type="text"
                            inputmode="decimal"
                            class="w-28 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold focus:border-sky-500 focus:ring-sky-200 sm:w-36"
                            :class="cellClass(`step.${step.key}`)"
                            autocomplete="off"
                            :aria-label="step.label"
                            :disabled="status[`step.${step.key}`] === true"
                            @input="onInput(`step.${step.key}`, $event)"
                            @blur="checkStep(`step.${step.key}`)"
                            @keyup.enter="checkStep(`step.${step.key}`)"
                        >
                        <span v-if="step.unit" class="text-sm text-slate-600">{{ step.unit }}</span>
                    </div>
                    <p v-if="messages[`step.${step.key}`] && status[`step.${step.key}`] === false" class="mt-1 text-sm text-rose-900">
                        {{ messages[`step.${step.key}`] }}
                    </p>
                    <button
                        v-if="status[`step.${step.key}`] !== true && status[`step.${step.key}`] !== null"
                        type="button"
                        class="mt-1 text-sm font-medium text-sky-900 hover:underline"
                        @click="helpOpen[`step.${step.key}`] = true"
                    >
                        Show working hint
                    </button>
                    <p
                        v-if="helpOpen[`step.${step.key}`] && status[`step.${step.key}`] !== true"
                        class="mt-1 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-950"
                    >
                        {{ step.hint || hintFor(`step.${step.key}`) }}
                    </p>
                </div>
            </template>
            <p v-else class="mt-1 text-sm text-slate-600">
                Write the formula correctly. Then calculate each step.
            </p>
        </article>

        <div
            v-if="allReady"
            class="space-y-3 rounded-xl border-2 border-emerald-400 bg-emerald-50 px-4 py-4"
        >
            <p class="text-sm font-medium text-emerald-900">
                Notebook habit: Given → To find → Formula → Calculate. Keep writing sums this way.
            </p>
            <button
                v-if="canGoNext"
                type="button"
                class="rounded-md bg-teal-800 px-4 py-2 text-sm font-extrabold uppercase tracking-wide text-white hover:bg-teal-900"
                @click="emit('next')"
            >
                Next interest sum →
            </button>
            <SeriesFinished v-else :next-part="nextPart" :series-label="seriesLabel" />
        </div>
    </div>
</template>
