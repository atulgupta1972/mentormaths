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

const play = computed(() => props.exercise?.age_table || {});
const rows = computed(() => play.value.rows || []);
const columns = computed(() => play.value.columns || []);
const cellKeys = computed(() => rows.value.flatMap((row) => columns.value.map((column) => `${row.key}.${column.key}`)));
const hasEquation = computed(() => Boolean(play.value.condition));

const answers = reactive({});
const status = reactive({});
const messages = reactive({});
const wrongCount = reactive({});
const helpOpen = reactive({});
const checking = reactive({});
const timers = {};

const reset = () => {
    const keys = [...cellKeys.value];
    if (hasEquation.value) {
        keys.push('equation');
    }
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

const tableReady = computed(() => cellKeys.value.length > 0 && cellKeys.value.every((key) => status[key] === true));
const allReady = computed(() => tableReady.value && (! hasEquation.value || status.equation === true));

const cleanMath = (value) => String(value ?? '')
    .replace(/[−–—﹣－]/g, '-')
    .replace(/[＝﹦]/g, '=')
    .replace(/[＋﹢]/g, '+')
    .replace(/(\d)[×⋅](\d)/g, '$1*$2')
    .replace(/(\d)[×⋅]/g, '$1x')
    .replace(/[×⋅](?=[+=\-)]|$)/g, 'x')
    .replace(/[×⋅]/g, '*')
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
    if (key === 'equation' && ! tableReady.value) {
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
        messages[key] = data.message || (ok ? 'Right.' : 'Look at the age words and try again.');
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
        <article class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3">
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-[11px] font-extrabold uppercase tracking-wide text-sky-800">
                    Age story
                </p>
                <span
                    v-for="phrase in play.phrases || []"
                    :key="phrase"
                    class="rounded-full bg-sky-800 px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-white"
                >
                    {{ phrase }}
                </span>
            </div>
            <p class="mt-1 text-base font-medium leading-relaxed text-slate-900">
                {{ play.story }}
            </p>
        </article>

        <div class="overflow-x-auto rounded-xl border border-amber-200">
            <table class="min-w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-amber-50">
                        <th class="border border-amber-200 px-3 py-2 text-left text-xs font-extrabold uppercase tracking-wide text-amber-950">
                            {{ play.name_heading || 'Name' }}
                        </th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            class="border border-amber-200 px-3 py-2 text-left text-xs font-extrabold uppercase tracking-wide text-amber-950"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key" class="bg-white">
                        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left font-semibold text-slate-900">
                            {{ row.name }}
                        </th>
                        <td
                            v-for="column in columns"
                            :key="`${row.key}.${column.key}`"
                            class="border border-slate-200 px-2 py-2"
                        >
                            <input
                                :value="answers[`${row.key}.${column.key}`]"
                                type="text"
                                inputmode="text"
                                class="w-28 rounded-md border px-2 py-1 text-center font-mono text-base font-extrabold focus:border-amber-500 focus:ring-amber-200 sm:w-32"
                                :class="cellClass(`${row.key}.${column.key}`)"
                                autocomplete="off"
                                :aria-label="`${row.name}, ${column.label}`"
                                :disabled="status[`${row.key}.${column.key}`] === true"
                                @input="onInput(`${row.key}.${column.key}`, $event)"
                                @blur="checkStep(`${row.key}.${column.key}`)"
                                @keyup.enter="checkStep(`${row.key}.${column.key}`)"
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-sm text-amber-950">
            {{ play.table_cue }}
        </p>

        <div
            v-for="key in cellKeys"
            :key="`note-${key}`"
        >
            <p v-if="messages[key] && status[key] === false" class="text-sm text-rose-900">
                {{ messages[key] }}
            </p>
            <button
                v-if="status[key] !== true && status[key] !== null"
                type="button"
                class="text-sm font-medium text-amber-900 hover:underline"
                @click="helpOpen[key] = true"
            >
                I cannot write this cell
            </button>
            <p
                v-if="helpOpen[key] && status[key] !== true && hintFor(key)"
                class="mt-1 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950"
            >
                {{ hintFor(key) }}
            </p>
        </div>

        <article
            v-if="hasEquation"
            class="rounded-xl border px-4 py-3"
            :class="allReady
                ? 'border-emerald-300 bg-emerald-50/70'
                : tableReady
                    ? 'border-violet-200 bg-white'
                    : 'border-slate-200 bg-slate-50'"
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
                        aria-label="Equation from the age table"
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
                    {{ play.equation_hint }}
                </p>
            </template>
            <p v-else class="mt-1 text-sm text-slate-600">
                The equation line opens once every age in the table is right.
            </p>
        </article>

        <div
            v-if="allReady"
            class="space-y-3 rounded-xl border-2 border-emerald-400 bg-emerald-50 px-4 py-4"
        >
            <p class="text-sm font-medium text-emerald-900">
                The age table gives the equation. After means add the years. Ago means subtract the years.
            </p>
            <button
                v-if="canGoNext"
                type="button"
                class="rounded-md bg-amber-800 px-4 py-2 text-sm font-extrabold uppercase tracking-wide text-white hover:bg-amber-900"
                @click="emit('next')"
            >
                Next age sum →
            </button>
            <SeriesFinished v-else :next-part="nextPart" :series-label="seriesLabel" />
        </div>
    </div>
</template>
