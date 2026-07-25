<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formulaPreviewRowsToJson, parseFormulaImportPreview } from '@/utils/formulaImportPreview';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    topic: { type: Object, required: true },
    sampleJson: { type: String, default: '' },
    cursorPrompt: { type: String, default: null },
    promptDefaults: { type: Object, default: () => ({}) },
});

const page = usePage();
const showImport = ref(false);
const copied = ref(false);
const promptBox = ref(null);
const previewRows = ref([]);
const previewError = ref('');

const cursorPromptText = computed(() => props.cursorPrompt || page.props.flash?.formula_bank_topic_prompt || '');

const setForm = useForm({ title: '' });
const promptForm = useForm({
    total: props.promptDefaults?.total || 8,
    focus: props.promptDefaults?.focus || '',
    style: props.promptDefaults?.style || 'mixed',
});
const importForm = useForm({
    json: '',
    create_set: true,
    worksheet_id: null,
});
const packageForm = useForm({});

watch(
    () => cursorPromptText.value,
    (value) => {
        if (value) {
            copied.value = false;
            showImport.value = true;
        }
    },
);

const createSet = () => {
    setForm.post(route('admin.formula-bank.topics.sets.store', props.topic.id), {
        preserveScroll: true,
        onSuccess: () => setForm.reset(),
    });
};

const generatePrompt = () => {
    promptForm.post(route('admin.formula-bank.topics.prompt', props.topic.id), {
        preserveScroll: true,
    });
};

const copyPrompt = async () => {
    if (!cursorPromptText.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(cursorPromptText.value);
        copied.value = true;
    } catch {
        promptBox.value?.select();
        document.execCommand('copy');
        copied.value = true;
    }
};

const discardPreview = () => {
    previewRows.value = [];
    previewError.value = '';
};

const loadPreview = () => {
    const result = parseFormulaImportPreview(importForm.json, [
        { name: props.topic.name },
    ]);
    previewError.value = result.error || '';
    // Topic page: all cards belong here even without topic field.
    previewRows.value = result.rows.map((row) => ({
        ...row,
        topic: row.topic || props.topic.name,
        topic_matched: true,
    }));
};

const removePreviewRow = (index) => {
    previewRows.value = previewRows.value.filter((_, i) => i !== index);
};

const setCorrectOption = (rowIndex, optionIndex) => {
    const row = previewRows.value[rowIndex];
    if (row) {
        row.correct_index = optionIndex;
    }
};

const submitImport = () => {
    if (!previewRows.value.length) {
        previewError.value = 'Preview formulas first, then save.';

        return;
    }

    importForm.json = formulaPreviewRowsToJson(previewRows.value);
    importForm.post(route('admin.formula-bank.topics.import', props.topic.id), {
        preserveScroll: true,
        onSuccess: () => {
            importForm.json = '';
            discardPreview();
        },
    });
};

