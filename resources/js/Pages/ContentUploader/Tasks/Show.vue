<script setup>
import GeminiPasteReviewPanel from '@/Components/GeminiPasteReviewPanel.vue';
import GeminiVerificationGuide from '@/Components/GeminiVerificationGuide.vue';
import ContentVerificationPanel from '@/Components/ContentVerificationPanel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { safeRoute } from '@/utils/routes';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    task: { type: Object, required: true },
    verification: { type: Object, default: null },
    activeSeconds: { type: Number, default: 0 },
    textbookChapterUrl: { type: String, default: '' },
    textbooks: { type: Array, default: () => [] },
    additionalBook: { type: Object, default: null },
});

const page = usePage();
const agreeForm = useForm({});
const startReviewForm = useForm({});
const additionalBookForm = useForm({
    mode: props.textbooks.length ? 'existing' : 'new',
    textbook_id: '',
    book_name: '',
    book_code: '',
});

const verificationPendingCount = computed(() =>
    Number(
        props.verification?.gemini_pending_count
        ?? props.verification?.progress?.pending
        ?? props.verification?.summary?.unverified
        ?? 0,
    ),
);

const showGeminiGuide = computed(() =>
    props.task.status === 'published'
    || props.task.needs_gemini_check
    || (props.verification?.progress?.can_gemini && props.verification.progress.pending > 0),
);

const taskPath = (suffix = '') => `/content/tasks/${props.task.id}${suffix}`;

