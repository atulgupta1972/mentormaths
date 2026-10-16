<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    pack: { type: Object, required: true },
    play: { type: Object, required: true },
    back_url: { type: String, required: true },
    restart_url: { type: String, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const selected = ref(null);
const submitting = ref(false);

const feedback = computed(() => props.play?.feedback || null);
const showFeedback = computed(() => Boolean(feedback.value));
const awaitingNext = computed(() => showFeedback.value && props.play?.status === 'in_progress');

const submitAnswer = () => {
    if (selected.value === null || selected.value === undefined) {
        return;
    }
    submitting.value = true;
    router.post(
        route('admin.algebra-cards.try-answer', { pack: props.pack.key }),
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
        route('admin.algebra-cards.try', { pack: props.pack.key }),
        { continue: 1 },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="`Try — ${pack.label}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Try as student
                <span class="font-normal text-slate-500">· {{ pack.label }}</span>
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-5 px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    Admin check mode — this is how the student run looks. Answers are not saved to any student.
                </div>

                <p v-if="flash.error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    {{ flash.error }}
                </p>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold text-slate-900">
                            <span v-if="play.status === 'completed'">Pack complete</span>
                            <span v-else>Card {{ play.index }} of {{ play.total }}</span>
                        </p>
                        <p class="text-sm text-slate-600">
                            Score {{ play.score }} / {{ play.answered || 0 }}
                            <span v-if="pack.level"> · {{ pack.level }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link
                            :href="restart_url"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Restart
                        </Link>
                        <Link
                            :href="back_url"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            ← Cards
                        </Link>
                    </div>
                </div>

                <!-- Feedback for previous answer -->
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
                    <p v-if="feedback.explanation" class="mt-2 text-xs text-slate-600">
                        {{ feedback.explanation }}
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

                <!-- Completed -->
                <div
                    v-if="play.status === 'completed'"
                    class="rounded-xl border border-emerald-200 bg-white p-6 text-center shadow-sm"
                >
                    <p class="text-lg font-semibold text-emerald-900">Done — {{ play.score }} / {{ play.total }}</p>
                    <p class="mt-1 text-sm text-slate-600">This is the end of the student-style run for this pack.</p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <Link
                            :href="restart_url"
                            class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800"
                        >
                            Try again
                        </Link>
                        <Link
                            :href="back_url"
                            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50"
                        >
                            Back to pack
                        </Link>
                    </div>
                </div>

                <!-- Current card (hide while reviewing feedback before continue, or show always) -->
                <div
                    v-if="play.card && !awaitingNext"
                    class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">
                            Card {{ play.card.id }} · {{ play.card.title }}
                        </p>
                        <p class="mt-2 text-base text-slate-900">{{ play.card.question }}</p>
                    </div>

                    <a
                        v-if="play.card.diagram_url"
                        :href="play.card.diagram_url"
                        target="_blank"
                        class="block overflow-hidden rounded-lg border border-slate-200 bg-slate-50 p-2"
                    >
                        <img
                            :src="play.card.diagram_url"
                            :alt="play.card.title"
                            class="mx-auto max-h-[28rem] w-full object-contain"
                        >
                    </a>

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

                    <div class="flex justify-end">
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
