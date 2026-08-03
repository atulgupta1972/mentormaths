<script setup>
import McqOptionLine from '@/Components/McqOptionLine.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    session: {
        type: Object,
        required: true,
    },
    current: {
        type: Object,
        default: null,
    },
    progress_label: {
        type: String,
        default: '',
    },
});

const currentItem = ref(props.current);
const sessionState = ref(props.session);
const progressLabel = ref(props.progress_label);
const selectedOptionId = ref(null);
const submitting = ref(false);
const feedback = ref(null);
const disabledOptions = ref([]);

watch(
    () => props.current,
    (next) => {
        currentItem.value = next;
        resetQuestionState();
    },
);

const question = computed(() => currentItem.value?.question ?? null);

const resetQuestionState = () => {
    selectedOptionId.value = null;
    feedback.value = null;
    disabledOptions.value = [];
    submitting.value = false;
};

const selectOption = (optionId) => {
    if (submitting.value || feedback.value?.exhausted || feedback.value?.correct) {
        return;
    }

    selectedOptionId.value = optionId;
};

const submitAnswer = async () => {
    if (!selectedOptionId.value || !currentItem.value || submitting.value) {
        return;
    }

    submitting.value = true;

    try {
        const { data: payload } = await axios.post(
            route('student.formula-drill.answer', currentItem.value.id),
            { option_id: selectedOptionId.value },
        );

        feedback.value = payload;

        if (!payload.correct && !payload.exhausted) {
            disabledOptions.value = [...disabledOptions.value, selectedOptionId.value];
            selectedOptionId.value = null;
            submitting.value = false;

            if (payload.session?.current) {
                currentItem.value = payload.session.current;
            }

            return;
        }

        sessionState.value = payload.session.session;
        progressLabel.value = payload.session.progress_label;

        if (payload.session_complete) {
            setTimeout(() => {
                router.visit(route('dashboard'));
            }, 1200);

            return;
        }

        setTimeout(() => {
            currentItem.value = payload.session.current;
            resetQuestionState();
        }, payload.exhausted ? 1800 : 900);
    } catch (error) {
        feedback.value = {
            error: error.response?.data?.message || 'Could not save answer. Try again.',
        };
        submitting.value = false;
    }
};

const optionClass = (optionId) => {
    if (feedback.value?.correct && optionId === selectedOptionId.value) {
        return 'border-green-500 bg-green-50 ring-2 ring-green-300';
    }

    if (feedback.value?.exhausted && optionId === feedback.value.correct_option_id) {
        return 'border-green-500 bg-green-50 ring-2 ring-green-300';
    }

    if (disabledOptions.value.includes(optionId)) {
        return 'border-rose-200 bg-rose-50 opacity-70';
    }

    if (selectedOptionId.value === optionId) {
        return 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-300';
    }

    return 'border-gray-200 bg-white hover:border-indigo-300';
};
</script>

<template>
    <Head title="Daily formula drill" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Daily formula drill</h2>
                <p class="text-sm text-gray-500">
                    Complete {{ sessionState.questions_total }} formulas to unlock today&apos;s work
                    <span v-if="sessionState.pool_size"> · Pool of {{ sessionState.pool_size }}</span>
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="mb-4 flex items-center justify-between rounded-lg bg-indigo-600 px-4 py-3 text-white shadow">
                    <p class="text-sm font-semibold">Progress</p>
                    <p class="font-mono text-lg font-bold">{{ progressLabel }}</p>
                </div>

                <div v-if="question" class="overflow-hidden rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <QuestionBody :question-text="question.question_text" />

                    <div class="mt-5 space-y-2">
                        <button
                            v-for="(option, optIndex) in question.options"
                            :key="option.id"
                            type="button"
                            class="block w-full rounded-lg border p-3 text-left transition"
                            :class="optionClass(option.id)"
                            :disabled="disabledOptions.includes(option.id) || submitting || feedback?.correct || feedback?.exhausted"
                            @click="selectOption(option.id)"
                        >
                            <McqOptionLine :index="optIndex" :text="option.option_text" />
                        </button>
                    </div>

                    <div v-if="feedback?.error" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                        {{ feedback.error }}
                    </div>

                    <div
                        v-else-if="feedback?.correct"
                        class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900"
                    >
                        Correct — next formula coming up…
                    </div>

                    <div
                        v-else-if="feedback?.exhausted"
                        class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                    >
                        <p class="font-medium">Maximum attempts reached.</p>
                        <p v-if="question.explanation" class="mt-1">{{ question.explanation }}</p>
                        <p class="mt-2 text-xs">This formula will come back in a future drill.</p>
                    </div>

                    <div
                        v-else-if="feedback && !feedback.correct"
                        class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                    >
                        Not quite — {{ feedback.attempts_left }} attempt{{ feedback.attempts_left === 1 ? '' : 's' }} left. Try again.
                    </div>

                    <div class="mt-6 flex items-center justify-between">
                        <p class="text-xs text-gray-500">
                            {{ currentItem.attempts_left }} attempt{{ currentItem.attempts_left === 1 ? '' : 's' }} left on this formula
                        </p>
                        <PrimaryButton
                            type="button"
                            :disabled="!selectedOptionId || submitting || feedback?.correct || feedback?.exhausted"
                            @click="submitAnswer"
                        >
                            {{ submitting ? 'Checking…' : 'Submit answer' }}
                        </PrimaryButton>
                    </div>
                </div>

                <div v-else class="rounded-xl bg-white p-8 text-center text-gray-500 shadow-sm">
                    Loading next formula…
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
