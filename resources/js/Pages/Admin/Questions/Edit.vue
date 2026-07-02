<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { nextTick, onMounted } from 'vue';

const props = defineProps({
    question: Object,
});

const form = useForm({
    question_text: props.question.question_text,
    explanation: props.question.explanation || '',
    difficulty: props.question.difficulty || '',
    options: props.question.options.map((opt) => ({
        option_text: opt.option_text,
        is_correct: opt.is_correct,
    })),
});

const autoResize = (event) => {
    const el = event?.target ?? event;
    if (!el) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
};

const resizeMcqFields = () => {
    nextTick(() => {
        document.querySelectorAll('.mcq-field').forEach((el) => autoResize(el));
    });
};

onMounted(resizeMcqFields);

const setCorrect = (index) => {
    form.options.forEach((opt, i) => {
        opt.is_correct = i === index;
    });
};

const submit = () => {
    form.put(route('admin.questions.update', props.question.id));
};

const destroy = () => {
    if (confirm('Delete this question?')) {
        form.delete(route('admin.questions.destroy', props.question.id));
    }
};
</script>

<template>
    <Head title="Edit question" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">Edit MCQ</h2>
                <Link :href="route('admin.questions.topics.show', props.question.syllabus_topic_id)" class="text-sm text-indigo-600">Back</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="submit">
                    <div>
                        <InputLabel value="Question" />
                        <textarea
                            v-model="form.question_text"
                            rows="2"
                            class="mcq-field mt-1 w-full rounded-md border-gray-300"
                            required
                            @input="autoResize"
                        />
                    </div>

                    <div>
                        <InputLabel value="Options (click letter for correct answer)" />
                        <div v-for="(opt, index) in form.options" :key="index" class="mt-2 flex items-start gap-2">
                            <button
                                type="button"
                                class="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                :class="opt.is_correct ? 'bg-green-600 text-white' : 'bg-gray-200'"
                                @click="setCorrect(index)"
                            >
                                {{ String.fromCharCode(65 + index) }}
                            </button>
                            <textarea
                                v-model="opt.option_text"
                                rows="2"
                                class="mcq-field flex-1 rounded-md border-gray-300 text-sm"
                                required
                                @input="autoResize"
                            />
                        </div>
                    </div>

                    <div>
                        <InputLabel value="Explanation" />
                        <textarea
                            v-model="form.explanation"
                            rows="2"
                            class="mcq-field mt-1 w-full rounded-md border-gray-300"
                            @input="autoResize"
                        />
                    </div>

                    <div>
                        <InputLabel value="Difficulty" />
                        <select v-model="form.difficulty" class="mt-1 rounded-md border-gray-300">
                            <option value="">—</option>
                            <option value="Easy">Easy</option>
                            <option value="Medium">Medium</option>
                            <option value="Hard">Hard</option>
                        </select>
                    </div>

                    <div class="flex gap-3">
                        <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                        <DangerButton type="button" @click="destroy">Delete</DangerButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.mcq-field {
    resize: vertical;
    min-height: 2.5rem;
    line-height: 1.4;
    overflow: hidden;
}
</style>
