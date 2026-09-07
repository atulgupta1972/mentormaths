<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConceptAngleMapBoard from '@/Components/ConceptAngleMapBoard.vue';
import ConceptTurnClockBoard from '@/Components/ConceptTurnClockBoard.vue';
import ConceptMathText from '@/Components/ConceptMathText.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { conceptAnswersMatch } from '@/utils/conceptAnswerMatch';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    uploaderMode: { type: Boolean, default: false },
    singleCard: { type: Boolean, default: false },
    chapter: { type: Object, required: true },
    path: { type: Object, required: true },
});

const cards = computed(() => (Array.isArray(props.path?.cards) ? props.path.cards : []));
const index = ref(0);
const finished = ref(false);
const selectedOption = ref(null);
const typedAnswer = ref('');
const selectedAngle = ref(null);
const selectedFace = ref(null);
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
    return Math.round((index.value / total.value) * 100);
});

const currentQuestions = computed(() => (Array.isArray(current.value?.questions) ? current.value.questions : []));
const currentPrompts = computed(() => (Array.isArray(current.value?.prompts) ? current.value.prompts : []));
const currentQuestion = computed(() => currentQuestions.value[questionIndex.value] || null);
const currentPrompt = computed(() => currentPrompts.value[questionIndex.value] || null);

const isAngleMap = computed(() => current.value?.type === 'angle_map');
const isTurnClock = computed(() => current.value?.type === 'turn_clock');
const isTeach = computed(() => current.value?.type === 'teach');
const isCheck = computed(() => current.value?.type === 'check');
const turnKind = computed(() => currentPrompt.value?.kind || 'tap_face');

const typeLabel = computed(() => {
    if (isAngleMap.value) {
        return 'Angle map';
    }
    if (isTurnClock.value) {
        return 'Turn clock';
    }
    if (isTeach.value) {
        return 'Teach';
    }
    return 'Check';
});

const resetAnswerState = () => {
    selectedOption.value = null;
    typedAnswer.value = '';
    selectedAngle.value = null;
    selectedFace.value = null;
    revealed.value = false;
    questionIndex.value = 0;
};

watch(index, () => {
    resetAnswerState();
});

const optionLetter = (i) => String.fromCharCode(65 + i);

const isCurrentCorrect = computed(() => {
    if (isAngleMap.value) {
        return selectedAngle.value === currentPrompt.value?.correct;
    }
    if (isTurnClock.value) {
        if (turnKind.value === 'tap_face') {
            return selectedFace.value === currentPrompt.value?.correct;
        }
        if (turnKind.value === 'fill_degrees') {
            return conceptAnswersMatch(typedAnswer.value, currentPrompt.value?.correct_answer);
        }
        return selectedOption.value === currentPrompt.value?.correct_index;
    }
    const q = currentQuestion.value;
    if (!q) {
        return false;
    }
    if (q.question_type === 'mcq') {
        return selectedOption.value === q.correct_index;
    }
    return conceptAnswersMatch(typedAnswer.value, q.correct_answer, q.accepted_answers);
});

