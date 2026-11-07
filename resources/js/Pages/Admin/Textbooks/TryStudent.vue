<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    chapter: { type: Object, required: true },
    play: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const selected = ref(null);
const typed = ref('');
const submitting = ref(false);

const question = computed(() => props.play.question);
const feedback = computed(() => props.play.feedback);
const isFillBlank = computed(() => question.value?.type === 'fill_blank');
const finished = computed(() => props.play.status === 'completed');

watch(question, () => {
    selected.value = null;
    typed.value = '';
});

const submit = () => {
    if (feedback.value || submitting.value) {
        return;
    }
    const payload = isFillBlank.value
        ? { answer_text: typed.value.trim() }
        : { option_index: selected.value };
    if (isFillBlank.value && !payload.answer_text) {
        return;
    }
    if (!isFillBlank.value && (selected.value === null || selected.value === undefined)) {
        return;
    }
    submitting.value = true;
    router.post(props.urls.answer, payload, {
        preserveScroll: true,
        onFinish: () => {
            submitting.value = false;
        },
    });
};

const optionClass = (index) => {
    if (feedback.value) {
        return 'border-slate-200 bg-slate-50 text-slate-700';
    }
    return selected.value === index
        ? 'border-indigo-500 bg-indigo-50'
        : 'border-slate-200 hover:border-indigo-300 hover:bg-slate-50';
};
</script>

<template>
    <Head :title="`Try as student — ${chapter.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Try as student
                <span class="font-normal text-slate-500">· {{ chapter.title }}</span>
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    Admin check. This is the student screen for
                    <span v-if="play.set_code" class="font-mono font-semibold">{{ play.set_code }}</span>
                    <span v-else>these questions</span>.
                    Nothing is saved to a scorecard.
                </div>

                <p v-if="flash.error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    {{ flash.error }}
                </p>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold text-slate-900">
                            <span v-if="finished">Set complete</span>
                            <span v-else>Question {{ play.index }} of {{ play.total }}</span>
                            <span v-if="question" class="font-normal text-slate-500"> · Q{{ question.number }}</span>
                        </p>
                        <p class="text-sm text-slate-600">
                            Score {{ play.score }} / {{ play.answered }}
                            <span class="text-slate-400"> · Q{{ play.from }}–{{ play.to }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="urls.restart" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            Restart
                        </Link>
                        <Link :href="urls.back" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            ← Chapter
                        </Link>
                    </div>
                </div>

                <div v-if="finished" class="rounded-xl border border-emerald-200 bg-white px-6 py-8 text-center shadow-sm">
                    <p class="text-lg font-semibold text-slate-900">{{ play.score }} correct out of {{ play.total }}</p>
                    <p class="mt-1 text-sm text-slate-600">This check is finished. Student scores were not changed.</p>
                    <div class="mt-4 flex justify-center gap-2">
                        <Link :href="urls.restart" class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-800">
                            Run again
                        </Link>
                        <Link :href="urls.back" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            Back to chapter
                        </Link>
                    </div>
                </div>

                <div v-else-if="question" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <p v-if="question.label" class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ question.label }}
                    </p>
                    <QuestionBody
                        :question-text="question.text"
                        :diagram-url="question.diagram_url"
                        enlarge-diagram
                    />

                    <form class="mt-5" @submit.prevent="submit">
                        <div v-if="isFillBlank" class="space-y-2">
                            <label class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                Your answer
                                <span v-if="question.answer_unit"> ({{ question.answer_unit }})</span>
                            </label>
                            <TextInput
                                v-model="typed"
                                type="text"
                                class="block w-full max-w-xs text-lg"
                                autocomplete="off"
                                :disabled="Boolean(feedback) || submitting"
                            />
                        </div>
                        <div v-else class="space-y-2">
                            <button
                                v-for="(option, index) in question.options"
                                :key="index"
                                type="button"
                                class="block w-full rounded-lg border p-3 text-left"
                                :class="optionClass(index)"
                                :disabled="Boolean(feedback) || submitting"
                                @click="selected = index"
                            >
                                <McqOptionLine :index="index" :text="option.text" />
                            </button>
                        </div>

                        <div
                            v-if="feedback"
                            class="mt-4 rounded-lg border px-4 py-3 text-sm"
                            :class="feedback.correct ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-rose-200 bg-rose-50 text-rose-950'"
                        >
                            <p class="font-semibold">{{ feedback.correct ? 'Correct' : 'Not quite' }}</p>
                            <p v-if="!feedback.correct" class="mt-1">
                                Answer: <strong>{{ feedback.expected }}</strong>
                            </p>
                            <p v-if="feedback.hint" class="mt-2 text-slate-800">{{ feedback.hint }}</p>
                            <p v-if="feedback.explanation" class="mt-1 text-slate-800">{{ feedback.explanation }}</p>
                        </div>

                        <div class="mt-5 flex flex-wrap gap-2">
                            <PrimaryButton
                                v-if="!feedback"
                                type="submit"
                                :disabled="submitting || (isFillBlank ? !typed.trim() : selected === null)"
                            >
                                {{ submitting ? 'Checking…' : 'Check answer' }}
                            </PrimaryButton>
                            <Link
                                v-else
                                :href="urls.next"
                                class="inline-flex items-center rounded-md bg-indigo-700 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-800"
                            >
                                Next question
                            </Link>
                            <SecondaryButton v-if="!feedback" type="button" @click="router.visit(urls.back)">
                                Stop
                            </SecondaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
