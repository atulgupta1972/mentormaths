<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import WorksheetPdfViewer from '@/Components/WorksheetPdfViewer.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    attempt: Object,
    practiceSet: Object,
    questions: Array,
    referencePdfUrl: { type: String, default: null },
});

const answers = ref({});
const elapsed = ref(0);
let timer = null;

const form = useForm({
    answers: {},
});

onMounted(() => {
    const started = new Date(props.attempt.started_at).getTime();
    const tick = () => {
        elapsed.value = Math.floor((Date.now() - started) / 1000);
    };
    tick();
    timer = setInterval(tick, 1000);
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
});

const selectOption = (questionId, optionId) => {
    answers.value[questionId] = optionId;
};

const formatTime = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
};

const submit = () => {
    form.answers = { ...answers.value };
    form.post(route('student.attempts.submit', props.attempt.id));
};

const allAnswered = () => props.questions.every((q) => answers.value[q.id]);
</script>

<template>
    <Head :title="practiceSet.display_title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">{{ practiceSet.topic_name }}</p>
                    <h2 class="text-xl font-semibold text-gray-800">{{ practiceSet.display_title }}</h2>
                </div>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-sm font-mono">{{ formatTime(elapsed) }}</span>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <WorksheetPdfViewer
                    v-if="referencePdfUrl"
                    :url="referencePdfUrl"
                />

                <div
                    v-for="(q, index) in questions"
                    :key="q.id"
                    class="rounded-lg bg-white p-5 shadow-sm"
                >
                    <p class="text-sm font-medium text-gray-500">Question {{ index + 1 }}</p>
                    <QuestionBody class="mt-2" :question-text="q.question_text" :diagram-url="q.diagram_url" />
                    <div class="mt-4 space-y-2">
                        <label
                            v-for="(opt, optIndex) in q.options"
                            :key="opt.id"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition"
                            :class="answers[q.id] === opt.id ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:bg-gray-50'"
                        >
                            <input
                                type="radio"
                                :name="`q-${q.id}`"
                                :value="opt.id"
                                :checked="answers[q.id] === opt.id"
                                class="mt-1"
                                @change="selectOption(q.id, opt.id)"
                            />
                            <McqOptionLine class="text-sm text-gray-800" :index="optIndex" :text="opt.option_text" />
                        </label>
                    </div>
                </div>

                <div class="sticky bottom-4 rounded-lg bg-white p-4 shadow-lg">
                    <p class="mb-3 text-sm text-gray-600">
                        {{ Object.keys(answers).length }} / {{ questions.length }} answered
                    </p>
                    <PrimaryButton :disabled="form.processing || !allAnswered()" @click="submit">
                        Submit practice set
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
