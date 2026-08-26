<script setup>
import Modal from '@/Components/Modal.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { hasRoute } from '@/utils/routes';

const props = defineProps({
    item: { type: Object, required: true },
    compact: { type: Boolean, default: false },
});

const showCheck = ref(false);
const fixForm = useForm({});
const dismissForm = useForm({});
const uploaderForm = useForm({
    issue: 'incomplete',
    remark: '',
});

const canReturnToUploader = computed(() =>
    !props.item.sent_to_uploader
    && hasRoute('admin.question-issue-reports.return-to-uploader')
    && props.item.can_return_to_uploader === true,
);

const canPostAction = computed(() =>
    !props.item.sent_to_uploader
    && hasRoute('admin.question-issue-reports.return-to-uploader'),
);

const isQuestionCorrect = computed(() => uploaderForm.issue === 'question_correct');

const showActionPanel = computed(() => canReturnToUploader.value || canPostAction.value);

const busy = computed(() =>
    fixForm.processing || dismissForm.processing || uploaderForm.processing,
);

const actionButtonLabel = computed(() => {
    if (uploaderForm.processing) {
        return isQuestionCorrect.value ? 'Confirming…' : 'Sending…';
    }

    return isQuestionCorrect.value
        ? 'Confirm — 0 marks & email'
        : 'Send to uploader';
});

const markFixed = () => {
    if (!confirm('Mark this sum as fixed and put it back on the student\'s correction list to attempt again?')) {
        return;
    }
    fixForm.post(route('admin.question-issue-reports.mark-fixed', props.item.id), {
        preserveScroll: true,
    });
};

const dismiss = () => {
    if (!confirm('Dismiss this report? The student will not be asked to re-attempt from this report.')) {
        return;
    }
    dismissForm.post(route('admin.question-issue-reports.dismiss', props.item.id), {
        preserveScroll: true,
    });
};

