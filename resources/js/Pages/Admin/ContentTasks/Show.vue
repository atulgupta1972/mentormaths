<script setup>
import AdminContentVerificationBatch from '@/Components/AdminContentVerificationBatch.vue';
import ContentVerificationPanel from '@/Components/ContentVerificationPanel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { safeRoute } from '@/utils/routes';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    task: { type: Object, required: true },
    verification: { type: Object, default: null },
    activeSeconds: { type: Number, default: 0 },
    deleteRequests: { type: Array, default: () => [] },
    uploaders: { type: Array, default: () => [] },
    textbooks: { type: Array, default: () => [] },
    conversionRows: { type: Array, default: () => [] },
});

const approveDeleteForm = useForm({ admin_note: '' });
const rejectDeleteForm = useForm({ admin_note: '' });
const reassignForm = useForm({
    assigned_to_user_id: '',
    note: '',
});
const changeBookForm = useForm({
    mode: 'existing',
    textbook_id: '',
    book_name: '',
    book_code: '',
});
const selectedConversionIndexes = ref([]);

const looksLikeWordAnswer = (answer) => {
    const value = String(answer || '').trim();
    if (!value) {
        return false;
    }

    if (/^-?\d+(?:[.,]\d+)?(?:\s*\/\s*\d+(?:[.,]\d+)?)?$/.test(value.replace(/,/g, ''))) {
        return false;
    }

    if (/^-?\d+\s+\d+\s*\/\s*\d+$/.test(value)) {
        return false;
    }

    return /[a-zA-Z]/.test(value);
};

const toggleConversionRow = (index) => {
    if (selectedConversionIndexes.value.includes(index)) {
        selectedConversionIndexes.value = selectedConversionIndexes.value.filter((row) => row !== index);
        return;
    }

    selectedConversionIndexes.value = [...selectedConversionIndexes.value, index];
};

const clearSelectedConversions = () => {
    if (!selectedConversionIndexes.value.length) {
        return;
    }

    if (!window.confirm(`Remove fill-in-blank for ${selectedConversionIndexes.value.length} selected question(s)? MCQs stay.`)) {
        return;
    }

    router.post(route('admin.content-tasks.conversion-clear-rows', props.task.id), {
        indexes: selectedConversionIndexes.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedConversionIndexes.value = [];
        },
    });
};

const clearAllConversions = () => {
    if (!window.confirm('Clear ALL fill-in-blank conversion drafts on this chapter? MCQs stay. Uploader can convert again.')) {
        return;
    }

    router.post(route('admin.content-tasks.conversion-clear-all', props.task.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            selectedConversionIndexes.value = [];
        },
    });
};

const approveDelete = (id) => {
    approveDeleteForm.post(route('admin.content-tasks.delete-requests.approve', [props.task.id, id]), {
        preserveScroll: true,
    });
};

const rejectDelete = (id) => {
    rejectDeleteForm.post(route('admin.content-tasks.delete-requests.reject', [props.task.id, id]), {
        preserveScroll: true,
    });
};

const reassign = () => {
    reassignForm.post(route('admin.content-tasks.reassign', props.task.id), {
        preserveScroll: true,
        onSuccess: () => reassignForm.reset(),
    });
};

const submitChangeBook = () => {
    if (!props.task.chapter?.id) {
        return;
    }

    changeBookForm.transform((data) => ({
        textbook_id: data.mode === 'existing' ? data.textbook_id : null,
        book_name: data.mode === 'new' ? data.book_name : null,
        book_code: data.mode === 'new' ? data.book_code : null,
    })).post(route('admin.textbooks.change-book', props.task.chapter.id), {
        preserveScroll: true,
    });
};

const page = usePage();
const publishForm = useForm({});
const reviewMode = ref('batch');

