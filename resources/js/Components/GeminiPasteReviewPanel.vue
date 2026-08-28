<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    runId: { type: Number, required: true },
    pendingCount: { type: Number, default: 0 },
    verifiedCount: { type: Number, default: 0 },
    totalCount: { type: Number, default: 0 },
    geminiPrompt: { type: String, default: '' },
    pasteRoute: { type: String, required: true },
    resetRoute: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

const page = usePage();
const copied = ref(false);

const form = useForm({
    run_id: props.runId,
    gemini_paste: '',
});

const resetForm = useForm({});

const geminiReview = computed(() => page.props.flash?.gemini_review ?? null);
const attention = computed(() => geminiReview.value?.attention ?? []);
const unparsedNumbers = computed(() => geminiReview.value?.unparsed_numbers ?? []);

const progressLabel = computed(() => {
    if (!props.totalCount) {
        return '';
    }

    return `${props.verifiedCount}/${props.totalCount} verified · ${props.pendingCount} pending`;
});

const copyPrompt = async () => {
    if (!props.geminiPrompt) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.geminiPrompt);
        copied.value = true;
        window.setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        window.prompt('Copy this prompt into Gemini:', props.geminiPrompt);
    }
};

const applyPaste = () => {
    if (form.processing || props.disabled || !props.pasteRoute || props.pasteRoute === '#') {
        return;
    }

    if (!form.gemini_paste.trim()) {
        window.alert('Paste Gemini’s reply first.');

        return;
    }

    const confirmMsg = props.pendingCount > 0
        ? `Apply Gemini review for up to ${props.pendingCount} pending question(s)?\n\nStatus: Correct → auto-verified\nStatus: Needs Verification → left for you to fix`
        : 'Apply Gemini review to any remaining pending questions?';

    if (!window.confirm(confirmMsg)) {
        return;
    }

    form.run_id = props.runId;
    form.post(props.pasteRoute, {
        preserveScroll: true,
        onSuccess: () => {
            form.gemini_paste = '';
        },
        onError: (errors) => {
            if (form.hasErrors && Object.keys(errors).length === 0) {
                window.alert('Could not apply Gemini review (server returned an error). If you just deployed, run: php artisan route:clear on the server, then try again.');
            }
        },
    });
};

const resetReview = () => {
    if (resetForm.processing || props.disabled || !props.resetRoute || props.resetRoute === '#') {
        return;
    }

    if (!window.confirm('Reset all verification ticks for this chapter?\n\nYou can then run Gemini check again from scratch. This does not email the uploader.')) {
        return;
    }

    resetForm.post(props.resetRoute, {
        preserveScroll: true,
        onError: () => {
            window.alert('Reset failed (server returned an error). If you just deployed, run: php artisan route:clear on the server, then try again.');
        },
    });
};
</script>

<template>
    <div class="space-y-3 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-indigo-950">Gemini answer check</p>
                <p class="mt-1 text-sm text-indigo-900">
                    Copy the chapter prompt into Gemini, paste the reply here.
                    Only <strong>pending</strong> questions go in the prompt — already Gemini-verified sums are skipped.
                    Questions marked <strong>Correct</strong> are auto-verified; fix the rest and run again.
                </p>
                <p v-if="totalCount > 0" class="mt-1 text-xs font-semibold text-indigo-800">
                    Progress: {{ progressLabel }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <SecondaryButton
                    v-if="resetRoute"
                    type="button"
                    class="!border-amber-300 !text-amber-900 hover:!bg-amber-50"
                    :disabled="disabled || resetForm.processing || totalCount === 0"
                    @click="resetReview"
                >
                    {{ resetForm.processing ? 'Resetting…' : 'Reset & check again' }}
                </SecondaryButton>
                <SecondaryButton
                    type="button"
                    :disabled="!geminiPrompt"
                    @click="copyPrompt"
                >
                    {{ copied ? 'Copied!' : 'Copy Gemini prompt' }}
                </SecondaryButton>
            </div>
        </div>

        <details class="rounded-md border border-indigo-100 bg-white p-3 text-sm text-slate-700">
            <summary class="cursor-pointer font-medium text-indigo-900">Preview prompt ({{ pendingCount }} questions)</summary>
            <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap text-xs text-slate-800">{{ geminiPrompt || 'All questions verified — nothing left to check.' }}</pre>
        </details>

        <div>
            <label for="gemini_paste" class="text-sm font-medium text-indigo-950">Paste Gemini reply</label>
            <textarea
                id="gemini_paste"
                v-model="form.gemini_paste"
                rows="8"
                class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                placeholder="Paste Gemini output here — e.g. Question 14 / Status: Correct …"
            />
            <InputError class="mt-1" :message="form.errors.gemini_paste" />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <PrimaryButton
                type="button"
                class="!bg-indigo-700 hover:!bg-indigo-800"
                :disabled="disabled || form.processing || pendingCount === 0 || !form.gemini_paste.trim()"
                @click="applyPaste"
            >
                {{ form.processing ? 'Applying…' : 'Apply Gemini review' }}
            </PrimaryButton>
            <p class="text-xs text-indigo-800">
                Fix flagged questions below, then copy prompt again — only corrected / pending sums are sent to Gemini.
            </p>
        </div>

        <div
            v-if="attention.length"
            class="rounded-md border border-amber-200 bg-white p-3"
        >
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">
                Needs your fix ({{ attention.length }})
            </p>
            <ul class="mt-2 max-h-48 space-y-1 overflow-y-auto text-sm text-slate-800">
                <li v-for="item in attention" :key="item.question_id">
                    <strong>Q{{ item.number }}</strong>
                    <span class="mx-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-900">
                        needs fix
                    </span>
                    — {{ item.note }}
                </li>
            </ul>
        </div>

        <div
            v-if="unparsedNumbers.length"
            class="rounded-md border border-slate-200 bg-white p-3 text-sm text-slate-700"
        >
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">
                Not found in paste ({{ unparsedNumbers.length }})
            </p>
            <p class="mt-1">
                Q{{ unparsedNumbers.join(', Q') }} — still pending. Include every question number in Gemini’s reply.
            </p>
        </div>
    </div>
</template>
