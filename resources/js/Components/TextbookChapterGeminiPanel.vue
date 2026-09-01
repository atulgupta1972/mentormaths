<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    stagingGemini: { type: Object, default: () => ({}) },
    pasteRoute: { type: String, required: true },
    resetRoute: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

const page = usePage();
const copied = ref(false);

const form = useForm({
    gemini_paste: '',
});

const resetForm = useForm({});

const geminiReview = computed(() => page.props.flash?.staging_gemini_review ?? null);
const attention = computed(() => geminiReview.value?.attention ?? []);
const unparsedNumbers = computed(() => geminiReview.value?.unparsed_numbers ?? []);

const progressLabel = computed(() => {
    const verified = props.stagingGemini?.verified_count ?? 0;
    const total = props.stagingGemini?.total_count ?? 0;
    const pending = props.stagingGemini?.pending_count ?? 0;

    if (!total) {
        return '';
    }

    return `${verified}/${total} Gemini-checked · ${pending} pending`;
});

const copyPrompt = async () => {
    const prompt = props.stagingGemini?.prompt ?? '';

    if (!prompt) {
        return;
    }

    try {
        await navigator.clipboard.writeText(prompt);
        copied.value = true;
        window.setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        window.prompt('Copy this prompt into Gemini:', prompt);
    }
};

const applyPaste = () => {
    if (form.processing || props.disabled || !form.gemini_paste.trim()) {
        return;
    }

    form.post(props.pasteRoute, {
        preserveScroll: true,
        onSuccess: () => {
            form.gemini_paste = '';
        },
    });
};

const resetReview = () => {
    if (resetForm.processing || !props.resetRoute) {
        return;
    }

    if (!window.confirm('Reset Gemini review for all questions?')) {
        return;
    }

    resetForm.post(props.resetRoute, { preserveScroll: true });
};
</script>

<template>
    <div class="space-y-3 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-indigo-950">Step 4 — Gemini answer &amp; figure check</p>
                <p class="mt-1 text-sm text-indigo-900">
                    After import, copy the prompt into Gemini with the chapter PDF.
                    Paste the reply here — <strong>Correct</strong> answers are marked verified;
                    fix <strong>Needs Verification</strong> or missing figures, then run again.
                </p>
                <p v-if="progressLabel" class="mt-1 text-xs font-semibold text-indigo-800">
                    {{ progressLabel }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <SecondaryButton
                    v-if="resetRoute"
                    type="button"
                    class="!border-amber-300 !text-amber-900 hover:!bg-amber-50"
                    :disabled="disabled || resetForm.processing"
                    @click="resetReview"
                >
                    Reset check
                </SecondaryButton>
                <SecondaryButton type="button" :disabled="!stagingGemini?.prompt" @click="copyPrompt">
                    {{ copied ? 'Copied!' : 'Copy Gemini prompt' }}
                </SecondaryButton>
            </div>
        </div>

        <details class="rounded-md border border-indigo-100 bg-white p-3 text-sm text-slate-700">
            <summary class="cursor-pointer font-medium text-indigo-900">
                Preview prompt ({{ stagingGemini?.pending_count ?? 0 }} pending)
            </summary>
            <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap text-xs text-slate-800">{{ stagingGemini?.prompt || 'All questions checked.' }}</pre>
        </details>

        <div>
            <label for="staging_gemini_paste" class="text-sm font-medium text-indigo-950">Paste Gemini reply</label>
            <textarea
                id="staging_gemini_paste"
                v-model="form.gemini_paste"
                rows="8"
                class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                placeholder="Question 1 / Status: Correct / Figure: OK …"
            />
            <InputError class="mt-1" :message="form.errors.gemini_paste" />
        </div>

        <PrimaryButton
            type="button"
            class="!bg-indigo-700 hover:!bg-indigo-800"
            :disabled="disabled || form.processing || !form.gemini_paste.trim()"
            @click="applyPaste"
        >
            {{ form.processing ? 'Applying…' : 'Apply Gemini review' }}
        </PrimaryButton>

        <div v-if="attention.length" class="rounded-md border border-amber-200 bg-white p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">
                Needs fix ({{ attention.length }})
            </p>
            <ul class="mt-2 max-h-48 space-y-1 overflow-y-auto text-sm text-slate-800">
                <li v-for="item in attention" :key="item.number">
                    <strong>Q{{ item.number }}</strong>
                    <span v-if="item.figure" class="mx-1 text-xs text-amber-800">[{{ item.figure }}]</span>
                    — {{ item.note }}
                </li>
            </ul>
        </div>

        <div v-if="unparsedNumbers.length" class="rounded-md border border-slate-200 bg-white p-3 text-sm text-slate-700">
            <p class="text-xs font-semibold uppercase text-slate-600">Not found in paste</p>
            <p class="mt-1">Q{{ unparsedNumbers.join(', Q') }}</p>
        </div>
    </div>
</template>
