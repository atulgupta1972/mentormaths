<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { safeRoute } from '@/utils/routes';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    chapter: { type: Object, required: true },
    mcqImport: { type: Object, required: true },
});

const cloneItems = (items) => JSON.parse(JSON.stringify(items ?? []));
const clonePlan = (plan) => JSON.parse(JSON.stringify(plan ?? []));

const page = usePage();
const items = ref(cloneItems(props.chapter.items));
const setPlan = ref(clonePlan(props.chapter.mcq_set_plan));
const copied = ref(false);
const jsonInput = ref('');

const importForm = useForm({ json: '' });

const draftForm = useForm({ items: items.value, mcq_set_plan: setPlan.value });
const publishForm = useForm({ items: items.value, mcq_set_plan: setPlan.value });

const syncForms = () => {
    draftForm.items = items.value;
    publishForm.items = items.value;
    draftForm.mcq_set_plan = setPlan.value;
    publishForm.mcq_set_plan = setPlan.value;
};

const applyFromProps = () => {
    items.value = cloneItems(props.chapter.items);
    setPlan.value = clonePlan(props.chapter.mcq_set_plan);
    syncForms();
};

applyFromProps();

watch(
    () => [props.chapter.items, props.chapter.mcq_set_plan],
    () => {
        applyFromProps();
    },
    { deep: true },
);

watch(
    () => props.chapter.status,
    () => {
        applyFromProps();
    },
);

const hasItems = computed(() => items.value.length > 0);
const awaitingImport = computed(() => props.chapter.status === 'draft' && !hasItems.value);
const canEdit = computed(() => ['review', 'published', 'failed'].includes(props.chapter.status) || hasItems.value);
const showImportSteps = computed(() => awaitingImport.value || (canEdit.value && !hasItems.value));
const approvedCount = computed(() => items.value.filter((item) => item.approved !== false).length);
const mcqBaseSetCode = computed(() => props.mcqImport.mcq_set_code || props.chapter.mcq_set_code?.replace(/M\d+$/, 'M'));

const mcqPublishSummary = computed(() => {
    const plan = setPlan.value ?? [];

    if (plan.length === 0) {
        return mcqBaseSetCode.value;
    }

    if (plan.length === 1) {
        const row = plan[0];
        const label = row.description ? ` (${row.description})` : '';

        return `${row.set_code}${label} · Q${row.q_from}–${row.q_to}`;
    }

    const counts = plan.map((row) => Number(row.q_to) - Number(row.q_from) + 1).join('+');
    const codes = plan.map((row) => row.set_code).join(', ');

    return `${plan.length} sets (${counts}): ${codes}`;
});

const publishedMcqSetCodes = computed(() => {
    if (props.chapter.mcq_set_codes?.length) {
        return props.chapter.mcq_set_codes;
    }

    return props.chapter.mcq_set_code ? [props.chapter.mcq_set_code] : [];
});

const resetToSingleSet = () => {
    setPlan.value = [{
        set_code: mcqBaseSetCode.value,
        q_from: 1,
        q_to: items.value.length || 1,
        description: '',
    }];
    syncForms();
};

const addSetPlanRow = () => {
    const total = items.value.length || 1;

    if (setPlan.value.length === 1) {
        const row = setPlan.value[0];
        const coversAll = Number(row.q_from) === 1 && Number(row.q_to) >= total;

        if (coversAll && total > 1) {
            const firstEnd = Math.min(15, total - 1);

            setPlan.value = [
                {
                    set_code: `${mcqBaseSetCode.value}1`,
                    q_from: 1,
                    q_to: firstEnd,
                    description: row.description || '',
                },
                {
                    set_code: `${mcqBaseSetCode.value}2`,
                    q_from: firstEnd + 1,
                    q_to: total,
                    description: '',
                },
            ];
            syncForms();

            return;
        }
    }

    const nextPart = setPlan.value.length + 1;
    const lastTo = setPlan.value.length ? Number(setPlan.value[setPlan.value.length - 1].q_to) : 0;
    const qFrom = Math.min(lastTo + 1, total);
    const qTo = total;

    setPlan.value.push({
        set_code: `${mcqBaseSetCode.value}${nextPart}`,
        q_from: qFrom,
        q_to: qTo,
        description: '',
    });
    syncForms();
};

const removeSetPlanRow = (index) => {
    setPlan.value.splice(index, 1);
    syncForms();
};

const copyPrompt = async () => {
    await navigator.clipboard.writeText(props.mcqImport.prompt || '');
    copied.value = true;
    window.setTimeout(() => {
        copied.value = false;
    }, 2000);
};

