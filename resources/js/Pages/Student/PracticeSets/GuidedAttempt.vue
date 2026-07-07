<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    finished: { type: Boolean, default: false },
    progress: { type: Object, default: null },
    phase: { type: String, default: 'answering' },
    show_explanation: { type: Boolean, default: false },
    can_give_up: { type: Boolean, default: false },
    question: { type: Object, default: null },
    practice_set: { type: Object, default: null },
    attempt: { type: Object, default: null },
    summary: { type: Object, default: null },
});

const page = usePage();
const elapsed = ref(0);
let timer = null;

const answerForm = useForm({ option_id: null, answer_text: '' });
const giveUpForm = useForm({});

const feedback = computed(() => page.props.flash?.guided_feedback ?? null);
const isFillInBlank = computed(() => props.question?.type === 'fill_in_blank');

const answerPlaceholder = computed(() => {
    const format = props.question?.answer_format;

    if (format === 'integer') {
        return 'Enter a whole number, e.g. -4';
    }

    if (format === 'decimal') {
        return 'Enter a decimal, e.g. 3.5';
    }

    if (format === 'fraction') {
        return 'Enter a fraction, e.g. 3/4 or 1 1/2';
    }

    return 'Enter your answer';
});

const setLabel = () => props.practice_set?.set_code || 'Practice';

const feedbackClass = computed(() => {
    if (!feedback.value) {
        return '';
    }

    return {
        correct: 'border-emerald-200 bg-emerald-50 text-emerald-900',
        retry: 'border-amber-200 bg-amber-50 text-amber-900',
        explained: 'border-sky-200 bg-sky-50 text-sky-900',
        incorrect: 'border-rose-200 bg-rose-50 text-rose-900',
    }[feedback.value.type] || 'border-gray-200 bg-gray-50 text-gray-800';
});

onMounted(() => {
    if (!props.attempt?.started_at) {
        return;
    }

    const started = new Date(props.attempt.started_at).getTime();
    const tick = () => {
        elapsed.value = Math.floor((Date.now() - started) / 1000);
    };
    tick();
    timer = setInterval(tick, 1000);
});

onUnmounted(() => {
    if (timer) {
        clearInterval(timer);
    }
});

const formatTime = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
};

const submitMcqAnswer = (optionId) => {
    answerForm.option_id = optionId;
    answerForm.answer_text = '';
    answerForm.post(route('student.attempts.guided.answer', props.attempt.id), {
        preserveScroll: true,
    });
};

const submitBlankAnswer = () => {
    answerForm.option_id = null;
    answerForm.post(route('student.attempts.guided.answer', props.attempt.id), {
        preserveScroll: true,
    });
};

const giveUp = () => {
    if (!confirm('Give up on this sum? It will go to your resolution list for your teacher to explain.')) {
        return;
    }

    giveUpForm.post(route('student.attempts.guided.give-up', props.attempt.id), {
        preserveScroll: true,
    });
};

const canAnswer = computed(() => ['answering', 'retry', 'explained'].includes(props.phase));

watch(
    () => props.question?.id,
    (questionId, previousId) => {
        if (questionId && questionId !== previousId) {
            answerForm.answer_text = '';
            answerForm.option_id = null;
            answerForm.clearErrors();
        }
    },
);
</script>

<template>
    <Head :title="setLabel()" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Guided practice</p>
                    <h2 class="font-mono text-xl font-semibold text-gray-800">{{ setLabel() }}</h2>
                </div>
                <span v-if="attempt" class="rounded-full bg-gray-100 px-3 py-1 font-mono text-sm">{{ formatTime(elapsed) }}</span>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-3xl space-y-5 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 p-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div v-if="progress" class="rounded-lg bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700">
                            Question {{ progress.current }} of {{ progress.total }}
                        </span>
                        <span class="text-gray-500">First-try score counts; fixes after help are tracked separately.</span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100">
                        <div
                            class="h-full rounded-full bg-indigo-500 transition-all"
                            :style="{ width: `${(progress.current / progress.total) * 100}%` }"
                        />
                    </div>
                </div>

                <div v-if="feedback" class="rounded-lg border p-4 text-sm" :class="feedbackClass">
                    {{ feedback.message }}
                </div>

                <div v-if="question" class="rounded-lg bg-white p-5 shadow-sm">
                    <p class="text-sm font-semibold text-indigo-600">Question {{ question.number }}</p>

                    <div class="mt-3">
                        <QuestionBody
                            :question-text="question.question_text"
                            :diagram-url="question.diagram_url"
                            use-html
                        />
                    </div>

                    <div v-if="show_explanation" class="mt-4 rounded-lg border border-sky-200 bg-sky-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-sky-800">Method — theory only</p>
                        <p v-if="question.method_hint" class="mt-2 whitespace-pre-wrap text-sm text-sky-950">{{ question.method_hint }}</p>
                        <p v-else class="mt-2 text-sm text-sky-900">
                            Think about the rules for this type of sum. No final answer is shown here — try again using the idea your teacher taught.
                        </p>
                    </div>

                    <div v-if="isFillInBlank" class="mt-4 space-y-3">
                        <p v-if="question.answer_format_label" class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            {{ question.answer_format_label }}
                        </p>
                        <TextInput
                            :key="question.id"
                            v-model="answerForm.answer_text"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            class="block w-full max-w-xs text-lg"
                            :placeholder="answerPlaceholder"
                            :disabled="!canAnswer || answerForm.processing"
                            @keyup.enter="submitBlankAnswer"
                        />
                        <PrimaryButton
                            type="button"
                            :disabled="!canAnswer || answerForm.processing || !answerForm.answer_text.trim()"
                            @click="submitBlankAnswer"
                        >
                            {{ answerForm.processing ? 'Checking…' : 'Submit answer' }}
                        </PrimaryButton>
                    </div>

                    <div v-else class="mt-4 space-y-2">
                        <button
                            v-for="(opt, optIndex) in question.options"
                            :key="opt.id"
                            type="button"
                            class="flex w-full items-start gap-3 rounded-lg border px-4 py-3 text-left text-sm transition"
                            :class="canAnswer && !answerForm.processing
                                ? 'border-gray-200 hover:border-indigo-300 hover:bg-indigo-50'
                                : 'cursor-not-allowed border-gray-100 bg-gray-50 opacity-70'"
                            :disabled="!canAnswer || answerForm.processing"
                            @click="submitMcqAnswer(opt.id)"
                        >
                            <McqOptionLine :index="optIndex" :text="opt.option_text" />
                        </button>
                    </div>

                    <div v-if="can_give_up" class="mt-4 flex flex-wrap gap-3 border-t pt-4">
                        <SecondaryButton type="button" :disabled="giveUpForm.processing" @click="giveUp">
                            Give up — ask teacher later
                        </SecondaryButton>
                    </div>
                </div>

                <Link :href="route('dashboard')" class="inline-block text-sm text-indigo-600 hover:underline">
                    Back to dashboard
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
