<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    gemini: { type: Object, default: null },
    taskId: { type: Number, default: null },
    disabled: { type: Boolean, default: false },
    previewRoute: { type: String, required: true },
    applyRoute: { type: String, required: true },
});

const page = usePage();
const copiedPrompt = ref(false);
const copiedReference = ref(false);

const jsonForm = useForm({
    json: page.props.flash?.conversion_gemini_json || '',
});

const previewForm = useForm({
    json: '',
});

const applyForm = useForm({
    json: '',
});

const preview = computed(() => page.props.flash?.conversion_gemini_preview ?? null);

watch(
    () => page.props.flash?.conversion_gemini_json,
    (value) => {
        if (value) {
            jsonForm.json = value;
        }
    },
    { immediate: true },
);

const copyPrompt = async () => {
    if (!props.gemini?.prompt) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.gemini.prompt);
        copiedPrompt.value = true;
        window.setTimeout(() => {
            copiedPrompt.value = false;
        }, 2000);
    } catch {
        window.prompt('Copy this prompt into Gemini:', props.gemini.prompt);
    }
};

const copyReference = async () => {
    if (!props.gemini?.mcq_reference_json) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.gemini.mcq_reference_json);
        copiedReference.value = true;
        window.setTimeout(() => {
            copiedReference.value = false;
        }, 2000);
    } catch {
        window.prompt('Copy MCQ reference JSON for Gemini:', props.gemini.mcq_reference_json);
    }
};

const runPreview = () => {
    if (!jsonForm.json.trim()) {
        window.alert('Paste Gemini JSON first.');

        return;
    }

    previewForm.json = jsonForm.json;
    previewForm.post(props.previewRoute, {
        preserveScroll: true,
    });
};

const applyConversion = () => {
    const json = jsonForm.json.trim();

    if (!json) {
        window.alert('Paste Gemini JSON first.');

        return;
    }

    if (!preview.value) {
        window.alert('Preview first — check convertible vs MCQ-only lists.');

        return;
    }

    const msg = `Apply conversion?\n\n${preview.value.convertible_count} → fill-in-blank (ready)\n${preview.value.not_possible_count} → stay MCQ in this set`;

    if (!window.confirm(msg)) {
        return;
    }

    applyForm.json = json;
    applyForm.post(props.applyRoute, {
        preserveScroll: true,
        onSuccess: () => {
            jsonForm.json = '';
        },
    });
};
</script>

<template>
    <div v-if="gemini" class="space-y-4 rounded-lg border border-violet-200 bg-violet-50 p-4">
        <div>
            <p class="text-sm font-semibold text-violet-950">Gemini bulk conversion</p>
            <p class="mt-1 text-sm text-violet-900">
                Faster than one-by-one: Gemini checks all {{ gemini.question_count }} MCQs.
                Whole numbers and simple fractions (e.g. <strong>2/3</strong>) become fill-in-blank;
                words, true/false, and mixed fractions stay <strong>MCQ-only</strong> in the same test set.
            </p>
        </div>

        <ol class="space-y-2 text-sm text-violet-950">
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-violet-600 text-xs font-bold text-white">1</span>
                <span>Copy <strong>MCQ reference JSON</strong> and attach/paste into Gemini with the prompt.</span>
            </li>
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-violet-600 text-xs font-bold text-white">2</span>
                <span>Copy the <strong>conversion prompt</strong> into Gemini.</span>
            </li>
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-violet-600 text-xs font-bold text-white">3</span>
                <span>Paste Gemini’s JSON reply below → <strong>Preview split</strong> → <strong>Apply conversion</strong>.</span>
            </li>
        </ol>

        <div class="flex flex-wrap gap-2">
            <SecondaryButton type="button" :disabled="!gemini.mcq_reference_json" @click="copyReference">
                {{ copiedReference ? 'Reference copied!' : 'Copy MCQ reference JSON' }}
            </SecondaryButton>
            <SecondaryButton type="button" :disabled="!gemini.prompt" @click="copyPrompt">
                {{ copiedPrompt ? 'Prompt copied!' : 'Copy Gemini prompt' }}
            </SecondaryButton>
        </div>

        <details class="rounded-md border border-violet-100 bg-white p-3 text-xs text-slate-700">
            <summary class="cursor-pointer font-medium text-violet-900">Preview prompt</summary>
            <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap">{{ gemini.prompt }}</pre>
        </details>

        <div>
            <label for="gemini_fill_blank_json" class="text-sm font-medium text-violet-950">Paste Gemini JSON reply</label>
            <textarea
                id="gemini_fill_blank_json"
                v-model="jsonForm.json"
                rows="8"
                class="mt-1 block w-full rounded-md border-violet-200 font-mono text-xs shadow-sm focus:border-violet-500 focus:ring-violet-500"
                placeholder='{"questions":[{"source_index":1,"question":"... ____","answer_format":"integer","correct_answer":"42",...}]}'
                :disabled="disabled"
            />
            <InputError class="mt-1" :message="previewForm.errors.json || applyForm.errors.json" />
        </div>

        <div class="flex flex-wrap gap-2">
            <SecondaryButton
                type="button"
                :disabled="disabled || previewForm.processing || !jsonForm.json.trim()"
                @click="runPreview"
            >
                {{ previewForm.processing ? 'Previewing…' : 'Preview split' }}
            </SecondaryButton>
            <PrimaryButton
                type="button"
                class="!bg-violet-700 hover:!bg-violet-800"
                :disabled="disabled || applyForm.processing || !preview || !jsonForm.json.trim()"
                @click="applyConversion"
            >
                {{ applyForm.processing ? 'Applying…' : 'Apply conversion' }}
            </PrimaryButton>
        </div>

        <div v-if="preview" class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-md border border-emerald-200 bg-white p-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">
                    Convertible · {{ preview.convertible_count }}
                </p>
                <p class="mt-1 text-xs text-emerald-900">Ready as fill-in-blank when you apply.</p>
                <ul class="mt-2 max-h-56 space-y-2 overflow-y-auto text-sm text-slate-800">
                    <li
                        v-for="row in preview.convertible"
                        :key="`ok-${row.index}`"
                        class="rounded border border-emerald-100 bg-emerald-50/50 p-2"
                    >
                        <p class="font-semibold text-emerald-900">Q{{ row.number }}<span v-if="row.label"> · {{ row.label }}</span></p>
                        <p class="mt-1 text-xs text-slate-600">{{ row.fill_blank_question }}</p>
                        <p class="mt-1 text-xs font-medium text-emerald-800">Answer: {{ row.correct_answer }} ({{ row.answer_format }})</p>
                    </li>
                </ul>
            </div>

            <div class="rounded-md border border-amber-200 bg-white p-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">
                    Not possible · {{ preview.not_possible_count }}
                </p>
                <p class="mt-1 text-xs text-amber-900">Stay MCQ-only in this fill-in-blank test set.</p>
                <ul class="mt-2 max-h-56 space-y-2 overflow-y-auto text-sm text-slate-800">
                    <li
                        v-for="row in preview.not_possible"
                        :key="`skip-${row.index}`"
                        class="rounded border border-amber-100 bg-amber-50/50 p-2"
                    >
                        <p class="font-semibold text-amber-950">Q{{ row.number }}<span v-if="row.label"> · {{ row.label }}</span></p>
                        <p class="mt-1 text-xs text-slate-700">{{ row.mcq_question }}</p>
                        <p class="mt-1 text-xs text-amber-800">{{ row.reason }} · MCQ key: {{ row.mcq_answer || '—' }}</p>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
