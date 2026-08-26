<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { safeRoute } from '@/utils/routes';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';

const props = defineProps({
    task: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    progress: { type: Object, default: () => ({ total: 0, included: 0, checked: 0, skipped: 0 }) },
    formats: { type: Array, default: () => [] },
    activeSeconds: { type: Number, default: 0 },
});

const page = usePage();
const agreeForm = useForm({});
const submitForm = useForm({});
const drafts = reactive({});
const attempts = reactive({});
/** After Check (pass or fail), show stored answer so checker can verify / edit. */
const keyRevealed = reactive({});

const seedDrafts = () => {
    props.rows.forEach((row) => {
        drafts[row.index] = {
            fill_blank_question_text: row.fill_blank_question_text,
            fill_blank_correct_answer: row.fill_blank_correct_answer,
            fill_blank_answer_format: ['integer', 'decimal', 'fraction'].includes(row.fill_blank_answer_format)
                ? row.fill_blank_answer_format
                : 'integer',
            fill_blank_decimal_places: row.fill_blank_decimal_places,
            include_in_written: row.include_in_written !== false,
        };
        if (attempts[row.index] === undefined) {
            attempts[row.index] = '';
        }
        if (row.checked) {
            keyRevealed[row.index] = true;
        }
    });
};

seedDrafts();
watch(() => props.rows, seedDrafts, { deep: true });

const canEdit = computed(() =>
    props.task.can_work
    && ['in_progress', 'uploaded', 'verification_in_progress', 'verified', 'submitted_for_publish'].includes(props.task.status),
);
const canSubmit = computed(() => canEdit.value
    && props.progress.included > 0
    && props.progress.checked === props.progress.included);

const nonNumericRows = computed(() =>
    props.rows.filter((row) => !row.skipped && row.non_numeric_answer),
);

const lastCheck = computed(() => page.props.flash?.conversion_check ?? null);

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;

const answerRevealed = (row) => Boolean(keyRevealed[row.index] || row.checked || lastCheck.value?.index === row.index);

const payload = (row) => ({
    index: row.index,
    ...drafts[row.index],
    include_in_written: drafts[row.index].include_in_written !== false,
});

const saveRow = (row) => {
    router.post(route('content.tasks.convert-save', props.task.id), payload(row), {
        preserveScroll: true,
        preserveState: true,
        only: ['rows', 'progress', 'flash'],
    });
};

const checkRow = (row) => {
    router.post(route('content.tasks.convert-check', props.task.id), {
        ...payload(row),
        attempt: attempts[row.index] || '',
    }, {
        preserveScroll: true,
        preserveState: true,
        only: ['rows', 'progress', 'flash', 'task'],
        onSuccess: () => {
            keyRevealed[row.index] = true;
        },
    });
};

const skipRow = (row, skipped) => {
    router.post(route('content.tasks.convert-skip', props.task.id), {
        index: row.index,
        skipped,
    }, {
        preserveScroll: true,
        only: ['rows', 'progress', 'flash', 'task'],
    });
};

const deleteFromConversion = (indexes) => {
    if (!indexes.length) {
        return;
    }

    router.post(route('content.tasks.convert-clear', props.task.id), {
        indexes,
    }, {
        preserveScroll: true,
        only: ['rows', 'progress', 'flash', 'task'],
    });
};

const deleteWordAnswerRow = (row) => {
    if (!window.confirm('Delete this question from fill-in-blank conversion? The MCQ stays. Use this when the answer is words, not a number.')) {
        return;
    }

    deleteFromConversion([row.index]);
};

const deleteAllNonNumeric = () => {
    const indexes = nonNumericRows.value.map((row) => row.index);
    if (!indexes.length) {
        return;
    }

    if (!window.confirm(`Delete ${indexes.length} question(s) whose answers are not numbers from conversion? MCQs stay.`)) {
        return;
    }

    deleteFromConversion(indexes);
};
</script>

