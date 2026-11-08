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

const play = computed(() => props.exercise?.ratio || {});
const items = computed(() => play.value.items || []);
const isPage = computed(() => items.value.length > 0);

const answers = reactive({});
const status = reactive({});
const messages = reactive({});
const wrongCount = reactive({});
const helpOpen = reactive({});
const checking = reactive({});
const timers = {};

const keys = computed(() => (isPage.value ? items.value.map((item) => item.key) : ['ratio']));

const reset = () => {
    keys.value.forEach((key) => {
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

const readyCount = computed(() => keys.value.filter((key) => status[key] === true).length);
const allReady = computed(() => keys.value.length > 0 && readyCount.value === keys.value.length);

const cleanMath = (value) => String(value ?? '')
    .replace(/[∶：]/g, ':')
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
        messages[key] = data.message || (ok ? 'Right.' : 'Look at the two quantities again.');
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
</script>

<template>
    <div class="mt-3 space-y-4">
        <template v-if="!isPage">
            <article v-if="play.explain" class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3">
                <p class="text-[11px] font-extrabold uppercase tracking-wide text-sky-800">
                    {{ play.phrase || 'Ratio' }}
                </p>
                <p class="mt-1 text-base font-medium leading-relaxed text-slate-900">
                    {{ play.explain }}
                </p>
                <p v-if="play.worked" class="mt-2 font-mono text-lg font-extrabold text-sky-950">
                    {{ play.worked }}
                </p>
            </article>

            <article class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                <p class="text-base font-medium leading-relaxed text-slate-900">
                    {{ play.story }}
                </p>
                <p v-if="play.cue" class="mt-1 text-sm text-amber-950">
                    {{ play.cue }}
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-slate-800">Ratio</span>
                    <input
                        :value="answers.ratio"
                        type="text"
                        inputmode="text"
                        class="w-28 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold focus:border-teal-500 focus:ring-teal-200"
                        :class="cellClass('ratio')"
                        autocomplete="off"
                        aria-label="Ratio"
                        :disabled="status.ratio === true"
                        @input="onInput('ratio', $event)"
                        @blur="checkStep('ratio')"
                        @keyup.enter="checkStep('ratio')"
                    >
                    <span v-if="status.ratio === true" class="text-sm font-bold text-emerald-800">Right</span>
                    <span v-else-if="status.ratio === false" class="text-sm font-bold text-rose-800">Look again</span>
                </div>
                <p v-if="messages.ratio && status.ratio === false" class="mt-2 text-sm text-rose-900">
                    {{ messages.ratio }}
                </p>
                <button
                    v-if="status.ratio !== true"
                    type="button"
                    class="mt-2 text-sm font-medium text-teal-900 hover:underline"
                    @click="helpOpen.ratio = true"
                >
                    I cannot write it
                </button>
                <p
                    v-if="helpOpen.ratio && status.ratio !== true"
                    class="mt-2 rounded-lg border border-teal-200 bg-white px-3 py-2 text-sm text-teal-950"
                >
                    {{ play.hint }}
                </p>
            </article>
        </template>

        <template v-else>
            <p class="text-sm leading-relaxed text-slate-700">
                {{ play.page_blurb }}
            </p>
            <article
                v-for="(item, index) in items"
                :key="item.key"
                class="rounded-xl border px-3 py-3"
                :class="status[item.key] === true ? 'border-emerald-300 bg-emerald-50/60' : 'border-slate-200 bg-white'"
            >
                <div class="flex flex-wrap items-start gap-3">
                    <span class="mt-1 rounded bg-teal-800 px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-white">
                        {{ index + 1 }}
                    </span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <p class="text-base font-medium leading-relaxed text-slate-900">
                            {{ item.story }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-slate-800">Ratio</span>
                            <input
                                :value="answers[item.key]"
                                type="text"
                                inputmode="text"
                                class="w-28 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold focus:border-teal-500 focus:ring-teal-200"
                                :class="cellClass(item.key)"
                                autocomplete="off"
                                :aria-label="`Ratio for sum ${index + 1}`"
                                :disabled="status[item.key] === true"
                                @input="onInput(item.key, $event)"
                                @blur="checkStep(item.key)"
                                @keyup.enter="checkStep(item.key)"
                            >
                            <span v-if="status[item.key] === true" class="text-sm font-bold text-emerald-800">Right</span>
                            <span v-else-if="status[item.key] === false" class="text-sm font-bold text-rose-800">Look again</span>
                        </div>
                        <p v-if="messages[item.key] && status[item.key] === false" class="text-sm text-rose-900">
                            {{ messages[item.key] }}
                        </p>
                        <button
                            v-if="status[item.key] !== true"
                            type="button"
                            class="text-sm font-medium text-teal-900 hover:underline"
                            @click="helpOpen[item.key] = true"
                        >
                            I cannot write it
                        </button>
                        <p
                            v-if="helpOpen[item.key] && status[item.key] !== true"
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
        </template>

        <div
            v-if="allReady"
            class="space-y-3 rounded-xl border-2 border-emerald-400 bg-emerald-50 px-4 py-4"
        >
            <p class="text-sm font-medium text-emerald-900">
                {{ isPage ? `All ${items.length} ratios are right.` : 'That ratio is in the simplest form.' }}
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
