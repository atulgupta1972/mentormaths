<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    pack: { type: Object, required: true },
    play: { type: Object, required: true },
    required_today: { type: Boolean, default: false },
    next_url: { type: String, required: true },
    next_label: { type: String, default: 'Continue' },
    restart_url: { type: String, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const selected = ref(null);
const submitting = ref(false);

const feedback = computed(() => props.play?.feedback || null);
const showFeedback = computed(() => Boolean(feedback.value));
const awaitingNext = computed(() => showFeedback.value && props.play?.status === 'in_progress');
const isTeach = computed(() => props.play?.phase === 'teach');
const isQuiz = computed(() => props.play?.phase === 'quiz');

const goToQuiz = () => {
    router.get(
        route('student.algebra-cards.show'),
        { to_quiz: 1 },
        { preserveScroll: true },
    );
};

const submitAnswer = () => {
    if (selected.value === null || selected.value === undefined) {
        return;
    }
    submitting.value = true;
    router.post(
        route('student.algebra-cards.answer'),
        { option_index: selected.value },
        {
            preserveScroll: true,
            onFinish: () => {
                submitting.value = false;
                selected.value = null;
            },
        },
    );
};

const continueNext = () => {
    router.get(
        route('student.algebra-cards.show'),
        { continue: 1 },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="`Algebra cards · ${pack.label}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Algebra cards
                <span class="font-normal text-slate-500">· {{ pack.label }}</span>
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-5 px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-950">
                    Today’s series practice is <strong>algebra cards</strong>
                    (mensuration match comes on the next practice day).
                    First see Wrong &amp; Right, then answer the question.
                </div>

                <p v-if="flash.error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    {{ flash.error }}
                </p>
                <p v-if="flash.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {{ flash.success }}
                </p>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold text-slate-900">
                            <span v-if="play.status === 'completed'">Pack complete</span>
                            <span v-else>Card {{ play.index }} of {{ play.total }}</span>
                            <span v-if="isTeach" class="ml-2 font-normal text-indigo-700">· Step 1: Learn</span>
                            <span v-else-if="isQuiz" class="ml-2 font-normal text-indigo-700">· Step 2: Question</span>
                        </p>
                        <p class="text-sm text-slate-600">
                            Score {{ play.score }} / {{ play.answered || 0 }}
                            <span v-if="pack.level"> · {{ pack.level }}</span>
                        </p>
                    </div>
                </div>

                <div
                    v-if="showFeedback"
                    class="rounded-xl border px-5 py-4 shadow-sm"
                    :class="feedback.correct
                        ? 'border-emerald-200 bg-emerald-50'
                        : 'border-rose-200 bg-rose-50'"
                >
                    <p class="text-sm font-semibold" :class="feedback.correct ? 'text-emerald-900' : 'text-rose-900'">
                        {{ feedback.correct ? 'Correct' : 'Not quite' }}
                        <span v-if="play.feedback_card" class="font-normal">
                            · Card {{ play.feedback_card.id }}
                        </span>
                    </p>
                    <p v-if="!feedback.correct" class="mt-1 text-sm text-rose-900">
                        Right option: {{ feedback.correct_option }}
                    </p>
                    <p v-if="feedback.method_hint" class="mt-2 text-sm text-slate-700">
                        {{ feedback.method_hint }}
                    </p>
                    <button
                        v-if="awaitingNext"
                        type="button"
                        class="mt-3 rounded-md bg-indigo-700 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-800"
                        @click="continueNext"
                    >
                        Next card →
                    </button>
                </div>

                <div
                    v-if="play.status === 'completed'"
                    class="rounded-xl border border-emerald-200 bg-white p-6 text-center shadow-sm"
                >
                    <p class="text-lg font-semibold text-emerald-900">
                        Done — {{ play.score }} / {{ play.total }}
                    </p>
                    <p class="mt-2 text-sm text-slate-600">
                        Next practice day you will get mensuration match instead.
                    </p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <Link
                            :href="next_url"
                            class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800"
                        >
                            {{ next_label }}
                        </Link>
                    </div>
                </div>

                <div
                    v-if="play.card && isTeach && !awaitingNext"
                    class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">
                            Card {{ play.card.id }} · {{ play.card.title }}
                        </p>
                        <p class="mt-1 text-sm text-slate-600">
                            First see what is wrong, then how to do it correctly.
                        </p>
                    </div>

                    <img
                        v-if="play.card.diagram_url"
                        :src="play.card.diagram_url"
                        :alt="play.card.title"
                        class="mx-auto w-full max-w-2xl rounded-lg border border-slate-100 object-contain"
                    >

                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="rounded-md bg-indigo-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-800"
                            @click="goToQuiz"
                        >
                            Next: question →
                        </button>
                    </div>
                </div>

                <div
                    v-if="play.card && isQuiz && !awaitingNext"
                    class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">
                            Card {{ play.card.id }} · Question
                        </p>
                        <p class="mt-2 text-base font-medium text-slate-900">{{ play.card.question }}</p>
                    </div>

                    <div
                        v-if="play.card.check_lines?.length"
                        class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3"
                    >
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Steps to judge</p>
                        <ul class="mt-2 space-y-1.5 text-sm text-slate-800">
                            <li v-for="(line, i) in play.card.check_lines" :key="i" class="font-mono">
                                {{ line }}
                            </li>
                        </ul>
                    </div>

                    <div class="space-y-2">
                        <label
                            v-for="(option, index) in play.card.options"
                            :key="index"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3 text-sm"
                            :class="selected === index
                                ? 'border-indigo-400 bg-indigo-50'
                                : 'border-slate-200 hover:border-indigo-200'"
                        >
                            <input
                                v-model="selected"
                                type="radio"
                                class="mt-1"
                                :value="index"
                                :disabled="submitting"
                            >
                            <span>
                                <span class="font-semibold text-slate-500">{{ String.fromCharCode(65 + index) }}.</span>
                                {{ option }}
                            </span>
                        </label>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <button
                            type="button"
                            class="text-sm font-medium text-slate-600 hover:text-slate-900"
                            @click="router.get(route('student.algebra-cards.show'), { to_teach: 1 }, { preserveScroll: true })"
                        >
                            ← Back to wrong / right
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-indigo-700 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-800 disabled:opacity-50"
                            :disabled="submitting || selected === null"
                            @click="submitAnswer"
                        >
                            {{ submitting ? 'Checking…' : 'Check answer' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
