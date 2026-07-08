<script setup>
import QuestionBody from '@/Components/QuestionBody.vue';
import { computed } from 'vue';

const props = defineProps({
    questions: {
        type: Array,
        default: () => [],
    },
});

const hasReview = computed(() => props.questions.length > 0);
</script>

<template>
    <div v-if="hasReview" class="space-y-4">
        <div>
            <h3 class="text-sm font-semibold text-gray-800">Review sums</h3>
            <p class="mt-1 text-xs text-gray-500">
                Wrong tries are shown first, then the correct answer in order.
            </p>
        </div>

        <article
            v-for="question in questions"
            :key="question.number"
            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
        >
            <p class="text-sm font-semibold text-indigo-600">Question {{ question.number }}</p>

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

            <div v-if="question.correct_answer" class="mt-3 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-800">
                <p class="font-semibold text-slate-900">Correct answer</p>
                <p class="mt-1 whitespace-pre-wrap">{{ question.correct_answer }}</p>
            </div>

            <div v-if="question.method_hint" class="mt-3 rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-900">
                <p class="font-semibold">Method</p>
                <p class="mt-1 whitespace-pre-wrap">{{ question.method_hint }}</p>
            </div>
        </article>
    </div>
</template>