const importMcq = () => {
    importForm.json = jsonInput.value;
    importForm.post(safeRoute('admin.textbooks.import-mcq', props.chapter.id, '#'), {
        preserveScroll: true,
        onSuccess: () => {
            jsonInput.value = '';
            applyFromProps();
        },
    });
};

const resetImport = () => {
    if (!confirm('Clear imported MCQs and start over?')) {
        return;
    }

    router.post(safeRoute('admin.textbooks.reset-import', props.chapter.id, '#'));
};

const saveDraft = () => {
    syncForms();
    draftForm.post(safeRoute('admin.textbooks.draft', props.chapter.id, '#'), { preserveScroll: true });
};

const publish = () => {
    syncForms();
    publishForm.post(safeRoute('admin.textbooks.publish', props.chapter.id, '#'));
};
</script>

<template>
    <Head :title="`Textbook Ch ${chapter.chapter_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        {{ chapter.book?.grade_name || 'Class' }} · {{ chapter.book?.name || 'Textbook' }}
                    </h2>
                    <p class="text-sm text-gray-500">
                        Ch {{ chapter.chapter_number }} — {{ chapter.title }}
                        · {{ chapter.status_label }}
                        · MCQ {{ mcqPublishSummary }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a
                        v-if="chapter.pdf_url"
                        :href="safeRoute('admin.textbooks.download', chapter.id, chapter.pdf_url)"
                        class="text-sm text-indigo-600 hover:underline"
                    >
                        Download PDF
                    </a>
                    <Link :href="safeRoute('admin.textbooks.index', null, '/admin/textbooks')" class="text-sm text-gray-600 hover:underline">All chapters</Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div v-if="hasItems && canEdit" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    <strong>Review {{ items.length }} MCQ(s)</strong> — use the set plan matrix below.
                    Small chapter (~25)? Keep <strong>one row</strong> covering Q1–{{ items.length }}.
                    Large chapter? Add rows and set q_from / q_to per class (e.g. AP, GP).
                    <SecondaryButton type="button" class="ml-3 !py-1 !text-xs" @click="resetImport">
                        Re-import JSON
                    </SecondaryButton>
                </div>

                <div v-if="canEdit && hasItems" class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-600">
                        {{ approvedCount }} of {{ items.length }} approved · publish as {{ mcqPublishSummary }}.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <SecondaryButton :disabled="draftForm.processing" @click="saveDraft">Save draft</SecondaryButton>
                        <PrimaryButton :disabled="publishForm.processing || approvedCount === 0" @click="publish">
                            {{ chapter.status === 'published' ? 'Re-publish MCQ sets' : 'Publish MCQ sets' }}
                        </PrimaryButton>
                    </div>
                </div>

                <div v-if="canEdit && hasItems" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="font-semibold text-gray-900">MCQ set plan</h3>
                                <p class="text-xs text-gray-500">
                                    You decide how questions split into assignable sets. Default after import: one set for all {{ items.length }} questions.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <SecondaryButton type="button" class="!py-1 !text-xs" @click="resetToSingleSet">
                                    One set (all)
                                </SecondaryButton>
                                <SecondaryButton type="button" class="!py-1 !text-xs" @click="addSetPlanRow">
                                    Add row / split
                                </SecondaryButton>
                            </div>
                        </div>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left">Set code</th>
                                <th class="px-3 py-2 text-left">Q from</th>
                                <th class="px-3 py-2 text-left">Q to</th>
                                <th class="px-3 py-2 text-left">Description</th>
                                <th class="px-3 py-2 text-right">Count</th>
                                <th class="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(row, index) in setPlan" :key="index">
                                <td class="px-3 py-2">
                                    <input v-model="row.set_code" type="text" class="w-full min-w-[12rem] rounded-md border-gray-300 font-mono text-xs">
                                </td>
                                <td class="px-3 py-2">
                                    <input v-model.number="row.q_from" type="number" min="1" class="w-20 rounded-md border-gray-300 text-sm">
                                </td>
                                <td class="px-3 py-2">
                                    <input v-model.number="row.q_to" type="number" min="1" class="w-20 rounded-md border-gray-300 text-sm">
                                </td>
                                <td class="px-3 py-2">
                                    <input v-model="row.description" type="text" placeholder="AP, GP, …" class="w-full min-w-[6rem] rounded-md border-gray-300 text-sm">
                                </td>
                                <td class="px-3 py-2 text-right text-gray-500">
                                    {{ Math.max(0, Number(row.q_to) - Number(row.q_from) + 1) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button
                                        v-if="setPlan.length > 1"
                                        type="button"
                                        class="text-xs text-rose-600 hover:underline"
                                        @click="removeSetPlanRow(index)"
                                    >
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <InputError :message="draftForm.errors.mcq_set_plan" class="px-4 py-2" />
                    <InputError :message="publishForm.errors.mcq_set_plan" class="px-4 py-2" />
                </div>

                <div v-if="canEdit && hasItems" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left">#</th>
                                <th class="px-3 py-2 text-left">Use</th>
                                <th class="px-3 py-2 text-left">Label</th>
                                <th class="px-3 py-2 text-left">Question</th>
                                <th class="px-3 py-2 text-left">Answer / explanation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(item, index) in items" :key="item.id">
                                <td class="px-3 py-3 align-top text-xs font-medium text-gray-400">{{ index + 1 }}</td>
                                <td class="px-3 py-3 align-top">
                                    <label class="flex items-center gap-1 text-xs">
                                        <input v-model="item.approved" type="checkbox">
                                        Include
                                    </label>
                                    <p v-if="item.difficulty" class="mt-1 text-[10px] uppercase text-gray-400">{{ item.difficulty }}</p>
                                </td>
                                <td class="px-3 py-3 align-top font-medium text-gray-800">{{ item.label }}</td>
                                <td class="px-3 py-3 align-top">
                                    <textarea v-model="item.question_text" rows="3" class="w-full min-w-[16rem] rounded-md border-gray-300 text-sm" />
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <input v-model="item.correct_answer" type="text" class="mb-2 w-full rounded-md border-gray-300 text-sm">
                                    <textarea v-model="item.explanation" rows="2" class="w-full rounded-md border-gray-300 text-xs" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <InputError :message="draftForm.errors.items" class="px-4 py-2" />
                    <InputError :message="publishForm.errors.items" class="px-4 py-2" />
                </div>

                <div v-if="chapter.status === 'published'" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                    Published MCQ sets:
                    <strong>{{ publishedMcqSetCodes.join(', ') }}</strong>.
                    Assign each part from class to class via
                    <Link :href="safeRoute('admin.classes.index', null, '/admin/classes')" class="font-semibold underline">Classes → Assign</Link>.
                </div>

                <div v-if="!hasItems" class="rounded-lg border border-indigo-200 bg-indigo-50 p-5 text-sm text-indigo-950">
                    <h3 class="font-semibold">Textbook MCQ workflow</h3>
                    <ol class="mt-2 list-decimal space-y-1 pl-5">
                        <li>PDF is stored on the server (download link above — or upload the same PDF to Claude/Gemini).</li>
                        <li>Copy the AI prompt → paste in Cursor, Claude, or Gemini with the chapter PDF.</li>
                        <li>Paste the JSON reply below → <strong>Import MCQs</strong>.</li>
                        <li>Edit the <strong>set plan matrix</strong> on the review page (one set for small chapters, split for large ones) → <strong>Publish</strong>.</li>
                    </ol>
                </div>

                <div v-if="showImportSteps" class="space-y-4 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-900">Step 2 — AI prompt</h3>
                            <p class="mt-1 text-sm text-gray-600">Copy this into Claude/Cursor/Gemini along with the chapter PDF.</p>
                        </div>
                        <SecondaryButton type="button" @click="copyPrompt">
                            {{ copied ? 'Copied!' : 'Copy prompt' }}
                        </SecondaryButton>
                    </div>
                    <textarea
                        :value="mcqImport.prompt"
                        rows="12"
                        readonly
                        class="w-full rounded-md border-gray-200 bg-gray-50 font-mono text-xs text-gray-800"
                    />

                    <details class="text-sm text-gray-600">
                        <summary class="cursor-pointer font-medium text-gray-800">Sample JSON format (questions only)</summary>
                        <pre class="mt-2 overflow-x-auto rounded-md bg-gray-50 p-3 text-xs">{{ mcqImport.sample_json }}</pre>
                    </details>
                </div>

                <div v-if="showImportSteps" class="space-y-4 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div>
                        <h3 class="font-semibold text-gray-900">Step 3 — Import MCQ JSON</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Paste AI output here (JSON with <strong>questions</strong> only).
                            After import, split into sets using the matrix on this page.
                        </p>
                    </div>
                    <textarea
                        v-model="jsonInput"
                        rows="10"
                        class="w-full rounded-md border-gray-300 font-mono text-xs"
                        placeholder='{"questions": [ ... ]}'
                    />
                    <InputError :message="importForm.errors.json" />
                    <div class="flex flex-wrap gap-2">
                        <PrimaryButton type="button" :disabled="importForm.processing || !jsonInput.trim()" @click="importMcq">
                            {{ importForm.processing ? 'Importing…' : 'Import MCQs' }}
                        </PrimaryButton>
                        <SecondaryButton v-if="items.length" type="button" @click="resetImport">
                            Clear &amp; re-import
                        </SecondaryButton>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
