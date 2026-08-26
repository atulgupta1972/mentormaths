<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import QuestionDiagram from '@/Components/QuestionDiagram.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';

const props = defineProps({
    correction: { type: Object, required: true },
    question: { type: Object, required: true },
});

const page = usePage();
const diagramPreview = ref(props.question.diagram_url || null);
const isFib = computed(() => props.question.is_fill_in_blank);

const form = useForm({
    question_text: props.question.question_text || '',
    explanation: props.question.explanation || '',
    method_hint: props.question.method_hint || '',
    difficulty: props.question.difficulty || '',
    options: (props.question.options || []).map((opt) => ({
        option_text: opt.option_text,
        is_correct: !!opt.is_correct,
    })),
    answer_format: props.question.blank_answer?.answer_format || 'integer',
    correct_answer: props.question.blank_answer?.correct_answer || '',
    decimal_places: props.question.blank_answer?.decimal_places ?? '',
    diagram: null,
    remove_diagram: false,
});

const autoResize = (event) => {
    const el = event?.target ?? event;
    if (!el) {
        return;
    }
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
};

onMounted(() => {
    nextTick(() => {
        document.querySelectorAll('.fix-field').forEach((el) => autoResize(el));
    });
});

const setCorrect = (index) => {
    form.options.forEach((opt, i) => {
        opt.is_correct = i === index;
    });
};

const onDiagramSelected = (event) => {
    const file = event.target.files?.[0];
    form.diagram = file || null;
    form.remove_diagram = false;
    diagramPreview.value = file ? URL.createObjectURL(file) : null;
};

const removeDiagram = () => {
    form.diagram = null;
    form.remove_diagram = true;
    diagramPreview.value = null;
};

const submit = () => {
    form.transform((data) => {
        const formData = new FormData();
        formData.append('_method', 'PUT');
        formData.append('question_text', data.question_text);
        formData.append('explanation', data.explanation || '');
        formData.append('method_hint', data.method_hint || '');
        formData.append('difficulty', data.difficulty || '');

        if (isFib.value) {
            formData.append('answer_format', data.answer_format);
            formData.append('correct_answer', data.correct_answer);
            if (data.decimal_places !== '' && data.decimal_places != null) {
                formData.append('decimal_places', data.decimal_places);
            }
        } else {
            data.options.forEach((opt, index) => {
                formData.append(`options[${index}][option_text]`, opt.option_text);
                formData.append(`options[${index}][is_correct]`, opt.is_correct ? '1' : '0');
            });
        }

        if (data.diagram) {
            formData.append('diagram', data.diagram);
        }
        if (data.remove_diagram) {
            formData.append('remove_diagram', '1');
        }

        return formData;
    }).post(route('content.corrections.update', props.correction.id), {
        forceFormData: true,
    });
};

const deleteForm = useForm({});

const deleteQuestion = () => {
    if (!confirm('Delete this question? Use when it is irrelevant or too broken to fix. Students will not be asked to re-attempt it.')) {
        return;
    }

    deleteForm.delete(route('content.corrections.destroy', props.correction.id));
};
</script>

<template>
    <Head title="Fix reported sum" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-rose-700">Student reported misprint / incomplete</p>
                    <h2 class="text-xl font-semibold text-gray-800">
                        Fix sum
                        <span v-if="correction.question_number">Q{{ correction.question_number }}</span>
                    </h2>
                </div>
                <Link :href="route('content.tasks.index')" class="text-sm font-medium text-indigo-700 hover:underline">
                    Back to my tasks
                </Link>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-3xl space-y-4 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.error" class="rounded-md bg-rose-50 p-3 text-sm text-rose-900">
                    {{ page.props.flash.error }}
                </div>

                <div v-if="correction.remark" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                    <p class="font-semibold">What to fix</p>
                    <p class="mt-1 whitespace-pre-wrap">{{ correction.remark }}</p>
                </div>

                <form class="space-y-4 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="submit">
                    <div>
                        <InputLabel value="Question text" />
                        <textarea
                            v-model="form.question_text"
                            rows="3"
                            class="fix-field mt-1 w-full rounded-md border-gray-300"
                            required
                            @input="autoResize"
                        />
                        <p v-if="form.errors.question_text" class="mt-1 text-sm text-rose-600">{{ form.errors.question_text }}</p>
                    </div>

                    <div>
                        <InputLabel value="Diagram (optional)" />
                        <p class="mt-1 text-xs text-gray-500">
                            Add or replace a diagram if the student said it was missing. PNG/JPG/WebP, max 5 MB.
                        </p>
                        <QuestionDiagram v-if="diagramPreview" :url="diagramPreview" class="mt-2" />
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <input type="file" accept="image/*" class="text-sm" @change="onDiagramSelected" />
                            <SecondaryButton v-if="diagramPreview" type="button" @click="removeDiagram">
                                Remove diagram
                            </SecondaryButton>
                        </div>
                    </div>

                    <template v-if="isFib">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Answer format" />
                                <select v-model="form.answer_format" class="mt-1 w-full rounded-md border-gray-300" required>
                                    <option value="integer">Integer</option>
                                    <option value="decimal">Decimal</option>
                                    <option value="fraction">Fraction</option>
                                    <option value="text">Text</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Correct answer" />
                                <input
                                    v-model="form.correct_answer"
                                    type="text"
                                    class="mt-1 w-full rounded-md border-gray-300"
                                    required
                                />
                            </div>
                        </div>
                        <div v-if="form.answer_format === 'decimal'">
                            <InputLabel value="Decimal places (optional)" />
                            <input
                                v-model="form.decimal_places"
                                type="number"
                                min="0"
                                max="6"
                                class="mt-1 w-40 rounded-md border-gray-300"
                            />
                        </div>
                    </template>

                    <template v-else>
                        <div>
                            <InputLabel value="Options (tick the correct one)" />
                            <div class="mt-2 space-y-2">
                                <div
                                    v-for="(opt, index) in form.options"
                                    :key="index"
                                    class="flex items-start gap-3 rounded-md border border-gray-200 p-3"
                                >
                                    <input
                                        type="radio"
                                        name="correct"
                                        class="mt-2"
                                        :checked="opt.is_correct"
                                        @change="setCorrect(index)"
                                    />
                                    <textarea
                                        v-model="opt.option_text"
                                        rows="1"
                                        class="fix-field flex-1 rounded-md border-gray-300"
                                        required
                                        @input="autoResize"
                                    />
                                </div>
                            </div>
                        </div>
                    </template>

                    <div>
                        <InputLabel value="Explanation (optional)" />
                        <textarea
                            v-model="form.explanation"
                            rows="2"
                            class="fix-field mt-1 w-full rounded-md border-gray-300"
                            @input="autoResize"
                        />
                    </div>

                    <div>
                        <InputLabel value="Method hint (optional)" />
                        <textarea
                            v-model="form.method_hint"
                            rows="2"
                            class="fix-field mt-1 w-full rounded-md border-gray-300"
                            @input="autoResize"
                        />
                    </div>

                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <PrimaryButton :disabled="form.processing || deleteForm.processing">
                            {{ form.processing ? 'Saving…' : 'Save & return to student' }}
                        </PrimaryButton>
                        <button
                            type="button"
                            class="rounded-md border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                            :disabled="form.processing || deleteForm.processing"
                            @click="deleteQuestion"
                        >
                            {{ deleteForm.processing ? 'Deleting…' : 'Delete question' }}
                        </button>
                        <p class="w-full text-xs text-gray-500">
                            Save returns the sum to the student’s revise list. Delete removes it when the sum is irrelevant or unfixable.
                        </p>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
