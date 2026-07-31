<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { safeRoute } from '@/utils/routes';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    chapter: { type: Object, required: true },
    mcqImport: { type: Object, required: true },
});

const cloneItems = (items) => JSON.parse(JSON.stringify(items ?? []));

const page = usePage();
const items = ref(cloneItems(props.chapter.items));
const copied = ref(false);
const jsonInput = ref('');

const importForm = useForm({ json: '' });

const draftForm = useForm({ items: items.value });
const publishForm = useForm({ items: items.value });

const awaitingImport = computed(() => props.chapter.status === 'draft' && !items.value.length);
const canEdit = computed(() => ['review', 'published', 'failed'].includes(props.chapter.status) || items.value.length > 0);
const approvedCount = computed(() => items.value.filter((item) => item.approved !== false).length);

const syncForms = () => {
    draftForm.items = items.value;
    publishForm.items = items.value;
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
                        · MCQ set <strong>{{ chapter.mcq_set_code }}</strong>
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

                <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-5 text-sm text-indigo-950">
                    <h3 class="font-semibold">Textbook MCQ workflow</h3>
                    <ol class="mt-2 list-decimal space-y-1 pl-5">
                        <li>PDF is stored on the server (download link above — or upload the same PDF to Claude/Gemini).</li>
                        <li>Copy the AI prompt → paste in Cursor, Claude, or Gemini with the chapter PDF.</li>
                        <li>Paste the JSON reply below → <strong>Import MCQs</strong>.</li>
                        <li>Review → <strong>Publish</strong> as <strong>{{ chapter.mcq_set_code }}</strong>.</li>
                    </ol>
                </div>

                <div v-if="awaitingImport || canEdit" class="space-y-4 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
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
                        <summary class="cursor-pointer font-medium text-gray-800">Sample JSON format</summary>
                        <pre class="mt-2 overflow-x-auto rounded-md bg-gray-50 p-3 text-xs">{{ mcqImport.sample_json }}</pre>
                    </details>
                </div>

                <div v-if="awaitingImport || canEdit" class="space-y-4 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div>
                        <h3 class="font-semibold text-gray-900">Step 3 — Import MCQ JSON</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Paste AI output here (JSON only, or text with JSON — preamble lines are OK).
                            Publishing creates set <strong>{{ mcqImport.mcq_set_code }}</strong>.
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

                <div v-if="chapter.status === 'published'" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                    Published MCQ set <strong>{{ chapter.mcq_set_code }}</strong>.
                    Assign from
                    <Link :href="safeRoute('admin.classes.index', null, '/admin/classes')" class="font-semibold underline">Classes → Assign</Link>.
                </div>

                <div v-if="canEdit && items.length" class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-600">
                        {{ approvedCount }} of {{ items.length }} approved · then publish as {{ chapter.mcq_set_code }}.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <SecondaryButton :disabled="draftForm.processing" @click="saveDraft">Save draft</SecondaryButton>
                        <PrimaryButton :disabled="publishForm.processing || approvedCount === 0" @click="publish">
                            {{ chapter.status === 'published' ? 'Re-publish MCQ set' : 'Publish MCQ set' }}
                        </PrimaryButton>
                    </div>
                </div>

                <div v-if="canEdit && items.length" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left">Use</th>
                                <th class="px-3 py-2 text-left">Label</th>
                                <th class="px-3 py-2 text-left">Question</th>
                                <th class="px-3 py-2 text-left">Answer / explanation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="item in items" :key="item.id">
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
            </div>
        </div>
    </AuthenticatedLayout>
</template>
