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

const play = computed(() => props.exercise?.simple_interest_practice || {});
const outlineRows = computed(() => play.value.outline_rows || []);

const answers = reactive({ answer: '' });
const status = reactive({ answer: null });
const messages = reactive({ answer: '' });
const wrongCount = reactive({ answer: 0 });
const helpOpen = reactive({ answer: false });
const checking = reactive({ answer: false });
let timer = null;

const reset = () => {
    answers.answer = '';
    status.answer = null;
    messages.answer = '';
    wrongCount.answer = 0;
    helpOpen.answer = false;
    checking.answer = false;
    if (timer) {
        window.clearTimeout(timer);
    }
};

watch(() => props.exercise?.id, reset, { immediate: true });

const allReady = computed(() => status.answer === true);

const cleanMath = (value) => String(value ?? '')
    .replace(/[−–—﹣－]/g, '-')
    .replace(/^(₹|rs\.?)\s*/i, '')
    .replace(/\s*(%|years?|yrs?)\s*$/i, '')
    .replace(/\s+/g, '')
    .trim();

const cellClass = () => {
    if (status.answer === true) {
        return 'border-emerald-500 bg-emerald-50';
    }
    if (status.answer === false) {
        return 'border-rose-500 bg-rose-50';
    }

    return 'border-slate-300 bg-white';
};

const checkAnswer = async () => {
    const value = cleanMath(answers.answer);
    if (value === '' || checking.answer || status.answer === true) {
        return;
    }
    checking.answer = true;
    try {
        const { data } = await axios.post(props.check_url, {
            exercise_id: props.exercise.id,
            step_key: 'answer',
            value,
            values: { answer: value },
        }, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        });
        const ok = !! (data.cell_results?.answer ?? data.ok);
        status.answer = ok;
        messages.answer = data.message || (ok ? 'Right.' : 'Check your table and calculation.');
        if (ok) {
            helpOpen.answer = false;
        } else {
            wrongCount.answer = (wrongCount.answer || 0) + 1;
            if (wrongCount.answer >= 2) {
                helpOpen.answer = true;
            }
        }
    } catch (e) {
        status.answer = false;
        messages.answer = e?.response?.data?.message || 'Could not check. Try again.';
    } finally {
        checking.answer = false;
    }
};

const scheduleCheck = () => {
    if (timer) {
        window.clearTimeout(timer);
    }
    if (status.answer === true) {
        return;
    }
    timer = window.setTimeout(() => checkAnswer(), 500);
};

const onInput = (event) => {
    answers.answer = event.target.value;
    status.answer = null;
    messages.answer = '';
    scheduleCheck();
};
</script>

