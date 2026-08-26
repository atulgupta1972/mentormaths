<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { hasRoute } from '@/utils/routes';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const canReturn = computed(() =>
    hasRoute('admin.help-requests.return-to-uploader') && props.item.can_return_to_uploader === true,
);

const canMarkCorrected = computed(() =>
    hasRoute('admin.help-requests.mark-corrected'),
);

const form = useForm({
    issue: 'wrong_answer',
    remark: '',
});

const correctedForm = useForm({});

const busy = computed(() => form.processing || correctedForm.processing);

const sendToUploader = () => {
    const who = props.item.uploader_name || 'the uploader';
    const chapter = props.item.chapter_label ? ` (${props.item.chapter_label})` : '';
    const issueLabel = form.issue === 'wrong_answer'
        ? 'wrong answer'
        : form.issue === 'incomplete'
            ? 'incomplete sum'
            : 'a content issue';

    if (!window.confirm(`Send only this sum to ${who}${chapter} to fix (${issueLabel})?\n\nOther questions stay as they are. The question stays live for students.`)) {
        return;
    }

    form.post(route('admin.help-requests.return-to-uploader', props.item.id), {
        preserveScroll: true,
    });
};

const markCorrected = () => {
    if (!window.confirm(
        'Mark this as corrected by you?\n\n'
        + '• It leaves the help list\n'
        + '• The student gets it back to retry (daily drill / corrections)',
    )) {
        return;
    }

    correctedForm.post(route('admin.help-requests.mark-corrected', props.item.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="space-y-1.5" :class="compact ? 'mt-1' : 'mt-2'">
        <div v-if="canReturn" class="space-y-1.5">
            <p v-if="!compact" class="text-xs text-gray-600">
                If the stored answer is wrong or the sum is incomplete, fix it yourself or send
                <span class="font-semibold">only this sum</span>
                to {{ item.uploader_name || 'the uploader' }}.
            </p>
            <div class="flex flex-wrap items-end gap-1.5" :class="compact ? 'justify-end' : ''">
                <select
                    v-model="form.issue"
                    class="rounded border-gray-300 text-xs"
                    :class="compact ? 'py-0.5' : 'py-1'"
                >
                    <option value="wrong_answer">Wrong answer</option>
                    <option value="incomplete">Incomplete sum</option>
                    <option value="other">Other issue</option>
                </select>
                <input
                    v-if="form.issue === 'other' || !compact"
                    v-model="form.remark"
                    type="text"
                    maxlength="500"
                    :placeholder="form.issue === 'other' ? 'What should they fix?' : 'Optional note'"
                    class="min-w-[8rem] flex-1 rounded border-gray-300 text-xs"
                    :class="compact ? 'py-0.5' : 'py-1'"
                >
                <button
                    type="button"
                    class="rounded bg-amber-700 px-2 py-1 text-xs font-semibold text-white hover:bg-amber-800 disabled:opacity-50"
                    :disabled="busy"
                    @click="sendToUploader"
                >
                    {{ form.processing ? 'Sending…' : 'Send this sum to uploader' }}
                </button>
            </div>
            <p v-if="form.errors.issue || form.errors.remark" class="text-xs text-rose-700">
                {{ form.errors.issue || form.errors.remark }}
            </p>
        </div>
        <p
            v-else-if="item.content_task_id === null && item.can_return_to_uploader === false && !compact"
            class="text-xs text-gray-500"
        >
            No content uploader is assigned — use Edit question, then tap I have corrected.
        </p>

        <div class="flex flex-wrap gap-2" :class="compact ? 'justify-end' : ''">
            <button
                v-if="canMarkCorrected"
                type="button"
                class="rounded border border-emerald-300 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800 hover:bg-emerald-100 disabled:opacity-50"
                :disabled="busy"
                @click="markCorrected"
            >
                {{ correctedForm.processing ? 'Saving…' : 'I have corrected — student retries' }}
            </button>
        </div>
    </div>
</template>
