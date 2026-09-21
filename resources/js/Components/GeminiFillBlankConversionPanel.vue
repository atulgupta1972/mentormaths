<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { copyTextToClipboard } from '@/utils/clipboard';
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
const copiedRewritePrompt = ref(false);
const copiedRewriteReference = ref(false);

const jsonForm = useForm({
    json: page.props.flash?.conversion_gemini_json || '',
});

const rewriteForm = useForm({
    json: page.props.flash?.conversion_rewrite_json || '',
});

const previewForm = useForm({
    json: '',
});

const applyForm = useForm({
    json: '',
});

const preview = computed(() => page.props.flash?.conversion_gemini_preview ?? null);
const hasBlocked = computed(() => (preview.value?.blocked_count || 0) > 0);

watch(
    () => page.props.flash?.conversion_gemini_json,
    (value) => {
        if (value) {
            jsonForm.json = value;
        }
    },
    { immediate: true },
);

watch(
    () => page.props.flash?.conversion_rewrite_json,
    (value) => {
        if (value) {
            rewriteForm.json = value;
        }
    },
    { immediate: true },
);

const copyText = async (text, flagRef, fallbackLabel) => {
    if (!text) {
        return;
    }

    const result = await copyTextToClipboard(text);

    if (result.ok) {
        flagRef.value = true;
        window.setTimeout(() => {
            flagRef.value = false;
        }, 2000);

        return;
    }

    window.prompt(fallbackLabel, text);
};

const copyPrompt = () => copyText(props.gemini?.prompt, copiedPrompt, 'Copy this prompt into Gemini:');
const copyReference = () => copyText(
    props.gemini?.mcq_reference_json,
    copiedReference,
    'Copy MCQ reference JSON for Gemini:',
);
const copyRewritePrompt = () => copyText(
    preview.value?.rewrite_prompt,
    copiedRewritePrompt,
    'Copy rewrite prompt into Gemini:',
);
const copyRewriteReference = () => copyText(
    preview.value?.rewrite_reference_json,
    copiedRewriteReference,
    'Copy blocked reference JSON for Gemini:',
);

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

const runRewritePreview = () => {
    if (!rewriteForm.json.trim()) {
        window.alert('Paste the rewritten blocked JSON first.');

        return;
    }

    previewForm.json = rewriteForm.json;
    previewForm.post(props.previewRoute, {
        preserveScroll: true,
        onSuccess: () => {
            // Keep rewrite JSON visible after preview.
        },
    });
};

const applyPayload = (json, label) => {
    if (!preview.value) {
        window.alert('Preview first — check convertible vs blocked lists.');

        return;
    }

    if ((preview.value.convertible_count || 0) < 1) {
        window.alert('No convertible rows yet. Fix missing ____ / rewrite wording+numbers in Gemini, then Preview again.');

        return;
    }

    const blocked = preview.value.blocked_count || 0;
    let msg = `${label}\n\n${preview.value.convertible_count} → fill-in-blank`;
    if (blocked > 0) {
        msg += `\n${blocked} still blocked → use Rewrite blocked pack below`;
    }

    if (!window.confirm(msg)) {
        return;
    }

    applyForm.json = json;
    applyForm.post(props.applyRoute, {
        preserveScroll: true,
        onSuccess: () => {
            jsonForm.json = '';
            rewriteForm.json = '';
        },
    });
};

const applyConversion = () => {
    const json = jsonForm.json.trim();
    if (!json) {
        window.alert('Paste Gemini JSON first.');

        return;
    }

    applyPayload(json, props.gemini?.is_mentormaths
        ? 'Apply MentorMaths transform?'
        : 'Apply conversion?');
};

const applyRewrite = () => {
    const json = rewriteForm.json.trim();
    if (!json) {
        window.alert('Paste rewritten blocked JSON first.');

        return;
    }

    applyPayload(json, 'Apply rewritten blocked rows? (keeps already-saved blanks)');
};
</script>