<template>
    <Head :title="`Convert · Ch ${task.chapter?.chapter_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        Convert MCQ → fill-in-blank
                    </h2>
                    <p class="text-sm text-gray-500">
                        {{ task.chapter?.grade_name }} · {{ task.chapter?.textbook_name }} · Ch {{ task.chapter?.chapter_number }}
                        · {{ task.status_label }}
                        · {{ task.rate_description || formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}
                    </p>
                </div>
                <Link :href="safeRoute('content.tasks.index', null, '/content/tasks')" class="text-sm text-indigo-600 hover:underline">
                    ← My tasks
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-4 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-950">
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700">
                    Blank answers must be <strong>numbers or fractions</strong> only (e.g. 42, 3.5, 3/4) — not English words.
                    If a question’s answer is not a number, use <strong>Delete from conversion</strong> (MCQ stays).
                    For the rest, edit the blank, then
                    <strong>Check as a student</strong> with the key hidden. After Check, the stored answer is shown so you can confirm —
                    if the MCQ key looks wrong, edit the fill-in-blank answer and Check again.
                    Admin publishes fill-in-blank and written after you submit.
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-slate-600">
                        {{ progress.checked }} of {{ progress.included }} included blanks checked
                        · {{ progress.skipped }} skipped / deleted (MCQ only)
                    </p>
                    <button
                        v-if="canEdit && nonNumericRows.length"
                        type="button"
                        class="rounded-md border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-900 hover:bg-rose-100"
                        @click="deleteAllNonNumeric"
                    >
                        Delete all non-numeric ({{ nonNumericRows.length }})
                    </button>
                </div>

                <div v-if="task.awaiting_agreement" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-700">
                        Admin offered <strong>{{ task.rate_description || formatInr(task.offered_amount_inr) }}</strong>.
                        Agree to start conversion.
                    </p>
                    <PrimaryButton
                        class="mt-4"
                        type="button"
                        :disabled="agreeForm.processing"
                        @click="agreeForm.post(route('content.tasks.agree', task.id))"
                    >
                        I agree — start conversion
                    </PrimaryButton>
                </div>

                <div
                    v-for="row in rows"
                    :key="row.index"
                    class="rounded-xl border bg-white p-4 shadow-sm"
                    :class="row.skipped
                        ? 'border-slate-200 opacity-80'
                        : row.non_numeric_answer
                            ? 'border-amber-300 bg-amber-50/40'
                            : row.checked
                                ? 'border-emerald-300'
                                : 'border-slate-200'"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ row.source_label }}</p>
                        <div class="flex flex-wrap gap-2">
                            <span v-if="row.skipped" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">Skipped · MCQ only</span>
                            <span
                                v-else-if="row.non_numeric_answer"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-950"
                            >Answer not a number</span>
                            <span v-else-if="row.checked" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-900">Checked</span>
                            <span v-else class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-900">Needs Check</span>
                        </div>
                    </div>

                    <div class="mt-3 rounded-md bg-slate-50 p-3 text-sm">
                        <p class="text-xs font-semibold text-slate-500">Original MCQ</p>
                        <p class="mt-1 whitespace-pre-wrap text-slate-900">{{ row.mcq_question }}</p>
                        <details class="mt-2 text-xs text-slate-600">
                            <summary class="cursor-pointer font-medium text-slate-500">Show MCQ source key (hide this when Checking)</summary>
                            <p class="mt-1">{{ row.mcq_answer }}</p>
                        </details>
                    </div>

                    <div v-if="canEdit" class="mt-3 flex flex-wrap items-center justify-end gap-3">
                        <button
                            v-if="!row.skipped && row.non_numeric_answer"
                            type="button"
                            class="rounded-md border border-rose-300 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-900 hover:bg-rose-100"
                            @click="deleteWordAnswerRow(row)"
                        >
                            Delete from conversion (not a number)
                        </button>
                        <button
                            type="button"
                            class="text-xs font-medium text-slate-600 hover:underline"
                            @click="skipRow(row, !row.skipped)"
                        >
                            {{ row.skipped ? 'Unskip — convert this one' : 'Skip (keep MCQ only)' }}
                        </button>
                    </div>

                    <template v-if="!row.skipped">
                        <div
                            v-if="row.non_numeric_answer"
                            class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950"
                        >
                            This answer looks like words, not a number. Delete it from conversion, or rewrite the blank answer as a number/fraction and Check.
                        </div>

                        <label class="mt-4 block text-xs font-semibold text-slate-600">Fill-in-blank stem (must include ____)</label>
                        <textarea
                            v-model="drafts[row.index].fill_blank_question_text"
                            rows="3"
                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            :disabled="!canEdit"
                            @change="saveRow(row)"
                        />

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="text-xs font-semibold text-slate-600">Answer format</label>
                                <select
                                    v-model="drafts[row.index].fill_blank_answer_format"
                                    class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                    :disabled="!canEdit"
                                    @change="saveRow(row)"
                                >
                                    <option v-for="format in formats" :key="format.value" :value="format.value">{{ format.label }}</option>
                                </select>
                            </div>
                            <div v-if="drafts[row.index].fill_blank_answer_format === 'decimal'">
                                <label class="text-xs font-semibold text-slate-600">Decimal places</label>
                                <input
                                    v-model="drafts[row.index].fill_blank_decimal_places"
                                    type="number"
                                    min="1"
                                    max="8"
                                    class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                    :disabled="!canEdit"
                                    @change="saveRow(row)"
                                >
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-slate-600">
                                    {{ answerRevealed(row) ? 'Stored fill-in-blank answer (edit if MCQ key is wrong)' : 'Canonical answer (hidden until you Check)' }}
                                </label>
                                <input
                                    v-model="drafts[row.index].fill_blank_correct_answer"
                                    :type="answerRevealed(row) ? 'text' : 'password'"
                                    autocomplete="off"
                                    class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                    :disabled="!canEdit"
                                    @change="saveRow(row)"
                                >
                            </div>
                            <label class="flex items-end gap-2 text-sm text-slate-700">
                                <input v-model="drafts[row.index].include_in_written" type="checkbox" class="rounded" :disabled="!canEdit" @change="saveRow(row)">
                                Include in written (W)
                            </label>
                        </div>

                        <div
                            v-if="canEdit"
                            class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50/60 p-3"
                        >
                            <p class="text-xs font-semibold text-indigo-950">Check as a student (answer key hidden until you Check)</p>
                            <p class="mt-1 text-xs text-indigo-900">Type what a student would enter. Same marking rules as live fill-in-blank.</p>
                            <div class="mt-2 flex flex-wrap items-end gap-2">
                                <input
                                    v-model="attempts[row.index]"
                                    type="text"
                                    autocomplete="off"
                                    placeholder="Student attempt"
                                    class="min-w-[12rem] flex-1 rounded-md border-indigo-200 text-sm"
                                >
                                <PrimaryButton type="button" class="!py-1.5 !text-xs" @click="checkRow(row)">
                                    Check
                                </PrimaryButton>
                            </div>
                            <div
                                v-if="lastCheck?.index === row.index || (row.checked && answerRevealed(row))"
                                class="mt-3 rounded-md border px-3 py-2 text-xs"
                                :class="(lastCheck?.index === row.index ? lastCheck.correct : row.checked)
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-950'
                                    : 'border-amber-200 bg-amber-50 text-amber-950'"
                            >
                                <p v-if="lastCheck?.index === row.index">{{ lastCheck.message }}</p>
                                <p v-else-if="row.checked">Checked — your attempt matched the stored answer.</p>
                                <p class="mt-1 font-medium">
                                    Stored answer:
                                    <span class="font-mono">{{ drafts[row.index].fill_blank_correct_answer || lastCheck?.expected_answer || '—' }}</span>
                                </p>
                                <p
                                    v-if="lastCheck?.index === row.index && !lastCheck.correct"
                                    class="mt-1"
                                >
                                    Your attempt was
                                    <span class="font-mono">{{ lastCheck.attempt || attempts[row.index] || '—' }}</span>.
                                    Verify the stored answer. If the MCQ key is wrong, edit it above and Check again.
                                </p>
                            </div>
                        </div>
                    </template>
                </div>

                <div v-if="canEdit" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-sm text-emerald-950">
                        Submit when every included blank is Checked. Skipped rows stay MCQ only. Admin still publishes F/W.
                    </p>
                    <PrimaryButton
                        class="mt-3"
                        type="button"
                        :disabled="submitForm.processing || !canSubmit"
                        @click="submitForm.post(route('content.tasks.submit-for-publish', task.id))"
                    >
                        {{ submitForm.processing ? 'Submitting…' : 'Submit for admin publish' }}
                    </PrimaryButton>
                    <p v-if="!canSubmit" class="mt-2 text-xs text-emerald-900">
                        Check remaining included blanks first ({{ progress.checked }}/{{ progress.included }}).
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
