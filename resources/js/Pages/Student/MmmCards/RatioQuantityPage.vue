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

const play = computed(() => props.exercise?.ratio_quantities || {});
const items = computed(() => play.value.items || []);

const qtyKeys = (item) => (item.quantities || []).map((qty) => `${item.key}.${qty.key}`);

const answers = reactive({});
const status = reactive({});
const messages = reactive({});
const wrongCount = reactive({});
const helpOpen = reactive({});
const checking = reactive({});
const timers = {};

const allKeys = computed(() => items.value.flatMap((item) => qtyKeys(item)));

const reset = () => {
    allKeys.value.forEach((key) => {
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

const sumReady = (item) => qtyKeys(item).every((key) => status[key] === true);
const readyCount = computed(() => items.value.filter((item) => sumReady(item)).length);
const allReady = computed(() => items.value.length > 0 && readyCount.value === items.value.length);

const cleanMath = (value) => String(value ?? '')
    .replace(/[−–—﹣－]/g, '-')
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
        const ok = !! (data.cell_results?.[key] ?? data.ok);
        status[key] = ok;
        messages[key] = data.message || (ok ? 'Right.' : 'Make the units the same and try again.');
        if (ok) {
            helpOpen[key.split('.')[0]] = false;
        } else {
            const sumKey = key.split('.')[0];
            wrongCount[sumKey] = (wrongCount[sumKey] || 0) + 1;
            if (wrongCount[sumKey] >= 2) {
                helpOpen[sumKey] = true;
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
</script>

<template>
    <div class="mt-3 space-y-4">
        <p class="text-sm leading-relaxed text-slate-700">
            {{ play.page_blurb }}
        </p>

        <article
            v-for="(item, index) in items"
            :key="item.key"
            class="rounded-xl border px-3 py-3"
            :class="sumReady(item) ? 'border-emerald-300 bg-emerald-50/60' : 'border-slate-200 bg-white'"
        >
            <div class="flex flex-wrap items-start gap-3">
                <span class="mt-0.5 rounded bg-teal-800 px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-white">
                    {{ index + 1 }}
                </span>
                <div class="min-w-0 flex-1 space-y-3">
                    <p class="text-base font-medium leading-relaxed text-slate-900">
                        {{ item.story }}
                    </p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse text-sm">
                            <thead>
                                <tr class="bg-teal-50">
                                    <th
                                        v-for="column in item.columns"
                                        :key="`${item.key}-${column}`"
                                        class="border border-teal-300 px-3 py-2 text-left text-xs font-extrabold uppercase tracking-wide text-teal-950"
                                    >
                                        {{ column }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="qty in item.quantities" :key="`${item.key}-row-${qty.key}`">
                                    <td
                                        v-for="column in item.columns"
                                        :key="`${item.key}-${qty.key}-${column}`"
                                        class="h-10 border border-slate-300 bg-white"
                                    />
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-slate-600">
                        {{ item.cue || `Make this table in your copy. Change both measures to ${item.unit} first. x is only the common factor. Write each quantity in ${item.unit}.` }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <label
                            v-for="qty in item.quantities"
                            :key="`${item.key}.${qty.key}`"
                            class="flex items-center gap-2 text-sm font-semibold text-slate-800"
                        >
                            {{ qty.name }}
                            <span class="text-xs font-medium text-teal-800">{{ item.unit }}</span>
                            <input
                                :value="answers[`${item.key}.${qty.key}`]"
                                type="text"
                                inputmode="text"
                                class="w-24 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold focus:border-teal-500 focus:ring-teal-200"
                                :class="cellClass(`${item.key}.${qty.key}`)"
                                autocomplete="off"
                                :aria-label="`${qty.name} in ${item.unit}`"
                                :disabled="status[`${item.key}.${qty.key}`] === true"
                                @input="onInput(`${item.key}.${qty.key}`, $event)"
                                @blur="checkStep(`${item.key}.${qty.key}`)"
                                @keyup.enter="checkStep(`${item.key}.${qty.key}`)"
                            >
                        </label>
                    </div>
                    <p
                        v-if="qtyKeys(item).some((key) => status[key] === false && messages[key])"
                        class="text-sm text-rose-900"
                    >
                        {{ messages[qtyKeys(item).find((key) => status[key] === false)] }}
                    </p>
                    <button
                        v-if="!sumReady(item)"
                        type="button"
                        class="text-sm font-medium text-teal-900 hover:underline"
                        @click="helpOpen[item.key] = true"
                    >
                        I cannot solve this
                    </button>
                    <p
                        v-if="helpOpen[item.key] && !sumReady(item)"
                        class="rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-950"
                    >
                        {{ item.hint }}
                    </p>
                </div>
            </div>
        </article>

        <p class="text-sm font-semibold text-slate-600">
            {{ readyCount }} / {{ items.length }} right
        </p>

        <div
            v-if="allReady"
            class="space-y-3 rounded-xl border-2 border-emerald-400 bg-emerald-50 px-4 py-4"
        >
            <p class="text-sm font-medium text-emerald-900">
                All {{ items.length }} sums are right. Each quantity is the ratio part times the factor.
            </p>
            <button
                v-if="canGoNext"
                type="button"
                class="rounded-md bg-teal-800 px-4 py-2 text-sm font-extrabold uppercase tracking-wide text-white hover:bg-teal-900"
                @click="emit('next')"
            >
                Next →
            </button>
            <SeriesFinished v-else :next-part="nextPart" :series-label="seriesLabel" />
        </div>
    </div>
</template>