const submitAdditionalBook = () => {
    additionalBookForm.transform((data) => ({
        textbook_id: data.mode === 'existing' ? data.textbook_id : null,
        book_name: data.mode === 'new' ? data.book_name : null,
        book_code: data.mode === 'new' ? data.book_code : null,
    })).post(safeRoute('content.tasks.additional-book', props.task.id, taskPath('/additional-book')));
};

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;
const formatDuration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m ${s}s`;
};

const pingSession = () => {
    if (!props.task.can_work) {
        return;
    }
    router.post(safeRoute('content.tasks.ping-session', props.task.id, taskPath('/ping-session')), {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['activeSeconds'],
    });
};

let pingTimer;
onMounted(() => {
    pingSession();
    pingTimer = window.setInterval(pingSession, 60000);
});
onUnmounted(() => clearInterval(pingTimer));
</script>

<template>
    <Head :title="`Ch ${task.chapter?.chapter_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        {{ task.chapter?.grade_name }} · {{ task.chapter?.textbook_name || 'Book' }} · Ch {{ task.chapter?.chapter_number }}
                    </h2>
                    <p class="text-sm text-gray-500">
                        {{ task.status_label }} · {{ task.rate_description || formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}
                        · PDF {{ task.has_pdf ? 'uploaded' : 'missing' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <Link
                        v-if="task.textbook_chapter_id"
                        :href="safeRoute('content.chapters.show', task.textbook_chapter_id, `/content/chapters/${task.textbook_chapter_id}`)"
                        class="text-sm text-indigo-600 hover:underline"
                    >
                        View all questions
                    </Link>
                    <Link :href="safeRoute('content.tasks.index', null, '/content/tasks')" class="text-sm text-indigo-600 hover:underline">← My tasks</Link>
                </div>
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

                <div v-if="additionalBook?.is_child" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    <p class="font-semibold">Additional book for the same chapter</p>
                    <p class="mt-1">
                        The first upload on {{ additionalBook.parent_book || 'the original book' }} stays.
                        Upload this book's PDF and questions here. You are paid once on the original chapter, not again for this book.
                    </p>
                    <p v-if="additionalBook.note" class="mt-2 whitespace-pre-wrap"><strong>Admin note:</strong> {{ additionalBook.note }}</p>
                </div>

                <div v-else-if="additionalBook" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                    <p class="font-semibold">{{ additionalBook.decision_label }} — add another book</p>
                    <p class="mt-1">
                        What you already uploaded stays. Add a second book for this same chapter.
                        You are paid once for the whole chapter, not again for the second book.
                        Admin publishes both together.
                    </p>
                    <p v-if="additionalBook.note" class="mt-2 whitespace-pre-wrap"><strong>Admin note:</strong> {{ additionalBook.note }}</p>

                    <div v-if="additionalBook.child" class="mt-3">
                        <p>Additional book: {{ additionalBook.child.book_name || 'added' }} · {{ additionalBook.child.status_label }}</p>
                        <Link
                            :href="safeRoute('content.tasks.show', additionalBook.child.id, `/content/tasks/${additionalBook.child.id}`)"
                            class="mt-2 inline-block font-medium text-amber-900 underline"
                        >
                            Open the additional book →
                        </Link>
                    </div>

                    <form v-else-if="additionalBook.open" class="mt-3 space-y-2" @submit.prevent="submitAdditionalBook">
                        <div class="flex flex-wrap gap-4">
                            <label class="inline-flex items-center gap-1.5">
                                <input v-model="additionalBookForm.mode" type="radio" value="existing">
                                Existing book
                            </label>
                            <label class="inline-flex items-center gap-1.5">
                                <input v-model="additionalBookForm.mode" type="radio" value="new">
                                New name + code
                            </label>
                        </div>
                        <div class="flex flex-wrap items-end gap-2">
                            <select
                                v-if="additionalBookForm.mode === 'existing'"
                                v-model="additionalBookForm.textbook_id"
                                class="min-w-[220px] rounded-md border-gray-300 text-sm"
                                required
                            >
                                <option value="" disabled>Select book</option>
                                <option v-for="book in textbooks" :key="book.id" :value="book.id">{{ book.label }}</option>
                            </select>
                            <template v-else>
                                <input v-model="additionalBookForm.book_name" type="text" placeholder="Book name" class="rounded-md border-gray-300 text-sm" required>
                                <input v-model="additionalBookForm.book_code" type="text" placeholder="Code" class="w-32 rounded-md border-gray-300 text-sm" required>
                            </template>
                            <PrimaryButton type="submit" :disabled="additionalBookForm.processing">
                                {{ additionalBookForm.processing ? 'Adding…' : 'Add this book' }}
                            </PrimaryButton>
                        </div>
                        <p v-if="additionalBookForm.errors.book_name || additionalBookForm.errors.book_code || additionalBookForm.errors.textbook_id" class="text-sm text-rose-700">
                            {{ additionalBookForm.errors.book_name || additionalBookForm.errors.book_code || additionalBookForm.errors.textbook_id }}
                        </p>
                    </form>
                </div>

                <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                    Active time tracked: <strong>{{ formatDuration(activeSeconds) }}</strong> (pauses after 5 min idle).
                </div>

                <div v-if="task.awaiting_agreement" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-700">
                        Admin offered <strong>{{ task.rate_description || formatInr(task.offered_amount_inr) }}</strong>.
                        Agree to start work.
                    </p>
                    <PrimaryButton
                        class="mt-4"
                        type="button"
                        :disabled="agreeForm.processing"
                        @click="agreeForm.post(safeRoute('content.tasks.agree', task.id, taskPath('/agree')))"
                    >
                        I agree — start work
                    </PrimaryButton>
                </div>

                <div v-else-if="task.needs_review && !verification" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-700">
                        MCQ sets are saved. The next step is the Gemini check. You can submit only after Gemini marks every question Correct.
                    </p>
                    <PrimaryButton
                        class="mt-4"
                        type="button"
                        :disabled="startReviewForm.processing"
                        @click="startReviewForm.post(safeRoute('content.tasks.start-review', task.id, taskPath('/start-review')))"
                    >
                        Review &amp; complete →
                    </PrimaryButton>
                </div>

                <div
                    v-if="verification && verificationPendingCount > 0 && task.status !== 'published'"
                    class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                >
                    <p class="font-semibold">Gemini check is required before you can submit.</p>
                    <p class="mt-1">
                        Copy the prompt, paste Gemini’s reply, then fix anything marked Needs Verification.
                        Paste Gemini again after those fixes. Submit stays closed until every question is Correct.
                        {{ verificationPendingCount }} still pending.
                    </p>
                </div>

                <div v-else-if="verification" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-700">
                        Gemini check comes first. Questions marked Correct are done.
                        Fix the ones Gemini flags, then paste Gemini again before you submit.
                        Upload a figure when the question needs one.
                    </p>
                    <p v-if="task.status === 'published'" class="mt-2 text-sm text-indigo-900">
                        This chapter is published — you can still complete Gemini review for any pending questions.
                    </p>
                    <a v-if="textbookChapterUrl" :href="textbookChapterUrl" class="mt-3 inline-block text-sm font-medium text-indigo-600 hover:underline">
                        Open textbook chapter →
                    </a>
                </div>

                <GeminiVerificationGuide v-if="showGeminiGuide" />

                <div
                    v-else-if="task.status === 'published' && !verification"
                    class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200"
                >
                    <p class="text-sm text-gray-700">
                        This chapter is published. Open review once MCQ sets are saved so Gemini verification can load.
                    </p>
                </div>

                <GeminiPasteReviewPanel
                    v-if="verification"
                    :run-id="verification.run_id"
                    :pending-count="verificationPendingCount"
                    :gemini-prompt="verification.gemini_prompt || ''"
                    :paste-route="safeRoute('content.tasks.verification-gemini-paste', task.id, taskPath('/verification-gemini-paste'))"
                />

                <ContentVerificationPanel
                    v-if="verification"
                    :task="task"
                    :verification="verification"
                    :save-question-route="safeRoute('content.tasks.verification-question', task.id, taskPath('/verification-question'))"
                    :skip-route="safeRoute('content.tasks.verification-skip', task.id, taskPath('/verification-skip'))"
                    :unskip-route="safeRoute('content.tasks.verification-unskip', task.id, taskPath('/verification-unskip'))"
                    :upload-diagram-route="safeRoute('content.tasks.verification-diagram', task.id, taskPath('/verification-diagram'))"
                    :remove-diagram-route="safeRoute('content.tasks.verification-diagram.remove', task.id, taskPath('/verification-diagram/remove'))"
                    :complete-verification-route="safeRoute('content.tasks.complete-verification', task.id, taskPath('/complete-verification'))"
                    :submit-for-publish-route="safeRoute('content.tasks.submit-for-publish', task.id, taskPath('/submit-for-publish'))"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
