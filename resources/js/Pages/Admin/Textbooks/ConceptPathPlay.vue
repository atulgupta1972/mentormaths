<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    uploaderMode: { type: Boolean, default: false },
    chapter: { type: Object, required: true },
    path: { type: Object, required: true },
});

const cards = computed(() => (Array.isArray(props.path?.cards) ? props.path.cards : []));
const index = ref(0);
const finished = ref(false);
const selectedOption = ref(null);
const typedAnswer = ref('');
const revealed = ref(false);
const questionIndex = ref(0);

const current = computed(() => cards.value[index.value] || null);
const total = computed(() => cards.value.length);
const progressPct = computed(() => {
    if (!total.value) {
        return 0;
    }
    if (finished.value) {
        return 100;
    }
    return Math.round(((index.value) / total.value) * 100);
});

const currentQuestions = computed(() => (Array.isArray(current.value?.questions) ? current.value.questions : []));
const currentQuestion = computed(() => currentQuestions.value[questionIndex.value] || null);

const resetAnswerState = () => {
    selectedOption.value = null;
    typedAnswer.value = '';
    revealed.value = false;
    questionIndex.value = 0;
};

watch(index, () => {
    resetAnswerState();
});

const optionLetter = (i) => String.fromCharCode(65 + i);

const normalizeAnswer = (value) => String(value ?? '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '');

const isCurrentCorrect = computed(() => {
    const q = currentQuestion.value;
    if (!q) {
        return false;
    }
    if (q.question_type === 'mcq') {
        return selectedOption.value === q.correct_index;
    }
    return normalizeAnswer(typedAnswer.value) === normalizeAnswer(q.correct_answer);
});

const checkAnswer = () => {
    if (!currentQuestion.value) {
        return;
    }
    if (currentQuestion.value.question_type === 'mcq' && selectedOption.value === null) {
        return;
    }
    if (currentQuestion.value.question_type === 'fill_blank' && !typedAnswer.value.trim()) {
        return;
    }
    revealed.value = true;
};

const goNext = () => {
    if (current.value?.type === 'check' && currentQuestions.value.length > 1 && questionIndex.value < currentQuestions.value.length - 1) {
        questionIndex.value += 1;
        selectedOption.value = null;
        typedAnswer.value = '';
        revealed.value = false;
        return;
    }

    if (index.value >= total.value - 1) {
        finished.value = true;
        return;
    }

    index.value += 1;
};

const restart = () => {
    index.value = 0;
    finished.value = false;
    resetAnswerState();
};
</script>

<template>
    <Head :title="`Run concepts · ${chapter.label}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Run concepts</h2>
                    <p class="text-sm text-gray-500">
                        {{ chapter.grade_name }} · {{ chapter.book_name }} ({{ chapter.book_code }})
                        · {{ chapter.label }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 text-sm">
                    <Link :href="chapter.edit_url" class="text-indigo-600 hover:underline">Edit path</Link>
                    <Link :href="chapter.builder_url" class="text-indigo-600 hover:underline">Concept builder</Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl space-y-4 px-4 sm:px-6">
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="mb-2 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <span>{{ path.chapter_title || chapter.title }}</span>
                        <span>{{ finished ? total : Math.min(index + 1, total) }} / {{ total }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: `${progressPct}%` }" />
                    </div>
                </div>

                <div v-if="finished" class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-center shadow-sm">
                    <p class="text-lg font-semibold text-emerald-950">Concept path complete</p>
                    <p class="mt-1 text-sm text-emerald-900">
                        You walked through {{ total }} cards for {{ chapter.label }}.
                    </p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <PrimaryButton type="button" @click="restart">Run again</PrimaryButton>
                        <Link :href="chapter.builder_url">
                            <SecondaryButton type="button">Back to builder</SecondaryButton>
                        </Link>
                    </div>
                </div>

                <div
                    v-else-if="current"
                    class="rounded-xl border bg-white p-5 shadow-sm"
                    :class="current.type === 'teach' ? 'border-sky-200' : 'border-amber-200'"
                >
                    <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">
                        Step {{ current.step }} · {{ current.type === 'teach' ? 'Teach' : 'Check' }}
                        <span v-if="current.topic" class="font-medium normal-case text-slate-600"> · {{ current.topic }}</span>
                    </p>
                    <h3 class="mt-2 text-xl font-semibold text-slate-900">{{ current.title }}</h3>

                    <template v-if="current.type === 'teach'">
                        <p class="mt-4 whitespace-pre-wrap text-base leading-relaxed text-slate-800">{{ current.body }}</p>
                        <p v-if="current.example" class="mt-3 rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-950">
                            <span class="font-semibold">Example:</span> {{ current.example }}
                        </p>
                        <p v-if="current.common_mistake" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950">
                            <span class="font-semibold">Common mistake:</span> {{ current.common_mistake }}
                        </p>
                        <div class="mt-5">
                            <PrimaryButton type="button" @click="goNext">
                                {{ index >= total - 1 ? 'Finish' : 'Next' }}
                            </PrimaryButton>
                        </div>
                    </template>

                    <template v-else>
                        <div v-if="currentQuestion" class="mt-4 space-y-3">
                            <p v-if="currentQuestions.length > 1" class="text-xs font-semibold uppercase tracking-wide text-amber-800">
                                Question {{ questionIndex + 1 }} of {{ currentQuestions.length }}
                            </p>
                            <p class="text-base font-medium text-slate-900">{{ currentQuestion.question }}</p>

                            <div v-if="currentQuestion.question_type === 'mcq'" class="space-y-2">
                                <button
                                    v-for="(opt, optIndex) in (currentQuestion.options || [])"
                                    :key="optIndex"
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded-md border px-3 py-2 text-left text-sm"
                                    :class="{
                                        'border-indigo-400 bg-indigo-50': selectedOption === optIndex && !revealed,
                                        'border-emerald-500 bg-emerald-50': revealed && optIndex === currentQuestion.correct_index,
                                        'border-rose-400 bg-rose-50': revealed && selectedOption === optIndex && optIndex !== currentQuestion.correct_index,
                                        'border-slate-200 bg-white hover:bg-slate-50': selectedOption !== optIndex && !(revealed && optIndex === currentQuestion.correct_index),
                                    }"
                                    :disabled="revealed"
                                    @click="selectedOption = optIndex"
                                >
                                    <span class="font-semibold text-slate-500">{{ optionLetter(optIndex) }}.</span>
                                    <span>{{ opt }}</span>
                                </button>
                            </div>

                            <div v-else>
                                <input
                                    v-model="typedAnswer"
                                    type="text"
                                    class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    :disabled="revealed"
                                    placeholder="Type your answer"
                                    @keyup.enter="!revealed && checkAnswer()"
                                >
                            </div>

                            <p
                                v-if="revealed"
                                class="rounded-md px-3 py-2 text-sm"
                                :class="isCurrentCorrect ? 'bg-emerald-50 text-emerald-900' : 'bg-rose-50 text-rose-900'"
                            >
                                <span class="font-semibold">{{ isCurrentCorrect ? 'Correct' : 'Not quite' }}.</span>
                                <span v-if="currentQuestion.explanation"> {{ currentQuestion.explanation }}</span>
                                <span v-else-if="currentQuestion.question_type === 'fill_blank'">
                                    Answer: {{ currentQuestion.correct_answer }}
                                </span>
                            </p>

                            <div class="flex flex-wrap gap-2 pt-1">
                                <PrimaryButton
                                    v-if="!revealed"
                                    type="button"
                                    :disabled="currentQuestion.question_type === 'mcq' ? selectedOption === null : !typedAnswer.trim()"
                                    @click="checkAnswer"
                                >
                                    Check
                                </PrimaryButton>
                                <PrimaryButton v-else type="button" @click="goNext">
                                    {{ index >= total - 1 && questionIndex >= currentQuestions.length - 1 ? 'Finish' : 'Next' }}
                                </PrimaryButton>
                            </div>
                        </div>
                        <p v-else class="mt-4 text-sm text-slate-600">No questions on this check card.</p>
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
