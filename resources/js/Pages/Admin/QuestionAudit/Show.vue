<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    worksheet: Object,
    chapter: Object,
    gradeLevel: Object,
    board: Object,
    audit: Object,
    findings: { type: Array, default: () => [] },
    answerFormats: { type: Array, default: () => [] },
});

const page = usePage();
const running = ref(false);
const editingId = ref(null);

const chapterSetsUrl = props.chapter
    ? route('admin.question-audit.chapters.show', props.chapter.id)
    : route('admin.question-audit.index');

const groupedFindings = computed(() => {
    const groups = {};

    props.findings.forEach((finding) => {
        const key = finding.question_id;
        if (!groups[key]) {
            groups[key] = {
                question_id: finding.question_id,
                question_number: finding.question_number,
                question_text: finding.question_text,
                type_label: finding.type_label,
                edit_url: finding.edit_url,
                can_inline_edit: finding.can_inline_edit,
                answer_format: finding.answer_format,
                decimal_places: finding.decimal_places,
                explanation: finding.explanation,
                method_hint: finding.method_hint,
                difficulty: finding.difficulty,
                stored_answer: finding.stored_answer,
                issues: [],
            };
        }

        groups[key].issues.push(finding);
    });

    return Object.values(groups).sort((a, b) => a.question_number - b.question_number);
});

const blankForm = useForm({
    question_text: '',
    answer_format: 'integer',
    correct_answer: '',
    decimal_places: null,
    explanation: '',
    method_hint: '',
    difficulty: '',
});

const startEdit = (group) => {
    editingId.value = group.question_id;
    blankForm.clearErrors();
    blankForm.question_text = group.question_text || '';
    blankForm.answer_format = group.answer_format || 'integer';
    blankForm.correct_answer = group.stored_answer || group.current_value || '';
    blankForm.decimal_places = group.decimal_places ?? null;
    blankForm.explanation = group.explanation || '';
    blankForm.method_hint = group.method_hint || '';
    blankForm.difficulty = group.difficulty || '';
};

const cancelEdit = () => {
    editingId.value = null;
    blankForm.reset();
};

const saveEdit = (questionId) => {
    blankForm.patch(route('admin.questions.fill-blank.update', questionId), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
        },
    });
};

const runAudit = () => {
    running.value = true;
    router.post(route('admin.question-audit.worksheets.run', props.worksheet.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            running.value = false;
        },
    });
};