<template>
    <div v-if="gemini" class="space-y-4 rounded-lg border border-violet-200 bg-violet-50 p-4">
        <div>
            <p class="text-sm font-semibold text-violet-950">
                {{ gemini.is_mentormaths ? 'MentorMaths Gemini transform' : 'Gemini bulk conversion' }}
            </p>
            <p class="mt-1 text-sm text-violet-900">
                <template v-if="gemini.is_mentormaths">
                    Rewrite every stem, change numbers/names, strip publisher wording.
                    Only numeric fill-in-blanks are kept — similarity gate rejects near-copies.
                </template>
                <template v-else>
                    Faster than one-by-one: Gemini checks all {{ gemini.question_count }} MCQs.
                    Whole numbers and simple fractions (e.g. <strong>2/3</strong>) become fill-in-blank;
                    words, true/false, and mixed fractions stay <strong>MCQ-only</strong> in the same test set.
                </template>
            </p>
        </div>

        <ol class="space-y-2 text-sm text-violet-950">
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-violet-600 text-xs font-bold text-white">1</span>
                <span>
                    Copy <strong>{{ gemini.is_mentormaths ? 'source reference JSON' : 'MCQ reference JSON' }}</strong>
                    and attach/paste into Gemini with the prompt.
                </span>
            </li>
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-violet-600 text-xs font-bold text-white">2</span>
                <span>Copy the <strong>{{ gemini.is_mentormaths ? 'transform prompt' : 'conversion prompt' }}</strong> into Gemini.</span>
            </li>
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-violet-600 text-xs font-bold text-white">3</span>
                <span>Paste Gemini’s JSON reply below → <strong>Preview split</strong> → <strong>Apply conversion</strong>.</span>
            </li>
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-rose-600 text-xs font-bold text-white">4</span>
                <span>If rows are blocked: use <strong>Rewrite blocked pack</strong> → Gemini again → paste rewrite JSON → Apply rewrite.</span>
            </li>
        </ol>

        <div class="flex flex-wrap gap-2">
            <SecondaryButton type="button" :disabled="!gemini.mcq_reference_json" @click="copyReference">
                {{ copiedReference
                    ? 'Reference copied!'
                    : (gemini.is_mentormaths ? 'Copy source reference JSON' : 'Copy MCQ reference JSON') }}
            </SecondaryButton>
            <SecondaryButton type="button" :disabled="!gemini.prompt" @click="copyPrompt">
                {{ copiedPrompt
                    ? 'Prompt copied!'
                    : (gemini.is_mentormaths ? 'Copy transform prompt' : 'Copy Gemini prompt') }}
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

            <div class="space-y-4">
                <div
                    v-if="hasBlocked"
                    class="rounded-md border border-rose-200 bg-white p-3"
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-rose-900">
                        Blocked · {{ preview.blocked_count }}
                    </p>
                    <p class="mt-1 text-xs text-rose-900">
                        Missing ____ / too similar / unchanged numbers.
                        Apply green rows now, then use the rewrite pack below for these.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <SecondaryButton type="button" class="!text-xs" @click="copyRewriteReference">
                            {{ copiedRewriteReference ? 'Blocked JSON copied!' : 'Copy blocked reference JSON' }}
                        </SecondaryButton>
                        <SecondaryButton type="button" class="!text-xs" @click="copyRewritePrompt">
                            {{ copiedRewritePrompt ? 'Rewrite prompt copied!' : 'Copy rewrite prompt' }}
                        </SecondaryButton>
                    </div>
                    <ul class="mt-2 max-h-40 space-y-2 overflow-y-auto text-sm text-slate-800">
                        <li
                            v-for="row in preview.blocked"
                            :key="`block-${row.index}`"
                            class="rounded border border-rose-100 bg-rose-50/50 p-2"
                        >
                            <p class="font-semibold text-rose-900">Q{{ row.number }}</p>
                            <p class="mt-1 text-xs text-rose-800">{{ row.reason }}</p>
                        </li>
                    </ul>
                </div>

                <div class="rounded-md border border-amber-200 bg-white p-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">
                        Not possible · {{ preview.not_possible_count }}
                    </p>
                    <p class="mt-1 text-xs text-amber-900">
                        {{ preview.is_mentormaths ? 'Skipped on this MentorMaths set.' : 'Stay MCQ-only in this fill-in-blank test set.' }}
                    </p>
                    <ul class="mt-2 max-h-56 space-y-2 overflow-y-auto text-sm text-slate-800">
                        <li
                            v-for="row in preview.not_possible"
                            :key="`skip-${row.index}`"
                            class="rounded border border-amber-100 bg-amber-50/50 p-2"
                        >
                            <p class="font-semibold text-amber-900">Q{{ row.number }}<span v-if="row.label"> · {{ row.label }}</span></p>
                            <p class="mt-1 text-xs text-amber-800">{{ row.reason }}</p>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div
            v-if="hasBlocked && preview?.rewrite_prompt"
            class="space-y-3 rounded-lg border-2 border-rose-300 bg-rose-50 p-4"
        >
            <div>
                <p class="text-sm font-semibold text-rose-950">Rewrite blocked rows (second pass)</p>
                <p class="mt-1 text-sm text-rose-900">
                    1) Copy blocked reference JSON + rewrite prompt → Gemini.
                    2) Paste Gemini’s fixed JSON here.
                    3) Preview → Apply rewrite (keeps blanks you already saved).
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <SecondaryButton type="button" @click="copyRewriteReference">
                    {{ copiedRewriteReference ? 'Blocked JSON copied!' : 'Copy blocked reference JSON' }}
                </SecondaryButton>
                <SecondaryButton type="button" @click="copyRewritePrompt">
                    {{ copiedRewritePrompt ? 'Rewrite prompt copied!' : 'Copy rewrite prompt' }}
                </SecondaryButton>
            </div>

            <details class="rounded-md border border-rose-100 bg-white p-3 text-xs text-slate-700">
                <summary class="cursor-pointer font-medium text-rose-900">Preview rewrite prompt</summary>
                <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap">{{ preview.rewrite_prompt }}</pre>
            </details>

            <div>
                <label for="gemini_rewrite_json" class="text-sm font-medium text-rose-950">Paste rewritten blocked JSON</label>
                <textarea
                    id="gemini_rewrite_json"
                    v-model="rewriteForm.json"
                    rows="8"
                    class="mt-1 block w-full rounded-md border-rose-200 font-mono text-xs shadow-sm focus:border-rose-500 focus:ring-rose-500"
                    placeholder='{"questions":[{"source_index":14,"question":"... ____",...}]}'
                    :disabled="disabled"
                />
            </div>

            <div class="flex flex-wrap gap-2">
                <SecondaryButton
                    type="button"
                    :disabled="disabled || previewForm.processing || !rewriteForm.json.trim()"
                    @click="runRewritePreview"
                >
                    {{ previewForm.processing ? 'Previewing…' : 'Preview rewrite' }}
                </SecondaryButton>
                <PrimaryButton
                    type="button"
                    class="!bg-rose-700 hover:!bg-rose-800"
                    :disabled="disabled || applyForm.processing || !preview || !rewriteForm.json.trim()"
                    @click="applyRewrite"
                >
                    {{ applyForm.processing ? 'Applying…' : 'Apply rewrite' }}
                </PrimaryButton>
            </div>
        </div>
    </div>
</template>