const checkAnswer = () => {
    if (isAngleMap.value) {
        if (selectedAngle.value === null) {
            return;
        }
        revealed.value = true;
        return;
    }
    if (isTurnClock.value) {
        if (turnKind.value === 'tap_face' && selectedFace.value === null) {
            return;
        }
        if (turnKind.value === 'fill_degrees' && !typedAnswer.value.trim()) {
            return;
        }
        if (turnKind.value === 'mcq_turn' && selectedOption.value === null) {
            return;
        }
        revealed.value = true;
        return;
    }
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

const onAngleSelect = (id) => {
    if (revealed.value) {
        return;
    }
    selectedAngle.value = id;
};

const onFaceSelect = (id) => {
    if (revealed.value) {
        return;
    }
    selectedFace.value = id;
};

const goNext = () => {
    if ((isAngleMap.value || isTurnClock.value) && currentPrompts.value.length > 1 && questionIndex.value < currentPrompts.value.length - 1) {
        questionIndex.value += 1;
        selectedAngle.value = null;
        selectedFace.value = null;
        selectedOption.value = null;
        typedAnswer.value = '';
        revealed.value = false;
        return;
    }

    if (isCheck.value && currentQuestions.value.length > 1 && questionIndex.value < currentQuestions.value.length - 1) {
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

const cardBorderClass = computed(() => {
    if (isTeach.value) {
        return 'border-sky-200';
    }
    if (isAngleMap.value) {
        return 'border-violet-200';
    }
    if (isTurnClock.value) {
        return 'border-teal-200';
    }
    return 'border-amber-200';
});

const turnCheckDisabled = computed(() => {
    if (turnKind.value === 'tap_face') {
        return selectedFace.value === null;
    }
    if (turnKind.value === 'fill_degrees') {
        return !typedAnswer.value.trim();
    }
    return selectedOption.value === null;
});

const inferDemoTurn = (prompt) => {
    if (!prompt) {
        return null;
    }
    if (prompt.turn) {
        return String(prompt.turn);
    }
    const fromDeg = { 90: '1/4', 180: '1/2', 270: '3/4', 360: '1' };
    if (prompt.correct_answer != null && fromDeg[String(prompt.correct_answer)]) {
        return fromDeg[String(prompt.correct_answer)];
    }
    const opts = Array.isArray(prompt.options) ? prompt.options : [];
    const chosen = String(opts[prompt.correct_index] || '').toLowerCase();
    if (chosen.includes('1/4') || chosen.includes('quarter')) return '1/4';
    if (chosen.includes('1/2') || chosen.includes('half')) return '1/2';
    if (chosen.includes('3/4')) return '3/4';
    if (chosen.includes('full')) return '1';
    return null;
};

const clockDemoTurn = computed(() => {
    if (turnKind.value === 'tap_face') {
        return null;
    }
    return inferDemoTurn(currentPrompt.value);
});
</script>

<template>
    <Head :title="`${singleCard ? 'Run card' : 'Run concepts'} · ${chapter.label}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        {{ singleCard ? 'Run this card' : 'Run concepts' }}
                    </h2>
                    <p class="text-sm text-gray-500">
                        {{ chapter.grade_name }} · {{ chapter.book_name }} ({{ chapter.book_code }})
                        · {{ chapter.label }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 text-sm">
                    <Link :href="chapter.edit_url" class="text-indigo-600 hover:underline">Edit path</Link>
                    <Link
                        v-if="singleCard && chapter.can_run_full && chapter.play_url"
                        :href="chapter.play_url"
                        class="font-semibold text-emerald-700 hover:underline"
                    >
                        Run all concepts
                    </Link>
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
                    <p class="text-lg font-semibold text-emerald-950">
                        {{ singleCard ? 'Card complete' : 'Concept path complete' }}
                    </p>
                    <p class="mt-1 text-sm text-emerald-900">
                        <template v-if="singleCard">
                            You finished step {{ cards[0]?.step || 1 }} for {{ chapter.label }}.
                        </template>
                        <template v-else>
                            You walked through {{ total }} cards for {{ chapter.label }}.
                        </template>
                    </p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <PrimaryButton type="button" @click="restart">Run again</PrimaryButton>
                        <Link
                            v-if="singleCard && chapter.can_run_full && chapter.play_url"
                            :href="chapter.play_url"
                        >
                            <SecondaryButton type="button">Run all concepts</SecondaryButton>
                        </Link>
                        <Link :href="chapter.edit_url">
                            <SecondaryButton type="button">Back to cards</SecondaryButton>
                        </Link>
                    </div>
                </div>

                <div
                    v-else-if="current"
                    class="rounded-xl border bg-white p-5 shadow-sm"
                    :class="cardBorderClass"
                >
                    <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">
                        Step {{ current.step }} · {{ typeLabel }}
                        <span v-if="current.topic" class="font-medium normal-case text-slate-600"> · {{ current.topic }}</span>
                    </p>
                    <h3 class="mt-2 text-xl font-semibold text-slate-900">{{ current.title }}</h3>

                    <template v-if="isTeach">
                        <p class="mt-4 text-base leading-relaxed text-slate-800">
                            <ConceptMathText :text="current.body" />
                        </p>
                        <img
                            v-if="current.diagram_url"
                            :src="current.diagram_url"
                            :alt="current.title"
                            class="mt-4 max-h-72 w-full rounded-md border border-slate-200 bg-white object-contain"
                        >
                        <p v-if="current.example" class="mt-3 rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-950">
                            <span class="font-semibold">Example:</span>
                            <ConceptMathText :text="current.example" />
                        </p>
                        <p v-if="current.common_mistake" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950">
                            <span class="font-semibold">Common mistake:</span>
                            <ConceptMathText :text="current.common_mistake" />
                        </p>
                        <div class="mt-5">
                            <PrimaryButton type="button" @click="goNext">
                                {{ index >= total - 1 ? 'Finish' : 'Next' }}
                            </PrimaryButton>
                        </div>
                    </template>

                    <template v-else-if="isAngleMap">
                        <p v-if="current.body" class="mt-3 text-sm text-slate-700">
                            <ConceptMathText :text="current.body" />
                        </p>
                        <div class="mt-4 space-y-3">
                            <p v-if="currentPrompts.length > 1" class="text-xs font-semibold uppercase tracking-wide text-violet-800">
                                Prompt {{ questionIndex + 1 }} of {{ currentPrompts.length }}
                            </p>
                            <p class="text-base font-semibold text-slate-900">
                                {{ currentPrompt?.prompt || 'Tap the matching angle.' }}
                            </p>

                            <ConceptAngleMapBoard
                                :highlight="currentPrompt?.highlight ?? null"
                                :selected="selectedAngle"
                                :correct="currentPrompt?.correct ?? null"
                                :revealed="revealed"
                                @select="onAngleSelect"
                            />

                            <p
                                v-if="revealed"
                                class="rounded-md px-3 py-2 text-sm"
                                :class="isCurrentCorrect ? 'bg-emerald-50 text-emerald-900' : 'bg-rose-50 text-rose-900'"
                            >
                                <span class="font-semibold">{{ isCurrentCorrect ? 'Correct' : 'Not quite' }}.</span>
                                <span v-if="currentPrompt?.explanation">
                                    <ConceptMathText :text="currentPrompt.explanation" />
                                </span>
                                <span v-else-if="!isCurrentCorrect"> Try ∠{{ currentPrompt?.correct }}.</span>
                            </p>

                            <div class="flex flex-wrap gap-2 pt-1">
                                <PrimaryButton
                                    v-if="!revealed"
                                    type="button"
                                    :disabled="selectedAngle === null"
                                    @click="checkAnswer"
                                >
                                    Check
                                </PrimaryButton>
                                <PrimaryButton v-else type="button" @click="goNext">
                                    {{ index >= total - 1 && questionIndex >= currentPrompts.length - 1 ? 'Finish' : 'Next' }}
                                </PrimaryButton>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="isTurnClock">
                        <p v-if="current.body" class="mt-3 text-sm text-slate-700">
                            <ConceptMathText :text="current.body" />
                        </p>
                        <div class="mt-4 space-y-3">
                            <p v-if="currentPrompts.length > 1" class="text-xs font-semibold uppercase tracking-wide text-teal-800">
                                Prompt {{ questionIndex + 1 }} of {{ currentPrompts.length }}
                            </p>
                            <p class="text-base font-semibold text-slate-900">
                                {{ currentPrompt?.prompt || 'Make the turn on the clock.' }}
                            </p>

                            <ConceptTurnClockBoard
                                :start="currentPrompt?.start ?? 12"
                                :selected="turnKind === 'tap_face' ? selectedFace : null"
                                :correct="turnKind === 'tap_face' ? (currentPrompt?.correct ?? null) : null"
                                :revealed="turnKind === 'tap_face' ? revealed : false"
                                :interactive="turnKind === 'tap_face'"
                                :demo-turn="clockDemoTurn"
                                :show-degrees="turnKind !== 'fill_degrees' || revealed"
                                :show-turn-label="turnKind !== 'mcq_turn' || revealed"
                                @select="onFaceSelect"
                            />

                            <div v-if="turnKind === 'fill_degrees'">
                                <input
                                    v-model="typedAnswer"
                                    type="text"
                                    inputmode="numeric"
                                    class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                    :disabled="revealed"
                                    placeholder="Type degrees, e.g. 90"
                                    @keyup.enter="!revealed && checkAnswer()"
                                >
                            </div>

                            <div v-else-if="turnKind === 'mcq_turn'" class="space-y-2">
                                <button
                                    v-for="(opt, optIndex) in (currentPrompt?.options || [])"
                                    :key="optIndex"
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded-md border px-3 py-2 text-left text-sm"
                                    :class="{
                                        'border-teal-400 bg-teal-50': selectedOption === optIndex && !revealed,
                                        'border-emerald-500 bg-emerald-50': revealed && optIndex === currentPrompt.correct_index,
                                        'border-rose-400 bg-rose-50': revealed && selectedOption === optIndex && optIndex !== currentPrompt.correct_index,
                                        'border-slate-200 bg-white hover:bg-slate-50': selectedOption !== optIndex && !(revealed && optIndex === currentPrompt.correct_index),
                                    }"
                                    :disabled="revealed"
                                    @click="selectedOption = optIndex"
                                >
                                    <span class="font-semibold text-slate-500">{{ optionLetter(optIndex) }}.</span>
                                    <span>{{ opt }}</span>
                                </button>
                            </div>

                            <p
                                v-if="revealed"
                                class="rounded-md px-3 py-2 text-sm"
                                :class="isCurrentCorrect ? 'bg-emerald-50 text-emerald-900' : 'bg-rose-50 text-rose-900'"
                            >
                                <span class="font-semibold">{{ isCurrentCorrect ? 'Correct' : 'Not quite' }}.</span>
                                <span v-if="currentPrompt?.explanation">
                                    <ConceptMathText :text="currentPrompt.explanation" />
                                </span>
                                <span v-else-if="!isCurrentCorrect && turnKind === 'tap_face'"> Try {{ currentPrompt?.correct }}.</span>
                                <span v-else-if="!isCurrentCorrect && turnKind === 'fill_degrees'"> Answer: {{ currentPrompt?.correct_answer }}°.</span>
                            </p>

                            <div class="flex flex-wrap gap-2 pt-1">
                                <PrimaryButton
                                    v-if="!revealed"
                                    type="button"
                                    :disabled="turnCheckDisabled"
                                    @click="checkAnswer"
                                >
                                    Check
                                </PrimaryButton>
                                <PrimaryButton v-else type="button" @click="goNext">
                                    {{ index >= total - 1 && questionIndex >= currentPrompts.length - 1 ? 'Finish' : 'Next' }}
                                </PrimaryButton>
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <img
                            v-if="current.diagram_url"
                            :src="current.diagram_url"
                            :alt="current.title"
                            class="mt-4 max-h-64 w-full rounded-md border border-slate-200 bg-white object-contain"
                        >
                        <div v-if="currentQuestion" class="mt-4 space-y-3">
                            <p v-if="currentQuestions.length > 1" class="text-xs font-semibold uppercase tracking-wide text-amber-800">
                                Question {{ questionIndex + 1 }} of {{ currentQuestions.length }}
                            </p>
                            <p class="text-base font-medium text-slate-900">
                                <ConceptMathText :text="currentQuestion.question" />
                            </p>

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
                                    <ConceptMathText :text="opt" />
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
                                <span v-if="currentQuestion.explanation">
                                    <ConceptMathText :text="currentQuestion.explanation" />
                                </span>
                                <span v-else-if="currentQuestion.question_type === 'fill_blank'">
                                    Answer: <ConceptMathText :text="currentQuestion.correct_answer" />
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