const adminTaskPath = (suffix = '') => `/admin/content-tasks/${props.task.id}${suffix}`;

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;
const formatDuration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m ${s}s`;
};
</script>

<template>
    <Head :title="`Task · Ch ${task.chapter?.chapter_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        {{ task.chapter?.grade_name }} · {{ task.chapter?.textbook_name || 'Book' }} · {{ task.chapter?.chapter_number }} — {{ task.chapter?.title }}
                    </h2>
                    <p class="text-sm text-gray-500">{{ task.work_type_label }} · {{ task.status_label }}</p>
                </div>
                <Link :href="safeRoute('admin.content-tasks.index', null, '/admin/content-tasks')" class="text-sm text-indigo-600 hover:underline">← All tasks</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-950">
                    {{ page.props.flash.error }}
                </div>

                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-xs uppercase text-gray-500">Uploader</p>
                        <p class="font-semibold">{{ task.assignee?.name }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-xs uppercase text-gray-500">Book</p>
                        <p class="font-semibold">{{ task.chapter?.textbook_name || '—' }}</p>
                        <p class="text-xs text-gray-500">{{ task.chapter?.textbook_code }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-xs uppercase text-gray-500">Agreed rate</p>
                        <p class="font-semibold">{{ task.rate_description || (task.agreed_amount_inr ? formatInr(task.agreed_amount_inr) : formatInr(task.offered_amount_inr)) }}</p>
                        <p v-if="task.payable_amount_inr > 0 && task.rate_basis === 'per_question'" class="mt-1 text-xs text-gray-500">
                            Payable now: {{ formatInr(task.payable_amount_inr) }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-xs uppercase text-gray-500">Uploader active time</p>
                        <p class="font-semibold">{{ formatDuration(activeSeconds) }}</p>
                    </div>
                </div>

                <form
                    v-if="task.chapter?.id"
                    class="rounded-lg border border-indigo-200 bg-indigo-50/40 p-4 shadow-sm"
                    @submit.prevent="submitChangeBook"
                >
                    <p class="font-semibold text-indigo-950">Change book / author</p>
                    <p class="mt-1 text-sm text-indigo-900">
                        Use when the uploader picked the wrong book. After publish, only admin can fix this.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-4 text-sm">
                        <label class="inline-flex items-center gap-1.5">
                            <input v-model="changeBookForm.mode" type="radio" value="existing">
                            Existing book
                        </label>
                        <label class="inline-flex items-center gap-1.5">
                            <input v-model="changeBookForm.mode" type="radio" value="new">
                            New name + code
                        </label>
                    </div>
                    <div class="mt-2 flex flex-wrap items-end gap-2">
                        <select
                            v-if="changeBookForm.mode === 'existing'"
                            v-model="changeBookForm.textbook_id"
                            class="min-w-[220px] rounded-md border-gray-300 text-sm"
                            required
                        >
                            <option value="" disabled>Select book</option>
                            <option v-for="book in textbooks" :key="book.id" :value="book.id">{{ book.label }}</option>
                        </select>
                        <template v-else>
                            <input v-model="changeBookForm.book_name" type="text" placeholder="Book name" class="rounded-md border-gray-300 text-sm" required>
                            <input v-model="changeBookForm.book_code" type="text" placeholder="Code" class="w-32 rounded-md border-gray-300 text-sm" required>
                        </template>
                        <PrimaryButton type="submit" class="!py-1.5 !text-xs" :disabled="changeBookForm.processing">
                            Update book
                        </PrimaryButton>
                        <Link
                            v-if="task.chapter?.id"
                            :href="safeRoute('admin.textbooks.show', task.chapter.id, `/admin/textbooks/chapters/${task.chapter.id}`)"
                            class="text-sm text-indigo-700 hover:underline"
                        >
                            Open chapter (PDF) →
                        </Link>
                    </div>
                </form>

                <form
                    v-if="task.can_reassign"
                    class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                    @submit.prevent="reassign"
                >
                    <p class="font-semibold text-gray-900">Reassign to another uploader</p>
                    <p class="mt-1 text-sm text-gray-500">
                        Use this when the current uploader cannot finish. The chapter, PDF, and any questions already imported stay — only the assignee changes.
                    </p>
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <div class="min-w-[16rem] flex-1">
                            <label class="text-xs font-medium text-gray-600">New uploader</label>
                            <select
                                v-model="reassignForm.assigned_to_user_id"
                                required
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            >
                                <option value="" disabled>Select uploader</option>
                                <option
                                    v-for="person in uploaders"
                                    :key="person.id"
                                    :value="person.id"
                                    :disabled="person.id === task.assignee?.id"
                                >
                                    {{ person.name }}{{ person.id === task.assignee?.id ? ' (current)' : '' }}
                                </option>
                            </select>
                        </div>
                        <div class="min-w-[16rem] flex-1">
                            <label class="text-xs font-medium text-gray-600">Note (optional)</label>
                            <input
                                v-model="reassignForm.note"
                                type="text"
                                maxlength="500"
                                placeholder="Could not finish"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            >
                        </div>
                        <PrimaryButton type="submit" :disabled="reassignForm.processing || !reassignForm.assigned_to_user_id">
                            {{ reassignForm.processing ? 'Reassigning…' : 'Reassign' }}
                        </PrimaryButton>
                    </div>
                </form>

                <div v-if="task.duplicate_override_reason" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm">
                    <strong>Duplicate override:</strong> {{ task.duplicate_override_reason }}
                </div>

                <div v-if="task.admin_notes" class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm whitespace-pre-wrap">
                    <strong>Admin notes:</strong><br>{{ task.admin_notes }}
                </div>

                <div v-if="deleteRequests.length" class="rounded-lg border border-rose-200 bg-rose-50 p-4">
                    <p class="text-sm font-semibold text-rose-950">Question delete requests</p>
                    <p class="mt-1 text-sm text-rose-900">Uploader cannot delete after publish. Approve only if the question should be removed.</p>
                    <div class="mt-3 space-y-3">
                        <div
                            v-for="row in deleteRequests"
                            :key="row.id"
                            class="rounded-md border border-rose-100 bg-white p-3 text-sm"
                        >
                            <p class="font-medium text-gray-900">Q{{ row.item_index + 1 }} · {{ row.question_text || 'Question' }}</p>
                            <p class="mt-1 text-gray-600"><strong>Reason:</strong> {{ row.reason }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ row.requester_name }} · {{ row.status }}</p>
                            <div v-if="row.status === 'pending'" class="mt-2 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-rose-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-rose-800 disabled:opacity-50"
                                    :disabled="approveDeleteForm.processing"
                                    @click="approveDelete(row.id)"
                                >
                                    Delete question
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                    :disabled="rejectDeleteForm.processing"
                                    @click="rejectDelete(row.id)"
                                >
                                    Reject
                                </button>
                            </div>
                            <p v-else-if="row.admin_note" class="mt-1 text-xs text-gray-500">Note: {{ row.admin_note }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-4">
                    <p class="text-sm text-indigo-950">
                        Review questions in batches of 10. Tick OK to verify, or flag with a remark to send back — one email when you finish.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <Link
                            v-if="task.chapter?.id"
                            :href="safeRoute('admin.textbooks.show', task.chapter.id, `/admin/textbooks/chapters/${task.chapter.id}`)"
                            class="text-sm font-medium text-indigo-700 hover:underline"
                        >
                            Open textbook chapter →
                        </Link>
                        <button
                            v-if="verification && task.can_verify_questions"
                            type="button"
                            class="text-sm font-medium text-indigo-700 hover:underline"
                            @click="reviewMode = reviewMode === 'batch' ? 'detail' : 'batch'"
                        >
                            {{ reviewMode === 'batch' ? 'Switch to one-by-one edit' : 'Switch to batch table' }}
                        </button>
                    </div>
                </div>

                <div
                    v-if="verification?.set_plan?.length"
                    class="rounded-lg border border-amber-200 bg-amber-50 p-4"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold text-amber-950">
                                Uploader chapter breakup · {{ verification.set_plan_parts }} part{{ verification.set_plan_parts === 1 ? '' : 's' }}
                            </p>
                            <p v-if="verification.set_plan_summary" class="mt-1 text-xs text-amber-900">
                                Summary: {{ verification.set_plan_summary }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-3 overflow-x-auto rounded-md border border-amber-200 bg-white">
                        <table class="min-w-full divide-y divide-amber-100 text-sm">
                            <thead class="bg-amber-100/70 text-left text-[11px] uppercase tracking-wide text-amber-900">
                                <tr>
                                    <th class="px-3 py-2">Part</th>
                                    <th class="px-3 py-2">Set code</th>
                                    <th class="px-3 py-2">Questions</th>
                                    <th class="px-3 py-2">Count</th>
                                    <th class="px-3 py-2">Description / summary</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-amber-50">
                                <tr
                                    v-for="row in verification.set_plan"
                                    :key="`${row.part}-${row.set_code}`"
                                >
                                    <td class="px-3 py-2 font-semibold text-slate-800">{{ row.part }}</td>
                                    <td class="px-3 py-2 font-mono text-xs font-semibold text-indigo-800">{{ row.set_code }}</td>
                                    <td class="px-3 py-2 text-slate-700">Q{{ row.q_from }}–{{ row.q_to }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ row.question_count }}</td>
                                    <td class="px-3 py-2 text-slate-700">
                                        {{ row.description || '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    v-else-if="verification && task.can_verify_questions"
                    class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-3 text-sm text-slate-600"
                >
                    No MCQ set plan saved for this chapter yet.
                </div>

                <AdminContentVerificationBatch
                    v-if="verification && task.can_verify_questions && reviewMode === 'batch'"
                    :task="task"
                    :verification="verification"
                    :batch-verify-route="safeRoute('admin.content-tasks.verification-batch', task.id, adminTaskPath('/verification-batch'))"
                    :return-route="safeRoute('admin.content-tasks.return-for-reverification', task.id, adminTaskPath('/return-for-reverification'))"
                    :upload-diagram-route="safeRoute('admin.content-tasks.verification-diagram', task.id, adminTaskPath('/verification-diagram'))"
                    :remove-diagram-route="safeRoute('admin.content-tasks.verification-diagram.remove', task.id, adminTaskPath('/verification-diagram/remove'))"
                    :can-return="Boolean(task.can_return_for_reverification)"
                />

                <ContentVerificationPanel
                    v-else-if="verification && task.can_verify_questions"
                    :task="task"
                    :verification="verification"
                    :save-question-route="safeRoute('admin.content-tasks.verification-question', task.id, adminTaskPath('/verification-question'))"
                    :upload-diagram-route="safeRoute('admin.content-tasks.verification-diagram', task.id, adminTaskPath('/verification-diagram'))"
                    :remove-diagram-route="safeRoute('admin.content-tasks.verification-diagram.remove', task.id, adminTaskPath('/verification-diagram/remove'))"
                    :editable-statuses="['uploaded', 'verification_in_progress', 'verified', 'submitted_for_publish', 'published']"
                    :show-complete-actions="false"
                />

                <div v-if="task.is_fill_blank_conversion" class="rounded-lg border border-emerald-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-emerald-950">Fill-in-blank conversion</p>
                            <p class="mt-1 text-sm text-emerald-900">
                                Review checked blanks. Answers must be numbers or fractions (e.g. 3/4), not words.
                                Delete bad rows (partial) or clear all — MCQs stay. Publish creates F and W parts.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-900 hover:bg-rose-100 disabled:opacity-50"
                                :disabled="!selectedConversionIndexes.length"
                                @click="clearSelectedConversions"
                            >
                                Delete selected ({{ selectedConversionIndexes.length }})
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-rose-400 bg-white px-3 py-1.5 text-xs font-medium text-rose-800 hover:bg-rose-50"
                                @click="clearAllConversions"
                            >
                                Clear all fill-in-blanks
                            </button>
                        </div>
                    </div>
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="w-8 px-2 py-1.5"></th>
                                    <th class="px-2 py-1.5">Q</th>
                                    <th class="px-2 py-1.5">Stem</th>
                                    <th class="px-2 py-1.5">Answer</th>
                                    <th class="px-2 py-1.5">Status</th>
                                    <th class="px-2 py-1.5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr
                                    v-for="row in conversionRows"
                                    :key="row.index"
                                    :class="looksLikeWordAnswer(row.fill_blank_correct_answer) && !row.skipped ? 'bg-amber-50' : ''"
                                >
                                    <td class="px-2 py-1.5">
                                        <input
                                            type="checkbox"
                                            class="rounded border-gray-300 text-rose-600"
                                            :checked="selectedConversionIndexes.includes(row.index)"
                                            @change="toggleConversionRow(row.index)"
                                        >
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-1.5">{{ row.index + 1 }}</td>
                                    <td class="px-2 py-1.5 text-slate-800">{{ row.skipped ? '—' : row.fill_blank_question_text }}</td>
                                    <td class="px-2 py-1.5 font-mono text-xs">
                                        {{ row.skipped ? '—' : row.fill_blank_correct_answer }}
                                        <span
                                            v-if="!row.skipped && looksLikeWordAnswer(row.fill_blank_correct_answer)"
                                            class="ml-1 rounded bg-amber-200 px-1 text-[10px] font-semibold uppercase text-amber-950"
                                        >words</span>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <span v-if="row.skipped">Skipped (MCQ)</span>
                                        <span v-else-if="row.checked" class="text-emerald-800">Checked</span>
                                        <span v-else class="text-amber-800">Not checked</span>
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        <button
                                            type="button"
                                            class="text-xs font-medium text-rose-700 hover:underline"
                                            @click="router.post(route('admin.content-tasks.conversion-clear-rows', task.id), { indexes: [row.index] }, { preserveScroll: true })"
                                        >
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    v-if="task.can_publish"
                    class="rounded-lg border border-emerald-300 bg-emerald-50 p-4"
                >
                    <p class="text-sm font-semibold text-emerald-950">Ready to publish</p>
                    <p class="mt-1 text-sm text-emerald-900">
                        {{ task.is_fill_blank_conversion
                            ? 'Publish fill-in-blank and written sets from the checked rows.'
                            : 'All questions are verified. Publish to clear this from the verifying list.' }}
                    </p>
                    <PrimaryButton
                        class="mt-3"
                        type="button"
                        :disabled="publishForm.processing"
                        @click="publishForm.post(safeRoute('admin.content-tasks.publish', task.id, adminTaskPath('/publish')))"
                    >
                        {{ publishForm.processing ? 'Publishing…' : 'Publish task' }}
                    </PrimaryButton>
                </div>

                <div v-else-if="task.status === 'submitted_for_publish'" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-sm text-emerald-900">Uploader submitted this chapter for publish.</p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <PrimaryButton type="button" :disabled="publishForm.processing" @click="publishForm.post(route('admin.content-tasks.publish', task.id))">
                            Mark task published
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
