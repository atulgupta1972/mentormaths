<script setup>
import McqOptionLine from '@/Components/McqOptionLine.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    session: { type: Object, required: true },
});

const sessionState = ref({ ...props.session });
const answer = ref('');
const submitting = ref(false);
const reveal = ref(null);
const completionSummary = ref(null);
const correctionIntroDismissed = ref(false);
const secondsLeft = ref(0);
const answerInputRef = ref(null);
const selectedOptionId = ref(null);
let timerId = null;

const isShowPhase = computed(() => sessionState.value.is_show_phase);
const isFinalCorrection = computed(() => sessionState.value.is_final_correction);
const chart = computed(() => sessionState.value.chart);
const currentItem = computed(() => sessionState.value.current_item);
const secondsPerBlank = computed(() => sessionState.value.seconds_per_blank || 5);
const isFormulaMcq = computed(() => currentItem.value?.is_formula_mcq);
const isFormulaFillBlank = computed(() => currentItem.value?.is_formula_fill_blank);
const correctionIntro = computed(() => sessionState.value.correction_intro ?? null);
const showCorrectionIntro = computed(
    () => isFinalCorrection.value && correctionIntro.value && !correctionIntroDismissed.value,
);

const phaseTitle = computed(() => {
    if (showCorrectionIntro.value) {
        return 'Almost there!';
    }

    if (isFinalCorrection.value) {
        return 'Fix your mistakes';
    }

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
    selectedOptionId.value = null;
};

const goDashboard = () => {
    router.visit(route('dashboard'));
};

const handleAdvancePayload = (payload) => {
    if (payload.session) {
        applySession(payload.session);

        if (payload.session.correction_intro) {
            correctionIntroDismissed.value = false;
        }
    }

    if (payload.next_item) {
        sessionState.value = {
            ...sessionState.value,
            current_item: payload.next_item,
        };
    }

    if (payload.completion_summary) {
        completionSummary.value = payload.completion_summary;
        applySession(payload.session ?? sessionState.value);

        return true;
    }

    if (payload.completed || payload.session?.is_complete) {
        if (! payload.completion_summary) {
            router.visit(payload.redirect || route('dashboard'));
        }

        return true;
    }

    return false;
};

const startCorrectionRound = () => {
    correctionIntroDismissed.value = true;
};

const clearTimer = () => {
    if (timerId) {
        window.clearInterval(timerId);
    }
    timerId = null;
};

const startTimer = () => {
    if (isFinalCorrection.value || isFormulaMcq.value || isFormulaFillBlank.value) {
        clearTimer();

        return;
    }

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
        if (id && !isShowPhase.value && !reveal.value && !isFormulaMcq.value && !isFormulaFillBlank.value) {
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
        if (handleAdvancePayload(payload)) {
            return;
        }
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

        if (payload.reveal) {
            applySession(payload.session);
            reveal.value = {
                itemId: payload.item_id,
                prompt: payload.prompt,
                correctAnswer: payload.correct_answer,
                isMcq: false,
            };

            return;
        }

        if (handleAdvancePayload(payload)) {
            return;
        }

        applySession(payload.session);
    } finally {
        submitting.value = false;
    }
};

const submitMcqAnswer = async () => {
    if (!selectedOptionId.value || !currentItem.value || submitting.value || reveal.value) {
        return;
    }

    submitting.value = true;

    try {
        const payload = await postJson(route('student.basics-drill.mcq-answer', currentItem.value.id), {
            option_id: selectedOptionId.value,
        });

        if (payload.reveal) {
            applySession(payload.session);
            reveal.value = {
                itemId: payload.item_id,
                prompt: payload.prompt,
                correctOptionId: payload.correct_option_id,
                isMcq: true,
            };

            return;
        }

        if (handleAdvancePayload(payload)) {
            return;
        }

        applySession(payload.session);
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

        if (handleAdvancePayload(payload)) {
            return;
        }

        if (payload.next_item) {
            sessionState.value = {
                ...sessionState.value,
                current_item: payload.next_item,
            };
        }

        reveal.value = null;
        selectedOptionId.value = null;
        answer.value = '';
    } finally {
        submitting.value = false;
    }
};

const continueAfterBlank = () => {
    router.visit(route('student.basics-drill.show'), {
        replace: true,
        preserveScroll: false,
        onSuccess: (page) => {
            const next = page.props.session;
            if (next) {
                applySession(next);
            }
        },
    });
};

onMounted(() => {
    const blank =
        !showCorrectionIntro.value
        && !completionSummary.value
        && !isShowPhase.value
        && !currentItem.value
        && !reveal.value
        && !sessionState.value.is_complete;

    // One auto-retry so students stuck on the amber screen (e.g. correction already done) recover without a second tap.
    if (blank && typeof window !== 'undefined' && !window.__basicsDrillBlankRetried) {
        window.__basicsDrillBlankRetried = true;
        continueAfterBlank();
    }
});

const timerPercent = computed(() => {
    if (!secondsPerBlank.value) {
        return 0;
    }

    return Math.max(0, Math.round((secondsLeft.value / secondsPerBlank.value) * 100));
});

const mcqOptionClass = (optionId) => {
    return selectedOptionId.value === optionId
        ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-300'
        : 'border-gray-200 bg-white hover:border-indigo-300';
};
</script>

<template>
    <Head title="Tables & powers" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">{{ phaseTitle }}</h2>
                <p class="text-sm text-gray-500">
                    <template v-if="showCorrectionIntro">
                        One last push — you&apos;re doing brilliantly.
                    </template>
                    <template v-else-if="isFinalCorrection">
                        Answer each wrong item correctly on your first try to finish today&apos;s drill.
                    </template>
                    <template v-else>
                        Memorise, then type answers quickly.
                    </template>
                    <span v-if="sessionState.progress_label"> · {{ sessionState.progress_label }}</span>
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl space-y-6 px-4 sm:px-6">
                <div
                    v-if="showCorrectionIntro"
                    class="overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-600 via-indigo-700 to-violet-800 p-8 text-center text-white shadow-xl"
                >
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-200">
                        Daily drill
                    </p>
                    <h3 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">
                        {{ correctionIntro.headline }}
                    </h3>
                    <p class="mt-6 text-base text-indigo-100">
                        You got
                        <span class="text-5xl font-black text-white sm:text-6xl">{{ correctionIntro.percent }}%</span>
                        correct on the first attempt.
                    </p>
                    <p class="mx-auto mt-4 max-w-md text-sm text-indigo-100">
                        {{ correctionIntro.first_try_correct }} of {{ correctionIntro.total }} right first time —
                        that&apos;s solid work. Now lock in the rest.
                    </p>
                    <p class="mx-auto mt-8 max-w-lg text-xl font-bold leading-snug text-amber-300 sm:text-2xl">
                        {{ correctionIntro.call_to_action }}
                    </p>
                    <button
                        type="button"
                        class="mt-8 inline-flex items-center justify-center rounded-md bg-white px-8 py-3 text-base font-bold text-gray-900 shadow-sm hover:bg-indigo-50"
                        @click="startCorrectionRound"
                    >
                        Let&apos;s make it 100%
                    </button>
                </div>

                <div
                    v-else-if="completionSummary"
                    class="overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-500 via-emerald-600 to-teal-700 p-8 text-center text-white shadow-xl"
                >
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-100">
                        Drill complete
                    </p>
                    <h3 class="mt-3 text-4xl font-extrabold tracking-tight sm:text-5xl">
                        {{ completionSummary.headline }}
                    </h3>
                    <p v-if="completionSummary.main_first_try_percent < 100" class="mt-4 text-lg text-emerald-100">
                        You started at {{ completionSummary.main_first_try_percent }}% first-try — and you finished strong.
                    </p>
                    <p class="mx-auto mt-6 max-w-lg text-lg font-semibold leading-relaxed text-white">
                        {{ completionSummary.message }}
                    </p>
                    <button
                        type="button"
                        class="mt-8 inline-flex items-center justify-center rounded-md bg-white px-8 py-3 text-base font-bold text-gray-900 shadow-sm hover:bg-emerald-50"
                        @click="goDashboard"
                    >
                        Go to dashboard
                    </button>
                </div>

                <div v-else-if="isShowPhase && chart" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
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

                <div v-else-if="currentItem && isFormulaFillBlank && !reveal" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <QuestionBody :question-text="currentItem.question.question_text" />

                    <div class="mt-5 space-y-3">
                        <p v-if="currentItem.question.answer_format_label" class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            {{ currentItem.question.answer_format_label }}
                        </p>
                        <TextInput
                            :key="currentItem.id"
                            v-model="answer"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            class="block w-full max-w-xs text-lg"
                            placeholder="Enter your answer"
                            :disabled="submitting"
                            @keyup.enter="submitAnswer(false)"
                        />
                    </div>

                    <PrimaryButton
                        class="mt-6 w-full justify-center"
                        :disabled="!answer.trim() || submitting"
                        @click="submitAnswer(false)"
                    >
                        Submit
                    </PrimaryButton>
                </div>

                <div v-else-if="currentItem && isFormulaMcq && !reveal" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <QuestionBody :question-text="currentItem.question.question_text" />

                    <div class="mt-5 space-y-2">
                        <button
                            v-for="(option, optIndex) in currentItem.question.options"
                            :key="option.id"
                            type="button"
                            class="block w-full rounded-lg border p-3 text-left transition"
                            :class="mcqOptionClass(option.id)"
                            :disabled="submitting"
                            @click="selectedOptionId = option.id"
                        >
                            <McqOptionLine :index="optIndex" :text="option.option_text" />
                        </button>
                    </div>

                    <PrimaryButton
                        class="mt-6 w-full justify-center"
                        :disabled="!selectedOptionId || submitting"
                        @click="submitMcqAnswer"
                    >
                        Submit
                    </PrimaryButton>
                </div>

                <div v-else-if="currentItem && !reveal && !isFormulaMcq && !isFormulaFillBlank" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <p class="text-center text-3xl font-bold text-gray-900">
                        <template v-if="currentItem.is_table_reverse">{{ currentItem.prompt }}</template>
                        <template v-else>{{ currentItem.prompt }} = ?</template>
                    </p>

                    <div v-if="!isFinalCorrection" class="mt-4">
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
                    v-else-if="reveal"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
                >
                    <div class="w-full max-w-sm rounded-2xl bg-white p-8 text-center shadow-xl">
                        <p class="text-sm text-gray-600">{{ reveal.prompt }}</p>
                        <p v-if="!reveal.isMcq" class="mt-4 text-6xl font-bold text-rose-600">{{ reveal.correctAnswer }}</p>
                        <p v-else class="mt-4 text-lg font-semibold text-rose-600">See the correct option marked when you continue.</p>
                        <p class="mt-3 text-sm text-gray-600">
                            {{ isFinalCorrection ? 'Try again — first attempt must be correct.' : 'Remember this, then continue.' }}
                        </p>
                        <PrimaryButton class="mt-6 w-full justify-center" :disabled="submitting" @click="acknowledgeReveal">
                            Try again
                        </PrimaryButton>
                    </div>
                </div>

                <div v-else-if="sessionState.is_complete" class="rounded-xl bg-emerald-50 p-6 text-center text-emerald-900">
                    <p class="font-semibold">Basics drill complete for today.</p>
                    <PrimaryButton class="mt-4" @click="goDashboard">
                        Go to dashboard
                    </PrimaryButton>
                </div>

                <div
                    v-else
                    class="rounded-xl bg-amber-50 p-6 text-center text-amber-950 ring-1 ring-amber-200"
                >
                    <p class="font-semibold">This step finished — tap Continue.</p>
                    <p class="mt-1 text-sm text-amber-800">
                        Moving you to the next part (or finishing today’s drill if corrections are done).
                    </p>
                    <PrimaryButton class="mt-4" @click="continueAfterBlank">
                        Continue
                    </PrimaryButton>
                    <button
                        type="button"
                        class="mt-3 block w-full text-sm font-medium text-indigo-700 hover:underline"
                        @click="goDashboard"
                    >
                        Go to dashboard
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
