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

const play = computed(() => props.exercise?.ratio_equation || {});
const rows = computed(() => play.value.rows || []);
const shareKeys = computed(() => rows.value.map((row) => row.key));
const valueKeys = computed(() => rows.value.map((row) => `${row.key}_value`));

const answers = reactive({});
const status = reactive({});
const messages = reactive({});
const wrongCount = reactive({});
const helpOpen = reactive({});
const checking = reactive({});
const timers = {};

const allKeys = () => [...shareKeys.value, 'equation', 'factor', ...valueKeys.value];

const reset = () => {
    allKeys().forEach((key) => {
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

const tableReady = computed(() => shareKeys.value.length > 0 && shareKeys.value.every((key) => status[key] === true));
const equationReady = computed(() => tableReady.value && status.equation === true);
const factorReady = computed(() => equationReady.value && status.factor === true);
const allReady = computed(() => factorReady.value && valueKeys.value.every((key) => status[key] === true));

const cleanMath = (value) => String(value ?? '')
    .replace(/[−–—﹣－]/g, '-')
    .replace(/[＝﹦]/g, '=')
    .replace(/[＋﹢]/g, '+')
    .replace(/(\d)[×⋅·](\d)/g, '$1*$2')
    .replace(/(\d)[×⋅·]/g, '$1x')
    .replace(/[×⋅·](?=[+=\-)]|$)/g, 'x')
    .replace(/[×⋅·]/g, '*')
    .replace(/\s+/g, '');

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
    if ((key === 'equation' && ! tableReady.value) || (key === 'factor' && ! equationReady.value) || (valueKeys.value.includes(key) && ! factorReady.value)) {
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
        messages[key] = data.message || (ok ? 'Right.' : 'Look at the ratio and try again.');
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
        <article class="rounded-xl border border-teal-200 bg-teal-50 px-4 py-3">
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-teal-800">
                Ratio given
            </p>
            <p class="mt-1 text-base font-medium leading-relaxed text-slate-900">
                {{ play.story }}
            </p>
            <p class="mt-2 font-mono text-lg font-extrabold text-teal-950">
                {{ play.ratio_label }} = {{ play.ratio_text }}
            </p>
        </article>

        <p class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-950">
            {{ play.factor_line }}
        </p>

        <div class="overflow-x-auto rounded-xl border border-teal-200">
            <table class="min-w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-teal-50">
                        <th class="border border-teal-200 px-3 py-2 text-left text-xs font-extrabold uppercase tracking-wide text-teal-950">
                            Quantity
                        </th>
                        <th class="border border-teal-200 px-3 py-2 text-left text-xs font-extrabold uppercase tracking-wide text-teal-950">
                            Ratio part
                        </th>
                        <th class="border border-teal-200 px-3 py-2 text-left text-xs font-extrabold uppercase tracking-wide text-teal-950">
                            Amount
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key" class="bg-white">
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left font-semibold text-slate-900">
                            {{ row.name }}
                        </th>
                        <td class="border border-slate-200 px-3 py-2 text-center font-mono text-base font-extrabold text-slate-800">
                            {{ row.part }}
                        </td>
                        <td class="border border-slate-200 px-2 py-2">
                            <input
                                :value="answers[row.key]"
                                type="text"
                                inputmode="text"
                                class="w-28 rounded-md border px-2 py-1 text-center font-mono text-base font-extrabold focus:border-teal-500 focus:ring-teal-200 sm:w-32"
                                :class="cellClass(row.key)"
                                autocomplete="off"
                                :aria-label="`${row.name} as a multiple of x`"
                                :disabled="status[row.key] === true"
                                @input="onInput(row.key, $event)"
                                @blur="checkStep(row.key)"
                                @keyup.enter="checkStep(row.key)"
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-sm text-teal-950">
            {{ play.table_cue }}
        </p>

        <div v-for="key in shareKeys" :key="`note-${key}`">
            <p v-if="messages[key] && status[key] === false" class="text-sm text-rose-900">
                {{ messages[key] }}
            </p>
            <button
                v-if="status[key] !== true && status[key] !== null"
                type="button"
                class="text-sm font-medium text-teal-900 hover:underline"
                @click="helpOpen[key] = true"
            >
                I cannot write this cell
            </button>
            <p
                v-if="helpOpen[key] && status[key] !== true && hintFor(key)"
                class="mt-1 rounded-lg border border-teal-300 bg-teal-50 px-3 py-2 text-sm text-teal-950"
            >
                {{ hintFor(key) }}
            </p>
        </div>

        <article
            class="rounded-xl border px-4 py-3"
            :class="equationReady ? 'border-emerald-300 bg-emerald-50/70' : tableReady ? 'border-violet-200 bg-white' : 'border-slate-200 bg-slate-50'"
        >
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-violet-900">
                Condition → equation
            </p>
            <template v-if="tableReady">
                <p class="mt-1 text-base font-medium leading-relaxed text-slate-900">
                    {{ play.condition }}
                </p>
                <p class="mt-1 text-sm text-violet-950">
                    {{ play.equation_cue }}
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-slate-800">Equation</span>
                    <input
                        :value="answers.equation"
                        type="text"
                        inputmode="text"
                        class="w-full max-w-md rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold focus:border-violet-500 focus:ring-violet-200"
                        :class="cellClass('equation')"
                        autocomplete="off"
                        aria-label="Equation from the ratio table"
                        :disabled="status.equation === true"
                        @input="onInput('equation', $event)"
                        @blur="checkStep('equation')"
                        @keyup.enter="checkStep('equation')"
                    >
                    <span v-if="status.equation === true" class="text-sm font-bold text-emerald-800">Right</span>
                    <span v-else-if="status.equation === false" class="text-sm font-bold text-rose-800">Look again</span>
                </div>
                <p v-if="messages.equation && status.equation === false" class="mt-2 text-sm text-rose-900">
                    {{ messages.equation }}
                </p>
                <button
                    v-if="status.equation !== true"
                    type="button"
                    class="mt-2 text-sm font-medium text-violet-900 hover:underline"
                    @click="helpOpen.equation = true"
                >
                    I cannot write it
                </button>
                <p
                    v-if="helpOpen.equation && status.equation !== true"
                    class="mt-2 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-sm text-violet-950"
                >
                    {{ play.hints?.equation }}
                </p>
            </template>
            <p v-else class="mt-1 text-sm text-slate-600">
                The equation opens once both amounts are written with x.
            </p>
        </article>

        <article
            class="rounded-xl border px-4 py-3"
            :class="factorReady ? 'border-emerald-300 bg-emerald-50/70' : equationReady ? 'border-sky-200 bg-white' : 'border-slate-200 bg-slate-50'"
        >
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-sky-900">
                Solve for the common factor
            </p>
            <template v-if="equationReady">
                <p class="mt-1 text-sm text-sky-950">
                    {{ play.solve_cue }}
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="font-mono text-lg font-extrabold text-slate-800">x =</span>
                    <input
                        :value="answers.factor"
                        type="text"
                        inputmode="text"
                        class="w-28 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold focus:border-sky-500 focus:ring-sky-200"
                        :class="cellClass('factor')"
                        autocomplete="off"
                        aria-label="Common factor x"
                        :disabled="status.factor === true"
                        @input="onInput('factor', $event)"
                        @blur="checkStep('factor')"
                        @keyup.enter="checkStep('factor')"
                    >
                    <span v-if="status.factor === true" class="text-sm font-bold text-emerald-800">Right</span>
                    <span v-else-if="status.factor === false" class="text-sm font-bold text-rose-800">Look again</span>
                </div>
                <p v-if="messages.factor && status.factor === false" class="mt-2 text-sm text-rose-900">
                    {{ messages.factor }}
                </p>
                <button
                    v-if="status.factor !== true"
                    type="button"
                    class="mt-2 text-sm font-medium text-sky-900 hover:underline"
                    @click="helpOpen.factor = true"
                >
                    I cannot solve it
                </button>
                <p
                    v-if="helpOpen.factor && status.factor !== true"
                    class="mt-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-950"
                >
                    {{ play.hints?.factor }}
                </p>
            </template>
            <p v-else class="mt-1 text-sm text-slate-600">
                Solving opens once the equation is right. x is only the factor.
            </p>
        </article>

        <article
            class="rounded-xl border px-4 py-3"
            :class="allReady ? 'border-emerald-300 bg-emerald-50/70' : factorReady ? 'border-teal-200 bg-white' : 'border-slate-200 bg-slate-50'"
        >
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-teal-900">
                Quantities
            </p>
            <template v-if="factorReady">
                <p class="mt-1 text-sm text-teal-950">
                    {{ play.value_cue }}
                </p>
                <div class="mt-3 overflow-x-auto rounded-xl border border-teal-200">
                    <table class="min-w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-teal-50">
                                <th class="border border-teal-200 px-3 py-2 text-left text-xs font-extrabold uppercase tracking-wide text-teal-950">
                                    Quantity
                                </th>
                                <th class="border border-teal-200 px-3 py-2 text-left text-xs font-extrabold uppercase tracking-wide text-teal-950">
                                    Value
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="`${row.key}-value`" class="bg-white">
                                <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left font-semibold text-slate-900">
                                    {{ row.name }}
                                </th>
                                <td class="border border-slate-200 px-2 py-2">
                                    <input
                                        :value="answers[`${row.key}_value`]"
                                        type="text"
                                        inputmode="text"
                                        class="w-32 rounded-md border px-2 py-1 text-center font-mono text-base font-extrabold focus:border-teal-500 focus:ring-teal-200 sm:w-40"
                                        :class="cellClass(`${row.key}_value`)"
                                        autocomplete="off"
                                        :aria-label="`${row.name} value`"
                                        :disabled="status[`${row.key}_value`] === true"
                                        @input="onInput(`${row.key}_value`, $event)"
                                        @blur="checkStep(`${row.key}_value`)"
                                        @keyup.enter="checkStep(`${row.key}_value`)"
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-for="row in rows" :key="`value-note-${row.key}`" class="mt-2">
                    <p v-if="messages[`${row.key}_value`] && status[`${row.key}_value`] === false" class="text-sm text-rose-900">
                        {{ messages[`${row.key}_value`] }}
                    </p>
                    <button
                        v-if="status[`${row.key}_value`] !== true && status[`${row.key}_value`] !== null"
                        type="button"
                        class="text-sm font-medium text-teal-900 hover:underline"
                        @click="helpOpen[`${row.key}_value`] = true"
                    >
                        I cannot write this value
                    </button>
                    <p
                        v-if="helpOpen[`${row.key}_value`] && status[`${row.key}_value`] !== true && hintFor(`${row.key}_value`)"
                        class="mt-1 rounded-lg border border-teal-300 bg-teal-50 px-3 py-2 text-sm text-teal-950"
                    >
                        {{ hintFor(`${row.key}_value`) }}
                    </p>
                </div>
            </template>
            <p v-else class="mt-1 text-sm text-slate-600">
                The value table opens after x is found. Each quantity is its ratio part times x.
            </p>
        </article>

        <div
            v-if="allReady"
            class="space-y-3 rounded-xl border-2 border-emerald-400 bg-emerald-50 px-4 py-4"
        >
            <p class="text-sm font-medium text-emerald-900">
                x was only the common factor. The quantities are the ratio parts times that factor.
            </p>
            <button
                v-if="canGoNext"
                type="button"
                class="rounded-md bg-teal-800 px-4 py-2 text-sm font-extrabold uppercase tracking-wide text-white hover:bg-teal-900"
                @click="emit('next')"
            >
                Next ratio sum →
            </button>
            <SeriesFinished v-else :next-part="nextPart" :series-label="seriesLabel" />
        </div>
    </div>
</template>
