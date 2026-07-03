<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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

const setLabel = () => props.practiceSet.set_code || `Set ${props.practiceSet.set_number}`;

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
    <Head :title="setLabel()" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">{{ practiceSet.kind_label }}</p>
                    <h2 class="font-mono text-xl font-semibold text-gray-800">{{ setLabel() }}</h2>
                </div>
                <span class="rounded-full bg-gray-100 px-3 py-1 font-mono text-sm">{{ formatTime(elapsed) }}</span>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <WorksheetPdfViewer
                    v-if="referencePdfUrl"
                    :url="referencePdfUrl"
                />

                <div v-else class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-6 text-center text-sm text-gray-600">
                    Use your printed or shared worksheet for {{ setLabel() }}.
                </div>

                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-gray-800">Record your answers</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        Match each sum on your sheet to the row below. Only option letters are shown here.
                    </p>

                    <div class="mt-4 space-y-3">
                        <div
                            v-for="q in questions"
                            :key="q.id"
                            class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 px-4 py-3"
                        >
                            <span class="w-10 text-sm font-semibold text-gray-700">Q{{ q.number }}</span>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    v-for="opt in q.options"
                                    :key="opt.id"
                                    class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm transition"
                                    :class="answers[q.id] === opt.id ? 'border-indigo-500 bg-indigo-50 font-semibold text-indigo-800' : 'border-gray-200 hover:bg-gray-50'"
                                >
                                    <input
                                        type="radio"
                                        :name="`q-${q.id}`"
                                        :value="opt.id"
                                        :checked="answers[q.id] === opt.id"
                                        class="sr-only"
                                        @change="selectOption(q.id, opt.id)"
                                    />
                                    {{ opt.letter }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sticky bottom-4 rounded-lg bg-white p-4 shadow-lg">
                    <p class="mb-3 text-sm text-gray-600">
                        {{ Object.keys(answers).length }} / {{ questions.length }} answered
                    </p>
                    <PrimaryButton :disabled="form.processing || !allAnswered()" @click="submit">
                        Submit {{ practiceSet.kind_label === 'Test' ? 'test' : 'practice set' }}
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