<template>
    <div class="mt-3 space-y-4">
        <article class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-emerald-900">
                Practice sum — do it in your copy
            </p>
            <p class="mt-1 text-base font-medium leading-relaxed text-slate-900">
                {{ play.story }}
            </p>
        </article>

        <p class="text-sm leading-relaxed text-slate-700">
            {{ play.blurb || 'Draw this outline in your notebook. Fill Given, write To find, write the formula, calculate, then punch only the final answer here.' }}
        </p>

        <section class="overflow-hidden rounded-xl border border-teal-200 bg-white">
            <div class="border-b border-teal-200 bg-teal-50 px-3 py-2">
                <p class="text-[11px] font-extrabold uppercase tracking-wide text-teal-950">
                    Outline to copy
                </p>
            </div>

            <div class="space-y-4 p-3">
                <div>
                    <p class="mb-1 text-xs font-extrabold uppercase tracking-wide text-slate-600">
                        1. Given
                    </p>
                    <table class="min-w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="border border-slate-200 px-3 py-2 text-left text-xs font-extrabold uppercase text-slate-700">Given</th>
                                <th class="border border-slate-200 px-3 py-2 text-left text-xs font-extrabold uppercase text-slate-700">Notation</th>
                                <th class="border border-slate-200 px-3 py-2 text-left text-xs font-extrabold uppercase text-slate-700">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in outlineRows" :key="row.notation">
                                <td class="border border-slate-200 px-3 py-2 font-semibold text-slate-800">{{ row.label }}</td>
                                <td class="border border-slate-200 px-3 py-2 text-center font-mono font-extrabold text-teal-900">{{ row.notation }}</td>
                                <td class="border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-center text-slate-400">____</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-dashed border-violet-300 bg-violet-50/50 px-3 py-2">
                        <p class="text-[11px] font-extrabold uppercase tracking-wide text-violet-900">2. To find</p>
                        <p class="mt-2 font-mono text-sm font-semibold text-violet-950">{{ play.find_label }} → ____</p>
                    </div>
                    <div class="rounded-lg border border-dashed border-amber-300 bg-amber-50/50 px-3 py-2 sm:col-span-2">
                        <p class="text-[11px] font-extrabold uppercase tracking-wide text-amber-900">3. Formula</p>
                        <p class="mt-2 font-mono text-sm font-semibold text-amber-950">{{ play.formula_outline }}</p>
                        <p class="mt-1 text-xs text-amber-900/80">Write it in your copy, then substitute.</p>
                    </div>
                </div>

                <div class="rounded-lg border border-dashed border-sky-300 bg-sky-50/50 px-3 py-2">
                    <p class="text-[11px] font-extrabold uppercase tracking-wide text-sky-900">4. Calculate</p>
                    <p class="mt-2 text-sm text-sky-950">
                        Do the working in your copy. Then punch the final answer below.
                    </p>
                </div>
            </div>
        </section>

        <article
            class="rounded-xl border px-4 py-3"
            :class="allReady ? 'border-emerald-300 bg-emerald-50/70' : 'border-sky-200 bg-white'"
        >
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-sky-900">
                Punch the answer
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="text-sm font-semibold text-slate-800">{{ play.answer_label || 'Answer' }}</span>
                <input
                    :value="answers.answer"
                    type="text"
                    inputmode="decimal"
                    class="w-32 rounded-md border px-2 py-1 text-center font-mono text-xl font-extrabold focus:border-sky-500 focus:ring-sky-200 sm:w-40"
                    :class="cellClass()"
                    autocomplete="off"
                    aria-label="Final answer"
                    :disabled="status.answer === true"
                    @input="onInput"
                    @blur="checkAnswer"
                    @keyup.enter="checkAnswer"
                >
                <span v-if="play.answer_unit" class="text-sm text-slate-600">{{ play.answer_unit }}</span>
                <button
                    v-if="status.answer !== true"
                    type="button"
                    class="rounded-md bg-sky-800 px-3 py-1.5 text-sm font-extrabold text-white hover:bg-sky-900"
                    @click="checkAnswer"
                >
                    Check
                </button>
                <span v-if="status.answer === true" class="text-sm font-bold text-emerald-800">Right</span>
            </div>
            <p v-if="messages.answer && status.answer === false" class="mt-2 text-sm text-rose-900">
                {{ messages.answer }}
            </p>
            <button
                v-if="status.answer !== true"
                type="button"
                class="mt-2 text-sm font-medium text-sky-900 hover:underline"
                @click="helpOpen.answer = true"
            >
                I cannot get the answer
            </button>
            <p
                v-if="helpOpen.answer && status.answer !== true && play.hint"
                class="mt-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-950"
            >
                {{ play.hint }}
            </p>
        </article>

        <div
            v-if="allReady"
            class="space-y-3 rounded-xl border-2 border-emerald-400 bg-emerald-50 px-4 py-4"
        >
            <p class="text-sm font-medium text-emerald-900">
                Right. Keep using Given → To find → Formula → Calculate in every interest sum.
            </p>
            <button
                v-if="canGoNext"
                type="button"
                class="rounded-md bg-teal-800 px-4 py-2 text-sm font-extrabold uppercase tracking-wide text-white hover:bg-teal-900"
                @click="emit('next')"
            >
                Next practice sum →
            </button>
            <SeriesFinished v-else :next-part="nextPart" :series-label="seriesLabel" />
        </div>
    </div>
</template>
