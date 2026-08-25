<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    runId: { type: Number, required: true },
    aiReviewRoute: { type: String, required: true },
    pendingCount: { type: Number, default: 0 },
    disabled: { type: Boolean, default: false },
});

const page = usePage();
const form = useForm({
    run_id: props.runId,
});

const aiReview = computed(() => page.props.flash?.ai_review ?? null);

const attention = computed(() => aiReview.value?.attention ?? []);

const runAiReview = () => {
    if (form.processing || props.disabled || !props.aiReviewRoute || props.aiReviewRoute === '#') {
        return;
    }

    const pending = Number(props.pendingCount || 0);
    const confirmMsg = pending > 0
        ? `AI will review ${pending} pending question(s).\n\nHigh-confidence OK → auto-verified\nHigh-confidence irrelevant → skipped (not paid)\nProblems / missing figures → left for you\n\nContinue?`
        : 'Run AI review on any remaining pending questions?';

    if (!window.confirm(confirmMsg)) {
        return;
    }

    form.run_id = props.runId;
    form.post(props.aiReviewRoute, {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="space-y-3 rounded-lg border border-violet-200 bg-violet-50 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-violet-950">AI verification assist</p>
                <p class="mt-1 text-sm text-violet-900">
                    Reviews answers, options, relevance, and whether a diagram is required.
                    Auto-applies only high-confidence approve/skip — you still publish.
                </p>
                <p v-if="pendingCount > 0" class="mt-1 text-xs text-violet-800">
                    {{ pendingCount }} pending for AI review
                </p>
            </div>
            <PrimaryButton
                type="button"
                class="!bg-violet-700 hover:!bg-violet-800"
                :disabled="disabled || form.processing || pendingCount === 0"
                @click="runAiReview"
            >
                {{ form.processing ? 'AI reviewing…' : 'Run AI review' }}
            </PrimaryButton>
        </div>

        <div
            v-if="attention.length"
            class="rounded-md border border-amber-200 bg-white p-3"
        >
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">
                Needs your attention ({{ attention.length }})
            </p>
            <ul class="mt-2 max-h-48 space-y-1 overflow-y-auto text-sm text-slate-800">
                <li v-for="item in attention" :key="item.question_id">
                    <strong>Q{{ item.number }}</strong>
                    <span class="mx-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-900">
                        {{ item.verdict }}
                    </span>
                    <span class="text-xs text-slate-500">({{ item.confidence }})</span>
                    — {{ item.note }}
                </li>
            </ul>
        </div>
    </div>
</template>
