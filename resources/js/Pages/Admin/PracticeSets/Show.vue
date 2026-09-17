<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    practiceSet: Object,
});

const page = usePage();
const form = useForm({});
const selectedIds = ref([]);
const converting = ref(false);
const approving = ref(false);

const flashSuccess = computed(() => page.props.flash?.success || null);
const flashError = computed(() => page.props.flash?.error || null);

const convertibleQuestions = computed(() =>
    (props.practiceSet.questions || []).filter((q) => q.can_convert_to_fill_blank),
);

const selectedConvertibleCount = computed(() =>
    selectedIds.value.filter((id) => convertibleQuestions.value.some((q) => q.id === id)).length,
);

const toggleQuestion = (question) => {
    if (!question.can_convert_to_fill_blank) {
        return;
    }

    if (selectedIds.value.includes(question.id)) {
        selectedIds.value = selectedIds.value.filter((id) => id !== question.id);
    } else {
        selectedIds.value = [...selectedIds.value, question.id];
    }
};

const selectAllConvertible = () => {
    selectedIds.value = convertibleQuestions.value.map((q) => q.id);
};

const clearSelection = () => {
    selectedIds.value = [];
};

const convertSelected = () => {
    if (selectedConvertibleCount.value === 0) {
        return;
    }

    converting.value = true;
    router.post(route('admin.practice-sets.convert-fill-blank', props.practiceSet.id), {
        question_ids: selectedIds.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            converting.value = false;
            selectedIds.value = [];
        },
    });
};

const approveExamPrep = () => {
    if (!props.practiceSet.is_exam_prep) {
        return;
    }

    approving.value = true;
    router.post(route('admin.exam-plans.exam-prep.approve', props.practiceSet.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            approving.value = false;
        },
    });
};

const destroy = () => {
    if (confirm('Delete this practice set?')) {
        form.delete(route('admin.practice-sets.destroy', props.practiceSet.id));
    }
};
</script>

<template>
    <Head :title="practiceSet.display_title || practiceSet.title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">{{ practiceSet.display_title || practiceSet.title }}</h2>
                    <p class="text-sm text-gray-500">
                        <span v-if="practiceSet.set_code" class="font-mono font-semibold text-indigo-700">{{ practiceSet.set_code }}</span>
                        <span v-if="practiceSet.tier_tagline"> · {{ practiceSet.tier_tagline }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button
                        v-if="practiceSet.is_exam_prep && practiceSet.status !== 'published'"
                        type="button"
                        class="rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-white hover:bg-emerald-800 disabled:opacity-50"
                        :disabled="approving"
                        @click="approveExamPrep"
                    >
                        {{ approving ? 'Publishing…' : 'Publish for student' }}
                    </button>
                    <Link
                        v-if="practiceSet.syllabus_topic_id"
                        :href="route('admin.practice-sets.topics.show', practiceSet.syllabus_topic_id)"
                        class="text-sm text-indigo-600"
                    >
                        Topic hub
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="flashSuccess"
                    class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900"
                >
                    {{ flashSuccess }}
                </div>
                <div
                    v-if="flashError"
                    class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900"
                >
                    {{ flashError }}
                </div>

                <div class="rounded-lg bg-white p-4 text-sm text-gray-600 shadow-sm">
                    Status: <strong class="capitalize">{{ practiceSet.status }}</strong>
                    · {{ practiceSet.questions.length }} questions
                    <span v-if="practiceSet.is_exam_prep" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold uppercase text-amber-900">Exam prep</span>
                    <span v-if="practiceSet.topic"> · {{ practiceSet.topic.chapter?.name }} — {{ practiceSet.topic.name }}</span>
                </div>

                <div
                    v-if="convertibleQuestions.length"
                    class="rounded-lg border border-amber-300 bg-amber-50 p-4 shadow-sm"
                >
                    <p class="text-sm font-semibold text-amber-950">Convert MCQ → fill-in-the-blank</p>
                    <p class="mt-1 text-xs text-amber-900/80">
                        Tick questions whose correct answer is a whole number, decimal, or fraction.
                        They become fill-in-the-blank for this bank question.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-amber-400 bg-white px-2.5 py-1.5 text-xs font-medium text-amber-950 hover:bg-amber-100"
                            @click="selectAllConvertible"
                        >
                            Select all convertible ({{ convertibleQuestions.length }})
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                            @click="clearSelection"
                        >
                            Clear
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-amber-800 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-white hover:bg-amber-900 disabled:opacity-50"
                            :disabled="converting || selectedConvertibleCount === 0"
                            @click="convertSelected"
                        >
                            {{ converting ? 'Converting…' : `Convert selected (${selectedConvertibleCount})` }}
                        </button>
                    </div>
                </div>

                <div class="space-y-4">
                    <div
                        v-for="(q, index) in practiceSet.questions"
                        :key="q.id"
                        class="rounded-lg bg-white p-4 shadow-sm"
                        :class="q.can_convert_to_fill_blank && selectedIds.includes(q.id) ? 'ring-2 ring-amber-400' : ''"
                    >
                        <div class="flex items-start gap-3">
                            <label
                                v-if="q.can_convert_to_fill_blank"
                                class="mt-0.5 flex shrink-0 cursor-pointer items-center gap-1.5 text-xs font-medium text-amber-900"
                            >
                                <input
                                    type="checkbox"
                                    class="rounded border-amber-400 text-amber-700"
                                    :checked="selectedIds.includes(q.id)"
                                    @change="toggleQuestion(q)"
                                >
                                FIB
                            </label>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-medium text-gray-500">Q{{ index + 1 }}</p>
                                    <span
                                        class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                                        :class="q.type === 'fill_in_blank' ? 'bg-emerald-100 text-emerald-800' : 'bg-indigo-100 text-indigo-800'"
                                    >
                                        {{ q.type_label }}
                                    </span>
                                    <span
                                        v-if="q.can_convert_to_fill_blank"
                                        class="text-[11px] font-medium text-amber-800"
                                    >
                                        · convertible (answer {{ q.correct_answer }})
                                    </span>
                                    <span
                                        v-else-if="q.type === 'mcq' && q.convert_block_reason"
                                        class="text-[11px] text-gray-400"
                                    >
                                        · {{ q.convert_block_reason }}
                                    </span>
                                </div>
                                <div class="mt-1">
                                    <QuestionBody :question-text="q.question_text" :diagram-url="q.diagram_url" />
                                </div>

                                <template v-if="q.type === 'fill_in_blank'">
                                    <p class="mt-2 text-sm text-gray-700">
                                        Answer:
                                        <span class="font-mono font-semibold text-emerald-800">{{ q.correct_answer || '—' }}</span>
                                        <span v-if="q.answer_format" class="text-xs text-gray-500"> ({{ q.answer_format }})</span>
                                    </p>
                                </template>
                                <ul v-else class="mt-2 space-y-1 text-sm">
                                    <li
                                        v-for="(opt, optIndex) in q.options"
                                        :key="opt.id"
                                        :class="opt.is_correct ? 'font-semibold text-green-700' : 'text-gray-700'"
                                    >
                                        <McqOptionLine :index="optIndex" :text="opt.option_text" />
                                        <span v-if="opt.is_correct"> ✓</span>
                                    </li>
                                </ul>
                                <p v-if="q.explanation" class="mt-2 text-xs text-gray-500">{{ q.explanation }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="button" class="text-sm text-red-600 hover:text-red-800" @click="destroy">
                    Delete practice set
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
