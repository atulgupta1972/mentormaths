<script setup>
import SeriesFinished from '@/Pages/Student/MmmCards/SeriesFinished.vue';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    exercise: { type: Object, required: true },
    check_url: { type: String, required: true },
    canGoNext: { type: Boolean, default: false },
    nextPart: { type: Object, default: null },
    seriesLabel: { type: String, default: '' },
});

const emit = defineEmits(['next']);

const play = computed(() => props.exercise?.make_equation || {});

const answers = reactive({ define: '', equation: '' });
const status = reactive({ define: null, equation: null });
const messages = reactive({ define: '', equation: '' });
const wrongCount = reactive({ define: 0, equation: 0 });
const helpOpen = reactive({ define: false, equation: false });
const checking = reactive({ define: false, equation: false });
const timers = { define: null, equation: null };

const defineReady = computed(() => status.define === true);
const bothReady = computed(() => status.define === true && status.equation === true);

watch(
    () => props.exercise?.id,
    () => {
        for (const key of ['define', 'equation']) {
            answers[key] = '';
            status[key] = null;
            messages[key] = '';
            wrongCount[key] = 0;
            helpOpen[key] = false;
            checking[key] = false;
            if (timers[key]) {
                window.clearTimeout(timers[key]);
            }
        }
    },
    { immediate: true },
);

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

const checkStep = async (key) => {
    const value = cleanMath(answers[key]);
    if (value === '' || checking[key] || status[key] === true) {
        return;
    }
    if (key === 'equation' && status.define !== true) {
        return;
    }
    checking[key] = true;
    try {
        const values = {};
        if (cleanMath(answers.define) !== '') {
            values.define = cleanMath(answers.define);
        }
        if (cleanMath(answers.equation) !== '') {
            values.equation = cleanMath(answers.equation);
        }
        const { data } = await axios.post(props.check_url, {
            exercise_id: props.exercise.id,
            step_key: key,
            value,
            values,
        }, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        });
        const ok = !! data.ok;
        status[key] = ok;
        messages[key] = data.message || (ok ? 'Right.' : 'Look at the number line and try again.');
        if (ok) {
            helpOpen[key] = false;
        } else {
            wrongCount[key] += 1;
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
        <article class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3">
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-sky-800">
                1 · Say it with a number
            </p>
            <p class="mt-1 text-base font-medium leading-relaxed text-slate-900">
                {{ play.number_story }}
            </p>
            <p class="mt-2 font-mono text-lg font-extrabold text-sky-950">
                {{ play.number_line }}
            </p>
        </article>

        <article
            class="rounded-xl border px-4 py-3"
            :class="status.define === true ? 'border-emerald-300 bg-emerald-50/70' : 'border-amber-200 bg-amber-50'"
        >
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-[11px] font-extrabold uppercase tracking-wide text-amber-900">
                    2 · Say the same amount with x
                </p>
                <span
                    v-if="play.phrase"
                    class="rounded-full bg-amber-800 px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-white"
                >
                    {{ play.phrase }}
                </span>
            </div>
            <p class="mt-1 text-base font-medium leading-relaxed text-slate-900">
                {{ play.x_story }}
            </p>
            <p class="mt-1 text-sm text-amber-950">
                {{ play.define_cue }}
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="text-sm font-semibold text-slate-800">{{ play.define_label || 'That amount is' }}</span>
                <input
                    :value="answers.define"
                    type="text"
                    inputmode="text"
                    class="w-40 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold focus:border-amber-500 focus:ring-amber-200"
                    :class="cellClass('define')"
                    placeholder="x + …"
                    autocomplete="off"
                    aria-label="Amount in terms of x"
                    :disabled="status.define === true"
                    @input="onInput('define', $event)"
                    @blur="checkStep('define')"
                    @keyup.enter="checkStep('define')"
                >
                <span v-if="status.define === true" class="text-sm font-bold text-emerald-800">Right</span>
                <span v-else-if="status.define === false" class="text-sm font-bold text-rose-800">Look again</span>
                <span v-else-if="checking.define" class="text-xs text-slate-500">Checking…</span>
            </div>
            <p v-if="messages.define && status.define === false" class="mt-2 text-sm text-rose-900">
                {{ messages.define }}
            </p>
            <button
                v-if="status.define !== true"
                type="button"
                class="mt-2 text-sm font-medium text-amber-900 hover:underline"
                @click="helpOpen.define = true"
            >
                I cannot write it
            </button>
            <p
                v-if="helpOpen.define && status.define !== true"
                class="mt-2 rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm text-amber-950"
            >
                {{ play.define_hint }}
            </p>
        </article>

        <article
            class="rounded-xl border px-4 py-3"
            :class="bothReady
                ? 'border-emerald-300 bg-emerald-50/70'
                : defineReady
                    ? 'border-violet-200 bg-white'
                    : 'border-slate-200 bg-slate-50'"
        >
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-violet-900">
                3 · Make the equation
            </p>
            <template v-if="defineReady">
                <p class="mt-1 text-base font-medium leading-relaxed text-slate-900">
                    {{ play.equation_story }}
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
                        class="w-56 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold focus:border-violet-500 focus:ring-violet-200"
                        :class="cellClass('equation')"
                        placeholder="x + … = …"
                        autocomplete="off"
                        aria-label="Equation for the statement"
                        :disabled="status.equation === true"
                        @input="onInput('equation', $event)"
                        @blur="checkStep('equation')"
                        @keyup.enter="checkStep('equation')"
                    >
                    <span v-if="status.equation === true" class="text-sm font-bold text-emerald-800">Right</span>
                    <span v-else-if="status.equation === false" class="text-sm font-bold text-rose-800">Look again</span>
                    <span v-else-if="checking.equation" class="text-xs text-slate-500">Checking…</span>
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
                This line opens once the amount is written with x.
            </p>
        </article>

        <div
            v-if="bothReady"
            class="space-y-3 rounded-xl border-2 border-emerald-400 bg-emerald-50 px-4 py-4"
        >
            <p class="text-sm font-medium text-emerald-900">
                The statement is now an equation. The same words work for more, less, times, half, higher, and lower.
            </p>
            <button
                v-if="canGoNext"
                type="button"
                class="rounded-md bg-amber-800 px-4 py-2 text-sm font-extrabold uppercase tracking-wide text-white hover:bg-amber-900"
                @click="emit('next')"
            >
                Next statement →
            </button>
            <SeriesFinished v-else :next-part="nextPart" :series-label="seriesLabel" />
        </div>
    </div>
</template>
