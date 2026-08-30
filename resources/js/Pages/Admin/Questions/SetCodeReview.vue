<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps({
    code: { type: String, default: '' },
    result: { type: Object, default: null },
    focusQuestionId: { type: Number, default: null },
    answerFormats: { type: Array, default: () => ['integer', 'decimal', 'fraction'] },
});

const page = usePage();
const searchCode = ref(props.code || '');
const editingId = ref(null);

const focusedOnly = computed(() => Boolean(props.focusQuestionId) || Boolean(props.result?.focused));
const visibleQuestions = computed(() => props.result?.questions || []);

const lookup = () => {
    router.get(route('admin.questions.set-code'), {
        code: searchCode.value.trim().toUpperCase(),
    }, {
        preserveState: false,
        replace: true,
    });
};

const blankForm = useForm({
    question_text: '',
    answer_format: 'integer',
    correct_answer: '',
    decimal_places: null,
    explanation: '',
    method_hint: '',
    difficulty: '',
});

const startEdit = (question) => {
    editingId.value = question.id;
    blankForm.clearErrors();
    blankForm.question_text = question.question_text || '';
    blankForm.answer_format = question.answer_format || 'integer';
    blankForm.correct_answer = question.correct_answer || '';
    blankForm.decimal_places = question.decimal_places ?? null;
    blankForm.explanation = question.explanation || '';
    blankForm.method_hint = question.method_hint || '';
    blankForm.difficulty = question.difficulty || '';
};

const cancelEdit = () => {
    editingId.value = null;
    blankForm.reset();
};

const applySuggestedAnswer = (question) => {
    if (!question?.answer_warning?.suggested_answer) {
        return;
    }

    blankForm.correct_answer = question.answer_warning.suggested_answer;
};

const saveEdit = (questionId) => {
    blankForm.patch(route('admin.questions.fill-blank.update', questionId), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
        },
    });
};

const deleteForm = useForm({});

const deleteQuestion = (question) => {
    if (!confirm('Delete this question from the set? Use this when the sum is irrelevant or too broken to fix. Students will not be asked to re-attempt it.')) {
        return;
    }

    deleteForm
        .transform(() => ({ return_to: 'set-code' }))
        .delete(route('admin.questions.destroy', question.id), {
            preserveScroll: true,
            onSuccess: () => {
                editingId.value = null;
            },
        });
};

const hasResult = computed(() => Boolean(props.result));

watch(
    () => props.code,
    (value) => {
        searchCode.value = value || '';
    },
);

onMounted(() => {
    const targetId = props.focusQuestionId || props.result?.focus_question_id;
    if (!targetId) {
        return;
    }
    const question = visibleQuestions.value.find((row) => Number(row.id) === Number(targetId));
    if (question?.type === 'fill_in_blank') {
        startEdit(question);
    }
});
</script>