const formatWhen = (value) => {
    if (!value) return '—';
    return new Date(value).toLocaleString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const suggestedAnswer = (issues) => {
    const mismatch = issues.find((issue) => issue.issue_type === 'answer_mismatch');
    return mismatch?.suggested_answer ?? null;
};
</script>

<template>
    <Head :title="`${worksheet.set_code} — Audit`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link :href="chapterSetsUrl" class="text-sm text-indigo-600">
                        ← Ch {{ chapter?.chapter_number }} {{ chapter?.name }}
                    </Link>
                    <div class="mt-1 flex flex-wrap items-center gap-3">
                        <span class="font-mono text-2xl font-bold text-indigo-600">{{ worksheet.set_code }}</span>
                        <span
                            v-if="audit"
                            class="rounded-full px-3 py-1 text-xs font-medium"
                            :class="audit.status === 'clean' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                        >
                            {{ audit.status === 'clean' ? 'Clean' : `${audit.issue_count} issue${audit.issue_count === 1 ? '' : 's'}` }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ worksheet.kind_label }} · {{ worksheet.tier_label }} · {{ worksheet.questions_count }} questions
                        <span v-if="worksheet.topic_name"> · {{ worksheet.topic_name }}</span>
                    </p>
                    <p v-if="audit" class="mt-1 text-xs text-gray-500">
                        Audited {{ formatWhen(audit.audited_at) }}
                        <span v-if="audit.audited_by"> by {{ audit.audited_by }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <PrimaryButton type="button" :disabled="running" @click="runAudit">
                        {{ running ? 'Auditing…' : audit ? 'Re-run audit' : 'Run audit' }}
                    </PrimaryButton>
                    <Link
                        :href="route('admin.questions.sets.show', worksheet.id)"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        Open set
                    </Link>
                    <Link
                        :href="route('admin.questions.set-code', { code: worksheet.set_code })"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        Set code review
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 p-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 p-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div v-if="!audit" class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center">
                    <p class="text-sm text-gray-600">This set has not been audited yet.</p>
                    <PrimaryButton type="button" class="mt-4" :disabled="running" @click="runAudit">
                        {{ running ? 'Auditing…' : 'Run audit now' }}
                    </PrimaryButton>
                </div>

                <div v-else-if="audit.status === 'clean'" class="rounded-xl border border-green-200 bg-green-50 p-6 text-sm text-green-900">
                    No issues found in the latest audit. You can re-run audit after making changes.
                </div>

                <div v-else class="space-y-4">
                    <p class="text-sm text-gray-600">
                        Fix issues below, then re-run audit to mark the set clean.
                    </p>

                    <article
                        v-for="group in groupedFindings"
                        :id="`question-${group.question_id}`"
                        :key="group.question_id"
                        class="overflow-hidden rounded-xl border border-red-200 bg-white shadow-sm"
                    >
                        <div class="border-b border-red-100 bg-red-50/60 px-5 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-red-800">
                                        Q{{ group.question_number }} · {{ group.type_label }}
                                    </p>
                                    <div class="mt-2 max-w-3xl">
                                        <QuestionBody :question-text="group.question_text" :compact="true" />
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <Link
                                        v-if="group.edit_url"
                                        :href="group.edit_url"
                                        class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm text-indigo-600 hover:bg-gray-50"
                                    >
                                        Open question
                                    </Link>
                                    <button
                                        v-if="group.can_inline_edit && editingId !== group.question_id"
                                        type="button"
                                        class="rounded-md border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-sm text-indigo-800 hover:bg-indigo-100"
                                        @click="startEdit(group)"
                                    >
                                        Fix here
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3 px-5 py-4">
                            <div
                                v-for="(issue, index) in group.issues"
                                :key="`${group.question_id}-${index}`"
                                class="rounded-lg border border-red-100 bg-red-50/40 px-4 py-3 text-sm text-red-900"
                            >
                                {{ issue.message }}
                                <p v-if="issue.current_value" class="mt-1 font-mono text-xs text-red-800">
                                    Current: {{ issue.current_value }}
                                </p>
                            </div>

                            <p v-if="suggestedAnswer(group.issues)" class="text-sm text-amber-800">
                                Suggested answer: <span class="font-mono font-semibold">{{ suggestedAnswer(group.issues) }}</span>
                            </p>

                            <form
                                v-if="group.can_inline_edit && editingId === group.question_id"
                                class="rounded-lg border border-indigo-200 bg-indigo-50/30 p-4"
                                @submit.prevent="saveEdit(group.question_id)"
                            >
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="md:col-span-2">
                                        <InputLabel value="Question text" />
                                        <textarea
                                            v-model="blankForm.question_text"
                                            rows="2"
                                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                        />
                                    </div>
                                    <div>
                                        <InputLabel value="Answer format" />
                                        <select v-model="blankForm.answer_format" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                            <option v-for="format in answerFormats" :key="format" :value="format">
                                                {{ format }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel value="Correct answer" />
                                        <TextInput v-model="blankForm.correct_answer" class="mt-1 block w-full font-mono" />
                                    </div>
                                    <div>
                                        <InputLabel value="Decimal places" />
                                        <TextInput v-model="blankForm.decimal_places" type="number" min="0" max="6" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <InputLabel value="Difficulty" />
                                        <TextInput v-model="blankForm.difficulty" class="mt-1 block w-full" />
                                    </div>
                                </div>
                                <div class="mt-4 flex gap-2">
                                    <PrimaryButton type="submit" :disabled="blankForm.processing">
                                        Save fix
                                    </PrimaryButton>
                                    <button type="button" class="text-sm text-gray-600 hover:text-gray-800" @click="cancelEdit">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
