<script setup>
import EquationFraction from '@/Pages/Student/MmmCards/EquationFraction.vue';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    exercise: { type: Object, required: true },
    check_url: { type: String, required: true },
});

const play = computed(() => props.exercise?.fill_page || {});
const items = computed(() => play.value.items || []);

const answers = reactive({});
const status = reactive({});
const messages = reactive({});
const wrongCount = reactive({});
const helpOpen = reactive({});
const checking = reactive({});
const timers = {};

const allCorrect = computed(() => {
    if (! items.value.length) {
        return false;
    }

    return items.value.every((item) => status[item.key] === true);
});
const correctCount = computed(() => items.value.filter((item) => status[item.key] === true).length);

watch(
    () => props.exercise?.id,
    () => {
        items.value.forEach((item) => {
            answers[item.key] = '';
            status[item.key] = null;
            messages[item.key] = '';
            wrongCount[item.key] = 0;
            helpOpen[item.key] = false;
            checking[item.key] = false;
            if (timers[item.key]) {
                window.clearTimeout(timers[item.key]);
            }
        });
    },
    { immediate: true },
);

const cleanMath = (value) => String(value ?? '')
    .replace(/[−–—﹣－]/g, '-')
    .replace(/[＋﹢]/g, '+')
    .replace(/\s+/g, '');

const cellClass = (key) => {
    if (status[key] === true) {
        return 'border-emerald-500 bg-emerald-50';
    }
    if (status[key] === false) {
        return 'border-rose-500 bg-rose-50';
    }

    return 'border-slate-300';
};

const collectedValues = () => {
    const out = {};
    items.value.forEach((item) => {
        const text = cleanMath(answers[item.key]);
        if (text !== '') {
            out[item.key] = text;
        }
    });

    return out;
};

const checkItem = async (key) => {
    const value = cleanMath(answers[key]);
    if (value === '' || checking[key] || status[key] === true) {
        return;
    }
    checking[key] = true;
    try {
        const payload = {
            exercise_id: props.exercise.id,
            step_key: key,
            value,
            values: { ...collectedValues(), [key]: value },
        };
        const { data } = await axios.post(props.check_url, payload, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        });
        const ok = !! data.ok;
        status[key] = ok;
        messages[key] = data.message || (ok ? 'Right.' : 'Not yet.');
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
    timers[key] = window.setTimeout(() => {
        checkItem(key);
    }, 650);
};

const onInput = (key, event) => {
    answers[key] = event.target.value;
    if (status[key] !== true) {
        status[key] = null;
        messages[key] = '';
    }
    scheduleCheck(key);
};

const onEnter = (key, event) => {
    event.preventDefault();
    if (timers[key]) {
        window.clearTimeout(timers[key]);
    }
    checkItem(key);
};

const askTeacher = (key) => {
    helpOpen[key] = true;
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

        <div class="space-y-3">
            <article
                v-for="(item, index) in items"
                :key="item.key"
                class="rounded-xl border px-3 py-3"
                :class="status[item.key] === true
                    ? 'border-emerald-300 bg-emerald-50/60'
                    : status[item.key] === false
                        ? 'border-rose-200 bg-rose-50/40'
                        : 'border-slate-200 bg-white'"
            >
                <div class="flex flex-wrap items-start gap-3">
                    <span class="mt-1 rounded bg-rose-700 px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-white">
                        {{ index + 1 }}
                    </span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div
                            v-if="item.left && item.right"
                            class="flex flex-wrap items-center gap-3 overflow-x-auto text-lg sm:text-xl"
                        >
                            <EquationFraction :expr="item.left" />
                            <span class="font-mono text-xl font-black text-rose-700">=</span>
                            <EquationFraction :expr="item.right" />
                        </div>
                        <p
                            v-else
                            class="overflow-x-auto font-mono text-base font-extrabold text-slate-900 sm:text-lg"
                        >
                            {{ item.equation }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-lg font-black text-rose-700">x =</span>
                            <input
                                :value="answers[item.key]"
                                type="text"
                                inputmode="text"
                                class="w-24 rounded-md border px-2 py-1 text-center font-mono text-lg font-extrabold focus:border-rose-400 focus:ring-rose-200"
                                :class="cellClass(item.key)"
                                placeholder="?"
                                autocomplete="off"
                                :aria-label="`Value of x for sum ${index + 1}`"
                                :disabled="status[item.key] === true"
                                @input="onInput(item.key, $event)"
                                @blur="checkItem(item.key)"
                                @keyup.enter="onEnter(item.key, $event)"
                            >
                            <span
                                v-if="status[item.key] === true"
                                class="text-sm font-bold text-emerald-800"
                            >
                                Right
                            </span>
                            <span
                                v-else-if="status[item.key] === false"
                                class="text-sm font-bold text-rose-800"
                            >
                                Wrong
                            </span>
                            <span
                                v-else-if="checking[item.key]"
                                class="text-xs text-slate-500"
                            >
                                Checking…
                            </span>
                        </div>
                        <p
                            v-if="messages[item.key] && status[item.key] === false"
                            class="text-sm text-rose-900"
                        >
                            {{ messages[item.key] }}
                        </p>
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                v-if="status[item.key] !== true"
                                type="button"
                                class="text-sm font-medium text-rose-800 hover:underline"
                                @click="askTeacher(item.key)"
                            >
                                I need teacher help
                            </button>
                        </div>
                        <div
                            v-if="helpOpen[item.key] && status[item.key] !== true"
                            class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950"
                        >
                            <p class="font-semibold">Ask your teacher to look at your copy.</p>
                            <p class="mt-1">{{ item.hint }}</p>
                            <p class="mt-1 text-xs">Then type x here — the box still has to be right.</p>
                        </div>
                    </div>
                </div>
            </article>
        </div>

        <p class="text-sm font-semibold text-slate-600">
            {{ correctCount }} / {{ items.length }} right
        </p>

        <div
            v-if="allCorrect"
            class="rounded-xl border-2 border-emerald-400 bg-emerald-50 px-4 py-4"
        >
            <p class="text-sm font-medium text-emerald-900">
                All five are right. You used BODMAS on the numbers, then balanced to find x.
            </p>
        </div>
    </div>
</template>