<template>
    <Head title="Look up set code" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="route('admin.questions.index')" class="text-sm text-indigo-600">← Question bank</Link>
                    <h2 class="mt-1 text-xl font-semibold text-gray-800">
                        {{ focusedOnly ? 'Edit reported sum' : 'Look up set code' }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        <template v-if="focusedOnly">
                            Showing only the flagged question from {{ code || 'this set' }} — edit and save below.
                            <Link
                                v-if="code"
                                :href="route('admin.questions.set-code', { code })"
                                class="ml-1 font-medium text-indigo-700 hover:underline"
                            >
                                View full set
                            </Link>
                        </template>
                        <template v-else>
                            Enter a code like SF121 or S121 to review every question and correct AI-generated answers.
                        </template>
                    </p>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <form v-if="!focusedOnly" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm" @submit.prevent="lookup">
                    <label class="block text-sm font-medium text-gray-700">Set code</label>
                    <div class="mt-2 flex flex-wrap gap-3">
                        <TextInput
                            v-model="searchCode"
                            type="text"
                            class="block w-full max-w-xs font-mono uppercase"
                            placeholder="SF121"
                        />
                        <button
                            type="submit"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            Look up
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">SF = fill-in-blank practice · S = MCQ practice · T = chapter test</p>
                </form>

                <div v-if="page.props.flash?.success" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>

                <div v-if="code && !hasResult" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    No set or bank found for <span class="font-mono font-semibold">{{ code.toUpperCase() }}</span>.
                    Check the code, or package the question bank from the chapter hub first.
                </div>

                <div v-if="hasResult" class="space-y-4">
                    <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-mono text-3xl font-bold tracking-wide text-indigo-700">{{ result.set_code }}</p>
                                <p class="mt-2 text-sm text-gray-700">{{ result.scope_line }}</p>
                                <p class="mt-1 text-sm text-gray-600">
                                    {{ result.class_label }} · {{ result.kind_label }} · {{ result.tier_label }}
                                </p>
                                <p class="mt-1 text-sm font-medium" :class="result.is_bank ? 'text-amber-800' : 'text-emerald-800'">
                                    {{ result.status_label }}
                                </p>
                            </div>
                            <div class="text-right text-sm">
                                <p class="font-semibold text-gray-800">{{ result.questions_count }} questions</p>
                                <p v-if="result.is_fill_in_blank" class="mt-1 text-emerald-700">Fill-in-blank set</p>
                                <p v-else-if="result.is_written" class="mt-1 text-amber-800">Written sheet</p>
                                <p v-else class="mt-1 text-indigo-700">MCQ set</p>
                                <Link
                                    v-if="result.review_url"
                                    :href="result.review_url"
                                    class="mt-2 inline-block text-indigo-600 hover:underline"
                                >
                                    Open in question hub →
                                </Link>
                                <a
                                    v-if="result.written_pdf_url"
                                    :href="result.written_pdf_url"
                                    target="_blank"
                                    rel="noopener"
                                    class="mt-1 block text-indigo-600 hover:underline"
                                >
                                    Open written PDF →
                                </a>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="result.questions_restored"
                        class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950"
                    >
                        This set was packaged with no questions attached. Matching questions from the topic bank are shown below and have been linked to
                        <span class="font-mono font-semibold">{{ result.set_code }}</span>.
                    </div>

                    <div
                        v-if="result.sibling_set"
                        class="rounded-lg border border-indigo-100 bg-white px-4 py-3 text-sm text-gray-700"
                    >
                        <template v-if="result.is_fill_in_blank">
                            MCQ practice for this chapter is
                        </template>
                        <template v-else>
                            Fill-in-blank practice for this chapter is
                        </template>
                        <Link
                            :href="route('admin.questions.set-code', { code: result.sibling_set.set_code })"
                            class="font-mono font-semibold text-indigo-700 hover:underline"
                        >
                            {{ result.sibling_set.set_code }}
                        </Link>
                        ({{ result.sibling_set.questions_count }} questions).
                    </div>

                    <div class="overflow-x-auto bg-white shadow-sm sm:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">#</th>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Question</th>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Type</th>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Answer</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <template v-for="(question, index) in visibleQuestions" :key="question.id">
                                    <tr :id="`question-${question.id}`">
                                        <td class="px-4 py-3 align-top text-gray-500">{{ index + 1 }}</td>
                                        <td class="px-4 py-3 align-top">
                                            <QuestionBody
                                                :question-text="question.question_text"
                                                :diagram-url="question.diagram_url"
                                                :compact="true"
                                            />
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                                :class="question.type === 'fill_in_blank' ? 'bg-emerald-100 text-emerald-800' : 'bg-indigo-100 text-indigo-800'"
                                            >
                                                {{ question.type_label }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <template v-if="question.type === 'fill_in_blank'">
                                                <p class="font-mono font-semibold text-gray-900">{{ question.correct_answer || '—' }}</p>
                                                <p class="text-xs text-gray-500">{{ question.answer_format || '—' }}</p>
                                                <p
                                                    v-if="question.answer_warning"
                                                    class="mt-1 text-xs font-medium text-amber-800"
                                                >
                                                    ⚠ {{ question.answer_warning.message }}
                                                </p>
                                            </template>
                                            <template v-else>
                                                <p class="font-mono font-semibold text-gray-900">{{ question.correct_answer || '—' }}</p>
                                                <ul v-if="question.options?.length" class="mt-1 space-y-0.5 text-xs text-gray-500">
                                                    <li v-for="(opt, optIndex) in question.options" :key="opt.letter">
                                                        <McqOptionLine :index="optIndex" :text="opt.option_text" />
                                                        <span v-if="opt.is_correct" class="ml-1 font-semibold text-emerald-700">✓</span>
                                                    </li>
                                                </ul>
                                            </template>
                                        </td>
                                        <td class="px-4 py-3 align-top text-right">
                                            <button
                                                v-if="question.type === 'fill_in_blank'"
                                                type="button"
                                                class="text-indigo-600 hover:text-indigo-800"
                                                @click="startEdit(question)"
                                            >
                                                {{ editingId === question.id ? 'Editing…' : 'Edit' }}
                                            </button>
                                            <Link
                                                v-else
                                                :href="route('admin.questions.edit', question.id)"
                                                class="text-indigo-600 hover:text-indigo-800"
                                            >
                                                Edit
                                            </Link>
                                        </td>
                                    </tr>
                                    <tr v-if="editingId === question.id && question.type === 'fill_in_blank'">
                                        <td colspan="5" class="bg-emerald-50 px-4 py-4">
                                            <div class="grid gap-4 lg:grid-cols-2">
                                                <div class="space-y-3">
                                                    <div>
                                                        <label class="text-xs font-medium uppercase text-gray-500">Question text</label>
                                                        <textarea
                                                            v-model="blankForm.question_text"
                                                            rows="3"
                                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                                                        />
                                                    </div>
                                                    <div class="grid gap-3 sm:grid-cols-3">
                                                        <div>
                                                            <label class="text-xs font-medium uppercase text-gray-500">Format</label>
                                                            <select v-model="blankForm.answer_format" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                                                <option v-for="format in answerFormats" :key="format" :value="format">
                                                                    {{ format }}
                                                                </option>
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <label class="text-xs font-medium uppercase text-gray-500">Correct answer</label>
                                                            <TextInput v-model="blankForm.correct_answer" class="mt-1 block w-full font-mono" />
                                                        </div>
                                                        <div>
                                                            <label class="text-xs font-medium uppercase text-gray-500">Decimal places</label>
                                                            <TextInput v-model="blankForm.decimal_places" type="number" min="0" max="6" class="mt-1 block w-full" />
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="space-y-3">
                                                    <div>
                                                        <label class="text-xs font-medium uppercase text-gray-500">Method hint</label>
                                                        <textarea v-model="blankForm.method_hint" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" />
                                                    </div>
                                                    <div>
                                                        <label class="text-xs font-medium uppercase text-gray-500">Explanation</label>
                                                        <textarea v-model="blankForm.explanation" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" />
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-4 flex flex-wrap gap-2">
                                                <div
                                                    v-if="question.answer_warning"
                                                    class="mb-2 w-full rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950"
                                                >
                                                    {{ question.answer_warning.message }}
                                                    <button
                                                        type="button"
                                                        class="ml-2 font-semibold text-indigo-700 hover:underline"
                                                        @click="applySuggestedAnswer(question)"
                                                    >
                                                        Use {{ question.answer_warning.suggested_answer }}
                                                    </button>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                                                    :disabled="blankForm.processing || deleteForm.processing"
                                                    @click="saveEdit(question.id)"
                                                >
                                                    {{ blankForm.processing ? 'Saving…' : 'Save correction' }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="rounded-md border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                                                    :disabled="blankForm.processing || deleteForm.processing"
                                                    @click="deleteQuestion(question)"
                                                >
                                                    {{ deleteForm.processing ? 'Deleting…' : 'Delete question' }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                                    @click="cancelEdit"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="visibleQuestions.length === 0">
                                    <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-500">
                                        <p class="font-medium text-gray-700">No questions in this set.</p>
                                        <p class="mt-1">
                                            Package questions from the chapter hub, or check the sibling set code
                                            (SF for fill-in-blank, S for MCQ).
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
