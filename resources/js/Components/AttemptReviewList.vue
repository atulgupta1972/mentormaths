<script setup>
import QuestionBody from '@/Components/QuestionBody.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import axios from 'axios';
import { computed, reactive } from 'vue';

const props = defineProps({
    questions: {
        type: Array,
        default: () => [],
    },
    attemptId: {
        type: Number,
        default: null,
    },
    allowPracticeRetry: {
        type: Boolean,
        default: false,
    },
});

const hasReview = computed(() => props.questions.length > 0);

const wrongQuestions = computed(() => props.questions.filter((question) => question.needs_practice_retry));

const practiceState = reactive({});

const practiceKey = (questionId) => String(questionId);

const getPracticeState = (questionId) => practiceState[practiceKey(questionId)] ?? { status: 'idle' };

const isFillInBlank = (question) => question.type === 'fill_in_blank';

const answerPlaceholder = (question) => {
    const format = question.answer_format;

    if (format === 'integer') {
        return 'Enter a whole number, e.g. -4';
    }

    if (format === 'decimal') {
        return 'Enter a decimal, e.g. 3.5';
    }

    if (format === 'fraction') {
        return 'Enter a fraction, e.g. 3/4 or 1 1/2';
    }

    if (format === 'text') {
        return 'Enter your answer, e.g. < or > or =';
    }

    return 'Enter your answer';
};

const showCorrectAnswer = (question) => {
    const state = getPracticeState(question.question_id);

    if (state.status === 'correct') {
        return state.correct_answer || question.correct_answer;
    }

    return !question.needs_practice_retry ? question.correct_answer : null;
};

const submitPracticeRetry = async (question, { optionId = null, answerText = null } = {}) => {
    if (!props.allowPracticeRetry || !props.attemptId) {
        return;
    }

    const key = practiceKey(question.question_id);
    practiceState[key] = { status: 'checking', message: 'Checking…' };

    try {
        const { data } = await axios.post(route('student.attempts.practice-retry', props.attemptId), {
            question_id: question.question_id,
            option_id: optionId,
            answer_text: answerText,
        });

        practiceState[key] = {
            status: data.correct ? 'correct' : 'wrong',
            message: data.message,
            correct_answer: data.correct_answer ?? null,
        };
    } catch (error) {
        practiceState[key] = {
            status: 'wrong',
            message: error.response?.data?.message || 'Could not check your answer. Try again.',
        };
    }
};

const submitMcqRetry = (question, optionId) => {
    submitPracticeRetry(question, { optionId });
};

const blankInputs = reactive({});

const submitBlankRetry = (question) => {
    const answerText = (blankInputs[practiceKey(question.question_id)] ?? '').trim();

    if (!answerText) {
        return;
    }

    submitPracticeRetry(question, { answerText });
};
</script>

<template>
    <div v-if="hasReview" class="space-y-4">
        <div>
            <h3 class="text-sm font-semibold text-gray-800">Review sums</h3>
            <p class="mt-1 text-xs text-gray-500">
                <template v-if="allowPracticeRetry && wrongQuestions.length">
                    Wrong sums are listed first — read the question, pick an option, and try again. Your original score stays the same.
                </template>
                <template v-else>
                    Wrong tries are shown first, then the correct answer in order.
                </template>
            </p>
        </div>

        <article
            v-for="question in questions"
            :key="question.number"
            class="rounded-lg border bg-white p-4 shadow-sm"
            :class="question.needs_practice_retry ? 'border-rose-200' : 'border-gray-200'"
        >
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-sm font-semibold text-indigo-600">Question {{ question.number }}</p>
                <span
                    v-if="question.needs_practice_retry"
                    class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-800"
                >
                    Try again
                </span>
            </div>

            <div class="mt-3">
                <QuestionBody
                    :question-text="question.question_text"
                    :diagram-url="question.diagram_url"
                    :use-html="question.type === 'mcq'"
                />
            </div>

            <div v-if="question.attempts?.length" class="mt-4 space-y-2">
                <div
                    v-for="(row, index) in question.attempts"
                    :key="index"
                    class="rounded-md border px-3 py-2 text-sm"
                    :class="row.is_correct ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'"
                >
                    <p class="font-semibold">{{ row.label }}</p>
                    <p v-if="row.answer" class="mt-1 whitespace-pre-wrap">{{ row.answer }}</p>
                </div>
            </div>

            <div
                v-if="allowPracticeRetry && question.needs_practice_retry && getPracticeState(question.question_id).status !== 'correct'"
                class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50/60 p-4"
            >
                <p class="text-sm font-semibold text-indigo-900">Try this sum again</p>
                <p class="mt-1 text-xs text-indigo-800">Pick the option you think is correct.</p>

                <div v-if="getPracticeState(question.question_id).status === 'wrong'" class="mt-3 rounded-md bg-rose-100 px-3 py-2 text-sm text-rose-900">
                    {{ getPracticeState(question.question_id).message }}
                </div>

                <div v-if="isFillInBlank(question)" class="mt-3 space-y-3">
                    <p v-if="question.answer_format_label" class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        {{ question.answer_format_label }}
                    </p>
                    <TextInput
                        v-model="blankInputs[practiceKey(question.question_id)]"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        class="block w-full max-w-xs text-lg"
                        :placeholder="answerPlaceholder(question)"
                        :disabled="getPracticeState(question.question_id).status === 'checking'"
                        @keyup.enter="submitBlankRetry(question)"
                    />
                    <PrimaryButton
                        type="button"
                        :disabled="getPracticeState(question.question_id).status === 'checking' || !(blankInputs[practiceKey(question.question_id)] ?? '').trim()"
                        @click="submitBlankRetry(question)"
                    >
                        {{ getPracticeState(question.question_id).status === 'checking' ? 'Checking…' : 'Check answer' }}
                    </PrimaryButton>
                </div>

                <div v-else class="mt-3 space-y-2">
                    <button
                        v-for="(opt, optIndex) in question.options"
                        :key="opt.id"
                        type="button"
                        class="flex w-full items-start gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 text-left text-sm transition hover:border-indigo-300 hover:bg-white"
                        :disabled="getPracticeState(question.question_id).status === 'checking'"
                        @click="submitMcqRetry(question, opt.id)"
                    >
                        <McqOptionLine :index="optIndex" :text="opt.option_text" />
                    </button>
                </div>
            </div>

            <div
                v-if="getPracticeState(question.question_id).status === 'correct'"
                class="mt-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900"
            >
                <p class="font-semibold">{{ getPracticeState(question.question_id).message }}</p>
            </div>

            <div v-if="showCorrectAnswer(question)" class="mt-3 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-800">
                <p class="font-semibold text-slate-900">Correct answer</p>
                <p class="mt-1 whitespace-pre-wrap">{{ showCorrectAnswer(question) }}</p>
            </div>

            <div v-if="question.method_hint" class="mt-3 rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-900">
                <p class="font-semibold">Method</p>
                <p class="mt-1 whitespace-pre-wrap">{{ question.method_hint }}</p>
            </div>
        </article>
    </div>
</template>