const submitAction = () => {
    if (isQuestionCorrect.value) {
        if (!window.confirm(
            'Confirm the question is correct?\n\n'
            + '• Student must re-attempt from their revise list\n'
            + '• Original score for this sum stays 0 (even if they get it right later)\n'
            + '• Student is notified by email',
        )) {
            return;
        }

        uploaderForm.post(route('admin.question-issue-reports.return-to-uploader', props.item.id), {
            preserveScroll: true,
        });

        return;
    }

    if (!canReturnToUploader.value) {
        window.alert('No content uploader is assigned for this chapter. Choose “Question is correct — please re-attempt”, or use Edit yourself.');

        return;
    }

    const who = props.item.uploader_name || 'the uploader';
    const chapter = props.item.chapter_label ? ` (${props.item.chapter_label})` : '';
    const issueLabel = uploaderForm.issue === 'wrong_answer'
        ? 'wrong answer'
        : uploaderForm.issue === 'incomplete'
            ? 'incomplete / missing diagram'
            : 'a content issue';

    if (!window.confirm(`Send only this sum to ${who}${chapter} to fix (${issueLabel})?\n\nThey will be emailed. It moves to Sent to uploader until you mark Fixed.`)) {
        return;
    }

    uploaderForm.post(route('admin.question-issue-reports.return-to-uploader', props.item.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div :class="compact ? 'flex w-full max-w-sm flex-col items-stretch gap-2' : 'mt-3 space-y-3'">
        <div class="flex flex-wrap gap-2" :class="compact ? 'justify-end' : ''">
            <button
                type="button"
                class="text-xs font-semibold text-sky-700 hover:underline"
                @click="showCheck = true"
            >
                Open to check
            </button>
            <Link
                v-if="item.check_url || item.set_url"
                :href="item.check_url || item.set_url"
                class="text-xs font-semibold text-indigo-700 hover:underline"
            >
                Open in set / edit
            </Link>
            <Link
                v-if="item.edit_url && item.edit_url !== item.check_url"
                :href="item.edit_url"
                class="text-xs font-semibold text-indigo-700 hover:underline"
            >
                Edit yourself
            </Link>
        </div>

        <div
            v-if="showActionPanel"
            class="rounded-md border p-2"
            :class="[
                compact ? 'text-right' : '',
                isQuestionCorrect ? 'border-sky-200 bg-sky-50/80' : 'border-amber-200 bg-amber-50/80',
            ]"
        >
            <p v-if="!compact" class="mb-1.5 text-xs" :class="isQuestionCorrect ? 'text-sky-950' : 'text-amber-950'">
                <template v-if="isQuestionCorrect">
                    Question and answer look fine — student must re-attempt.
                    <span class="font-semibold">Score stays 0</span> even if they get it right later. Email notifies them.
                </template>
                <template v-else>
                    If you prefer not to fix it yourself, send
                    <span class="font-semibold">only this sum</span>
                    to {{ item.uploader_name || 'the uploader' }}.
                </template>
            </p>
            <div class="flex flex-wrap items-end gap-1.5" :class="compact ? 'justify-end' : ''">
                <select
                    v-model="uploaderForm.issue"
                    class="rounded border-gray-300 text-xs py-0.5"
                >
                    <option value="incomplete">Incomplete / missing diagram</option>
                    <option value="wrong_answer">Wrong answer</option>
                    <option value="other">Other issue</option>
                    <option value="question_correct">Question is correct — please re-attempt</option>
                </select>
                <input
                    v-if="uploaderForm.issue === 'other' || uploaderForm.issue === 'question_correct' || !compact"
                    v-model="uploaderForm.remark"
                    type="text"
                    maxlength="500"
                    :placeholder="uploaderForm.issue === 'other'
                        ? 'What should they fix?'
                        : uploaderForm.issue === 'question_correct'
                            ? 'Optional note to student'
                            : 'Optional note'"
                    class="min-w-[8rem] flex-1 rounded border-gray-300 text-xs py-0.5"
                >
                <button
                    type="button"
                    class="rounded px-2 py-1 text-xs font-semibold text-white disabled:opacity-50"
                    :class="isQuestionCorrect ? 'bg-sky-700 hover:bg-sky-800' : 'bg-amber-700 hover:bg-amber-800'"
                    :disabled="busy || (!isQuestionCorrect && !canReturnToUploader)"
                    @click="submitAction"
                >
                    {{ actionButtonLabel }}
                </button>
            </div>
            <p v-if="uploaderForm.errors.issue || uploaderForm.errors.remark" class="mt-1 text-xs text-rose-700">
                {{ uploaderForm.errors.issue || uploaderForm.errors.remark }}
            </p>
        </div>
        <p
            v-else-if="item.content_task_id === null && item.can_return_to_uploader === false && !compact"
            class="text-xs text-gray-500"
        >
            No content uploader is assigned — use Edit yourself to fix it, or dismiss if not needed.
        </p>

        <p
            v-if="item.sent_to_uploader"
            class="rounded-md border border-violet-200 bg-violet-50 px-2 py-1.5 text-xs text-violet-950"
            :class="compact ? 'text-right' : ''"
        >
            Waiting on uploader{{ item.uploader_name ? ` (${item.uploader_name})` : '' }}.
            When they fix it, tap Fixed — return to student.
        </p>

        <p v-if="item.admin_note" class="text-xs text-amber-900">
            Note: {{ item.admin_note }}
        </p>

        <div class="flex flex-wrap gap-3" :class="compact ? 'justify-end' : ''">
            <button
                type="button"
                class="text-xs font-semibold text-emerald-700 hover:underline disabled:opacity-50"
                :disabled="busy"
                @click="markFixed"
            >
                {{ fixForm.processing ? 'Saving…' : 'Fixed — return to student' }}
            </button>
            <button
                type="button"
                class="text-xs font-semibold text-gray-500 hover:underline disabled:opacity-50"
                :disabled="busy"
                @click="dismiss"
            >
                {{ dismissForm.processing ? '…' : 'Dismiss' }}
            </button>
        </div>
    </div>

    <Modal :show="showCheck" max-width="2xl" @close="showCheck = false">
        <div class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Check this sum</h3>
                    <p class="mt-0.5 text-xs text-gray-500">
                        {{ item.context_label }}
                        <span v-if="item.set_code"> · {{ item.set_code }}</span>
                        <span v-if="item.student_name"> · {{ item.student_name }}</span>
                    </p>
                </div>
                <button type="button" class="text-sm text-gray-500 hover:text-gray-800" @click="showCheck = false">
                    Close
                </button>
            </div>

            <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
                <QuestionBody
                    :question-text="item.question_text"
                    :diagram-url="item.diagram_url"
                    enlarge-diagram
                />

                <ul v-if="item.options?.length" class="mt-4 space-y-2">
                    <li
                        v-for="(opt, index) in item.options"
                        :key="opt.id"
                        class="rounded-md border px-3 py-2 text-sm"
                        :class="opt.is_correct
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-950'
                            : 'border-gray-200 bg-white text-gray-800'"
                    >
                        <McqOptionLine :index="index" :text="opt.option_text" />
                        <span v-if="opt.is_correct" class="ml-1 text-xs font-semibold text-emerald-700">(stored key)</span>
                    </li>
                </ul>
                <p v-else-if="item.correct_answer" class="mt-4 text-sm text-gray-800">
                    Stored answer:
                    <span class="font-semibold">{{ item.correct_answer }}</span>
                </p>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <Link
                    v-if="item.check_url || item.set_url"
                    :href="item.check_url || item.set_url"
                    class="rounded bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700"
                >
                    Open in set / edit
                </Link>
                <Link
                    v-if="item.edit_url && item.edit_url !== item.check_url"
                    :href="item.edit_url"
                    class="rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 hover:bg-gray-50"
                >
                    Edit yourself
                </Link>
                <button
                    type="button"
                    class="rounded border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-100 disabled:opacity-50"
                    :disabled="busy"
                    @click="markFixed"
                >
                    Fixed — return to student
                </button>
            </div>
        </div>
    </Modal>
</template>
