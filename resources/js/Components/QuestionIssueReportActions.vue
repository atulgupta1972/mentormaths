<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    item: { type: Object, required: true },
    compact: { type: Boolean, default: false },
});

const fixForm = useForm({});
const dismissForm = useForm({});

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
</script>

<template>
    <div :class="compact ? 'flex flex-col items-end gap-1' : 'mt-2 flex flex-wrap gap-2'">
        <button
            type="button"
            class="text-xs font-semibold text-emerald-700 hover:underline disabled:opacity-50"
            :disabled="fixForm.processing || dismissForm.processing"
            @click="markFixed"
        >
            {{ fixForm.processing ? 'Saving…' : 'Fixed — return to student' }}
        </button>
        <button
            type="button"
            class="text-xs font-semibold text-gray-500 hover:underline disabled:opacity-50"
            :disabled="fixForm.processing || dismissForm.processing"
            @click="dismiss"
        >
            {{ dismissForm.processing ? '…' : 'Dismiss' }}
        </button>
    </div>
</template>