const packageUnpacked = () => {
    packageForm.post(route('admin.formula-bank.topics.package', props.topic.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Formula · ${topic.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-indigo-600">Formula bank</p>
                    <h2 class="text-xl font-semibold text-gray-800">{{ topic.name }}</h2>
                    <p class="text-sm text-gray-500">
                        {{ topic.grade?.name }} · {{ topic.board?.code }} · {{ topic.chapter?.name }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 text-sm">
                    <Link
                        v-if="topic.grade?.id && topic.board?.id"
                        :href="route('admin.formula-bank.index', { board_id: topic.board.id, grade_id: topic.grade.id })"
                        class="font-medium text-amber-800 hover:underline"
                    >
                        ← Formula summary
                    </Link>
                    <Link
                        v-if="topic.chapter?.id"
                        :href="route('admin.formula-bank.chapters.show', topic.chapter.id)"
                        class="text-gray-600 hover:underline"
                    >
                        Chapter formulas
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
                >
                    {{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash?.error"
                    class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
                >
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg border border-amber-200 bg-amber-50/50 p-5 shadow-sm">
                    <h3 class="font-medium text-amber-950">1. Generate Cursor prompt</h3>
                    <p class="mt-1 text-sm text-amber-900">
                        Describe which formulas / concepts / True-False you want. Prompt forbids calculation sums.
                    </p>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="How many cards?" class="!text-xs" />
                            <input
                                v-model.number="promptForm.total"
                                type="number"
                                min="1"
                                max="40"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            >
                            <InputError :message="promptForm.errors.total" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Card style" class="!text-xs" />
                            <select v-model="promptForm.style" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                <option value="mixed">Formulas + concepts + True/False</option>
                                <option value="formula_recall">Formulas / identities only</option>
                                <option value="concept">Concepts / definitions only</option>
                                <option value="true_false">True / False only</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <InputLabel value="Describe formulas / concepts to cover (optional)" class="!text-xs" />
                        <textarea
                            v-model="promptForm.focus"
                            rows="4"
                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            placeholder="e.g. (a+b)², (a−b)², a²−b² · integer sign rules · perimeter vs area of rectangle/square · when to use each identity"
                        />
                        <InputError :message="promptForm.errors.focus" class="mt-1" />
                    </div>

                    <PrimaryButton
                        type="button"
                        class="mt-3 !bg-amber-700 hover:!bg-amber-800"
                        :disabled="promptForm.processing"
                        @click="generatePrompt"
                    >
                        {{ promptForm.processing ? 'Building…' : 'Generate Cursor prompt' }}
                    </PrimaryButton>

                    <div v-if="cursorPromptText" class="mt-4 rounded-md border border-amber-200 bg-white p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">2. Copy → paste in Cursor</p>
                            <SecondaryButton type="button" class="!py-1 !text-xs" @click="copyPrompt">
                                {{ copied ? 'Copied!' : 'Copy prompt' }}
                            </SecondaryButton>
                        </div>
                        <textarea
                            ref="promptBox"
                            :value="cursorPromptText"
                            readonly
                            rows="12"
                            class="mt-2 w-full rounded-md border-gray-300 font-mono text-xs"
                            @focus="$event.target.select()"
                        />
                        <p class="mt-1 text-xs text-gray-500">Then paste Cursor’s JSON below in step 3.</p>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-gray-900">Formula sets</h3>
                            <p class="text-xs text-gray-500">{{ topic.formulas_count }} cards in this topic · Set 1, Set 2, …</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="showImport = !showImport">
                                {{ showImport || cursorPromptText ? 'Hide import' : '3. Import JSON' }}
                            </SecondaryButton>
                            <PrimaryButton type="button" class="!py-1.5 !text-xs" :disabled="setForm.processing" @click="createSet">
                                New empty set
                            </PrimaryButton>
                        </div>
                    </div>

                    <div v-if="showImport || cursorPromptText" class="mt-4 space-y-3 rounded-md border border-indigo-100 bg-indigo-50/40 p-4">
                        <p class="text-sm text-indigo-950">
                            3. Paste JSON → <strong>Preview</strong> → verify → <strong>Save</strong>.
                        </p>
                        <label class="flex items-center gap-2 text-sm text-gray-800">
                            <input v-model="importForm.create_set" type="checkbox" class="rounded border-gray-300">
                            Create a new set and attach these cards when saving
                        </label>
                        <textarea
                            v-model="importForm.json"
                            rows="10"
                            class="w-full rounded-md border-gray-300 font-mono text-xs"
                            placeholder='{"questions":[{"question":"...","options":["A","B","C","D"],"correct_index":1}]}'
                            @input="discardPreview"
                        />
                        <InputError :message="importForm.errors.json" />
                        <p v-if="previewError" class="text-sm text-rose-700">{{ previewError }}</p>
                        <div class="flex flex-wrap gap-2">
                            <SecondaryButton type="button" class="!py-1.5 !text-xs" :disabled="!importForm.json.trim()" @click="loadPreview">
                                Preview formulas
                            </SecondaryButton>
                            <PrimaryButton
                                type="button"
                                class="!py-1.5 !text-xs"
                                :disabled="importForm.processing || !previewRows.length"
                                @click="submitImport"
                            >
                                {{ importForm.processing ? 'Saving…' : `Save ${previewRows.length || ''} verified formula${previewRows.length === 1 ? '' : 's'}` }}
                            </PrimaryButton>
                            <SecondaryButton v-if="previewRows.length" type="button" class="!py-1.5 !text-xs" @click="discardPreview">
                                Discard preview
                            </SecondaryButton>
                        </div>

                        <div v-if="previewRows.length" class="space-y-3 pt-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-800">
                                Preview ({{ previewRows.length }}) — verify before saving
                            </p>
                            <div
                                v-for="(row, rowIndex) in previewRows"
                                :key="row.key"
                                class="rounded-md bg-white p-3 ring-1 ring-gray-200"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-medium text-gray-900">{{ rowIndex + 1 }}. {{ row.question }}</p>
                                    <button type="button" class="text-xs text-rose-600 hover:underline" @click="removePreviewRow(rowIndex)">
                                        Remove
                                    </button>
                                </div>
                                <ul class="mt-2 space-y-1 text-sm">
                                    <li v-for="(option, optionIndex) in row.options" :key="`${row.key}-${optionIndex}`">
                                        <button
                                            type="button"
                                            class="w-full rounded-md px-2 py-1 text-left"
                                            :class="row.correct_index === optionIndex
                                                ? 'bg-emerald-50 font-medium text-emerald-900'
                                                : 'bg-gray-50 text-gray-700'"
                                            @click="setCorrectOption(rowIndex, optionIndex)"
                                        >
                                            {{ String.fromCharCode(65 + optionIndex) }}. {{ option }}
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div v-if="topic.sets?.length" class="mt-4 divide-y divide-gray-100 overflow-hidden rounded-md border border-gray-200">
                        <Link
                            v-for="set in topic.sets"
                            :key="set.id"
                            :href="route('admin.formula-bank.sets.show', set.id)"
                            class="flex items-center justify-between gap-3 bg-white px-4 py-3 text-sm hover:bg-gray-50"
                        >
                            <div>
                                <p class="font-semibold text-gray-900">{{ set.set_code }} · Set {{ set.set_number }}</p>
                                <p class="text-xs text-gray-500">{{ set.title }}</p>
                            </div>
                            <span class="text-xs text-gray-500">{{ set.questions_count }} cards</span>
                        </Link>
                    </div>
                    <p v-else class="mt-4 text-sm text-gray-500">No formula sets yet — generate a prompt, import JSON, or create an empty set.</p>
                </div>

                <div
                    v-if="topic.unpacked_formulas?.length"
                    class="rounded-lg border border-amber-200 bg-amber-50/50 p-5"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-amber-950">Unpacked cards ({{ topic.unpacked_formulas.length }})</h3>
                            <p class="text-xs text-amber-900">Imported but not in a set yet.</p>
                        </div>
                        <PrimaryButton type="button" class="!py-1.5 !text-xs" :disabled="packageForm.processing" @click="packageUnpacked">
                            Package into new set
                        </PrimaryButton>
                    </div>
                    <ul class="mt-3 space-y-2 text-sm text-gray-800">
                        <li v-for="q in topic.unpacked_formulas" :key="q.id" class="rounded-md bg-white px-3 py-2 ring-1 ring-amber-100">
                            {{ q.question_text }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
