<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { safeRoute } from '@/utils/routes';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    chapter: { type: Object, required: true },
});

const cloneItems = (items) => JSON.parse(JSON.stringify(items ?? []));

const page = usePage();
const items = ref(cloneItems(props.chapter.items));

watch(
    () => props.chapter.items,
    (newItems) => {
        items.value = cloneItems(newItems);
    },
    { deep: true },
);

const draftForm = useForm({
    items: items.value,
});

const publishForm = useForm({
    items: items.value,
});

const isExtracting = computed(() => props.chapter.status === 'extracting' || props.chapter.status === 'draft');
const canEdit = computed(() => ['review', 'published', 'failed'].includes(props.chapter.status));

const syncForms = () => {
    draftForm.items = items.value;
    publishForm.items = items.value;
};

const saveDraft = () => {
    syncForms();
    draftForm.post(safeRoute('admin.textbooks.draft', props.chapter.id, '#'), {
        preserveScroll: true,
        onSuccess: () => {
            items.value = cloneItems(page.props.chapter?.items ?? items.value);
        },
    });
};

const publish = () => {
    syncForms();
    publishForm.post(safeRoute('admin.textbooks.publish', props.chapter.id, '#'));
};

const reextract = () => {
    router.post(safeRoute('admin.textbooks.reextract', props.chapter.id, '#'));
};

const approvedCount = computed(() => items.value.filter((item) => item.approved !== false).length);

let pollTimer = null;

onMounted(() => {
    if (!isExtracting.value) {
        return;
    }

    pollTimer = window.setInterval(() => {
        router.reload({ only: ['chapter'], preserveScroll: true });
    }, 8000);
});

onBeforeUnmount(() => {
    if (pollTimer) {
        window.clearInterval(pollTimer);
    }
});
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

                <div v-if="isExtracting" class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
                    AI is reading the PDF (text + diagram pages). This usually takes 5–10 minutes.
                    You can leave — we will email you when extraction is ready to review.
                    This page also refreshes automatically if you keep it open.
                </div>

                <div v-if="chapter.status === 'failed'" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                    {{ chapter.extraction_error || 'Extraction failed.' }}
                    <SecondaryButton class="mt-3" @click="reextract">Try again</SecondaryButton>
                </div>

                <div v-if="chapter.status === 'published'" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                    Published.
                    <span v-if="chapter.mcq_set_code">MCQ set <strong>{{ chapter.mcq_set_code }}</strong>.</span>
                    <span v-if="chapter.written_set_code">Written set <strong>{{ chapter.written_set_code }}</strong>.</span>
                    Assign from
                    <Link :href="safeRoute('admin.classes.index', null, '/admin/classes')" class="font-semibold underline">Classes → Assign</Link>.
                    You can still edit questions below and re-publish.
                </div>

                <div v-if="canEdit && items.length" class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-600">
                        {{ approvedCount }} of {{ items.length }} approved · edit answers if AI was wrong, then publish.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <SecondaryButton @click="reextract">Re-extract PDF</SecondaryButton>
                        <SecondaryButton :disabled="draftForm.processing" @click="saveDraft">Save draft</SecondaryButton>
                        <PrimaryButton :disabled="publishForm.processing || approvedCount === 0" @click="publish">
                            {{ chapter.status === 'published' ? 'Re-publish sets' : 'Approve & publish' }}
                        </PrimaryButton>
                    </div>
                </div>

                <div v-if="canEdit && items.length" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left">Include</th>
                                <th class="px-3 py-2 text-left">Label</th>
                                <th class="px-3 py-2 text-left">Question</th>
                                <th class="px-3 py-2 text-left">Answer</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(item, index) in items" :key="item.id">
                                <td class="px-3 py-3 align-top">
                                    <label class="flex items-center gap-1 text-xs">
                                        <input v-model="item.approved" type="checkbox">
                                        Use
                                    </label>
                                    <label class="mt-1 flex items-center gap-1 text-xs text-gray-600">
                                        <input v-model="item.include_in_mcq" type="checkbox">
                                        MCQ
                                    </label>
                                    <label class="mt-1 flex items-center gap-1 text-xs text-gray-600">
                                        <input v-model="item.include_in_written" type="checkbox">
                                        Written
                                    </label>
                                    <p class="mt-1 text-[10px] uppercase text-gray-400">{{ item.kind }}</p>
                                </td>
                                <td class="px-3 py-3 align-top font-medium text-gray-800">
                                    {{ item.label }}
                                    <p v-if="item.source_page" class="text-xs font-normal text-gray-500">p. {{ item.source_page }}</p>
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <textarea
                                        v-model="item.question_text"
                                        rows="4"
                                        class="w-full min-w-[16rem] rounded-md border-gray-300 text-sm"
                                    />
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <input
                                        v-model="item.correct_answer"
                                        type="text"
                                        class="mb-2 w-full rounded-md border-gray-300 text-sm"
                                    >
                                    <textarea
                                        v-model="item.explanation"
                                        rows="2"
                                        placeholder="Explanation / working"
                                        class="w-full rounded-md border-gray-300 text-xs"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <InputError :message="draftForm.errors.items" class="px-4 py-2" />
                    <InputError :message="publishForm.errors.items" class="px-4 py-2" />
                </div>

                <div v-else-if="!isExtracting && !items.length" class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                    No extracted questions yet.
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
