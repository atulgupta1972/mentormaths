<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    session: { type: Object, required: true },
});

const sessionState = ref({ ...props.session });
const answer = ref('');
const submitting = ref(false);
const reveal = ref(null);
const secondsLeft = ref(0);
const answerInputRef = ref(null);
let timerId = null;

const isShowPhase = computed(() => sessionState.value.is_show_phase);
const chart = computed(() => sessionState.value.chart);
const currentItem = computed(() => sessionState.value.current_item);
const secondsPerBlank = computed(() => sessionState.value.seconds_per_blank || 5);

const phaseTitle = computed(() => {
    const phase = sessionState.value.phase || '';

    if (phase.includes('table')) {
        return `Table of ${sessionState.value.table_number}`;
    }
    if (phase.includes('square')) {
        return 'Squares';
    }
    if (phase.includes('cube')) {
        return 'Cubes';
    }

    return 'Basics drill';
});

const applySession = (next) => {
    sessionState.value = { ...next };
    reveal.value = null;
    answer.value = '';
};

const clearTimer = () => {
    if (timerId) {
        window.clearInterval(timerId);
        timerId = null;
    }
};

const startTimer = () => {
    clearTimer();
    secondsLeft.value = secondsPerBlank.value;

    timerId = window.setInterval(() => {
        secondsLeft.value -= 1;

        if (secondsLeft.value <= 0) {
            clearTimer();
            submitAnswer(true);
        }
    }, 1000);
};

const focusAnswerInput = () => {
    nextTick(() => {
        answerInputRef.value?.focus();
    });
};

watch(
    () => currentItem.value?.id,
    (id) => {
        if (id && !isShowPhase.value && !reveal.value) {
            answer.value = '';
            startTimer();
            focusAnswerInput();
        } else {
            clearTimer();
        }
    },
    { immediate: true },
);

onUnmounted(clearTimer);

const postJson = async (url, body = {}) => {
    const { data } = await axios.post(url, body);

    return data;
};

const startDrill = async () => {
    submitting.value = true;

    try {
        const payload = await postJson(route('student.basics-drill.start', sessionState.value.id));
        applySession(payload.session);
    } finally {
        submitting.value = false;
    }
};

const submitAnswer = async (timedOut = false) => {
    if (!currentItem.value || submitting.value || reveal.value) {
        return;
    }

    if (!timedOut && !answer.value.trim()) {
        return;
    }

    submitting.value = true;
    clearTimer();

    try {
        const payload = await postJson(route('student.basics-drill.answer', currentItem.value.id), {
            answer: answer.value,
            timed_out: timedOut,
        });

        applySession(payload.session);

        if (payload.reveal) {
            reveal.value = {
                itemId: payload.item_id,
                prompt: payload.prompt,
                correctAnswer: payload.correct_answer,
            };

            return;
        }

        if (payload.completed && payload.redirect) {
            router.visit(payload.redirect);

            return;
        }
    } finally {
        submitting.value = false;
    }
};

const acknowledgeReveal = async () => {
    if (!reveal.value || submitting.value) {
        return;
    }

    submitting.value = true;

    try {
        const payload = await postJson(route('student.basics-drill.acknowledge', reveal.value.itemId));
        applySession(payload.session);

        if (payload.session?.is_complete) {
            router.visit(route('dashboard'));
        }
    } finally {
        submitting.value = false;
    }
};

const timerPercent = computed(() => {
    if (!secondsPerBlank.value) {
        return 0;
    }

    return Math.max(0, Math.round((secondsLeft.value / secondsPerBlank.value) * 100));
});
</script>

<template>
    <Head title="Tables & powers" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">{{ phaseTitle }}</h2>
                <p class="text-sm text-gray-500">
                    Memorise, then type answers quickly.
                    <span v-if="sessionState.progress_label"> · {{ sessionState.progress_label }}</span>
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl space-y-6 px-4 sm:px-6">
                <div v-if="isShowPhase && chart" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h3 class="text-lg font-semibold text-indigo-700">{{ chart.title }}</h3>
                    <p class="mt-1 text-sm text-gray-600">Study this chart. When ready, tap Start — order will be random.</p>
                    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div
                            v-for="row in chart.rows"
                            :key="row.label"
                            class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-2 text-center"
                        >
                            <p class="text-sm font-medium text-indigo-900">{{ row.label }}</p>
                            <p class="text-lg font-bold text-indigo-700">{{ row.answer }}</p>
                        </div>
                    </div>
                    <PrimaryButton class="mt-6" :disabled="submitting" @click="startDrill">
                        Start
                    </PrimaryButton>
                </div>

                <div v-else-if="currentItem && !reveal" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <p class="text-center text-3xl font-bold text-gray-900">{{ currentItem.prompt }} = ?</p>

                    <div class="mt-4">
                        <div class="mb-1 flex justify-between text-xs text-gray-500">
                            <span>Time left</span>
                            <span>{{ secondsLeft }}s</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-gray-200">
                            <div
                                class="h-full rounded-full bg-amber-500 transition-all duration-1000"
                                :style="{ width: `${timerPercent}%` }"
                            />
                        </div>
                    </div>

                    <input
                        ref="answerInputRef"
                        v-model="answer"
                        type="tel"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        autocomplete="off"
                        autofocus
                        class="mt-6 block w-full rounded-lg border-gray-300 text-center text-3xl font-bold tracking-widest"
                        placeholder="?"
                        @keyup.enter="submitAnswer(false)"
                    >

                    <PrimaryButton class="mt-4 w-full justify-center" :disabled="submitting" @click="submitAnswer(false)">
                        Submit
                    </PrimaryButton>
                </div>

                <div
                    v-if="reveal"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
                >
                    <div class="w-full max-w-sm rounded-2xl bg-white p-8 text-center shadow-xl">
                        <p class="text-sm text-gray-600">{{ reveal.prompt }}</p>
                        <p class="mt-4 text-6xl font-bold text-rose-600">{{ reveal.correctAnswer }}</p>
                        <p class="mt-3 text-sm text-gray-600">Remember this, then continue.</p>
                        <PrimaryButton class="mt-6 w-full justify-center" :disabled="submitting" @click="acknowledgeReveal">
                            Got it
                        </PrimaryButton>
                    </div>
                </div>

                <div v-if="sessionState.is_complete" class="rounded-xl bg-emerald-50 p-6 text-center text-emerald-900">
                    <p class="font-semibold">Basics drill complete for today.</p>
                    <PrimaryButton class="mt-4" @click="router.visit(route('dashboard'))">
                        Go to dashboard
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
