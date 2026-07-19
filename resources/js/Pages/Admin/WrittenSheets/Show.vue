<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    sheet: { type: Object, required: true },
});

const page = usePage();
const regenerateForm = useForm({});
const verifyForm = useForm({});
const rejectForm = useForm({});

const regenerate = () => {
    regenerateForm.post(route('admin.written-sheets.regenerate', props.sheet.id), { preserveScroll: true });
};

const verify = () => {
    if (!confirm('Verify this sheet? Students can be assigned after verification.')) {
        return;
    }

    verifyForm.post(route('admin.written-sheets.verify', props.sheet.id), { preserveScroll: true });
};

const reject = () => {
    rejectForm.post(route('admin.written-sheets.reject', props.sheet.id), { preserveScroll: true });
};
</script>

<template>
    <Head :title="sheet.set_code" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        <span class="font-mono text-indigo-600">{{ sheet.set_code }}</span>
                        · {{ sheet.kind_label }}
                    </h2>
                    <p class="text-sm text-gray-500">{{ sheet.chapter_name }}<span v-if="sheet.topic_name"> · {{ sheet.topic_name }}</span></p>
                </div>
                <Link :href="route('admin.written-sheets.index')" class="text-sm text-indigo-600">Back</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700">
                            {{ sheet.written_status_label }}
                        </span>
                        <span class="text-sm text-gray-600">{{ sheet.questions_count }} sums</span>
                        <a
                            v-if="sheet.written_pdf_url"
                            :href="route('admin.written-sheets.download', sheet.id)"
                            class="text-sm font-medium text-indigo-600 hover:underline"
                            target="_blank"
                        >
                            Download PDF
                        </a>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <PrimaryButton
                            v-if="sheet.written_status === 'pending_review'"
                            type="button"
                            :disabled="verifyForm.processing"
                            @click="verify"
                        >
                            Verify sheet
                        </PrimaryButton>
                        <SecondaryButton type="button" :disabled="regenerateForm.processing" @click="regenerate">
                            Regenerate PDF
                        </SecondaryButton>
                        <DangerButton
                            v-if="sheet.written_status !== 'draft'"
                            type="button"
                            :disabled="rejectForm.processing"
                            @click="reject"
                        >
                            Send back to draft
                        </DangerButton>
                    </div>

                    <p class="mt-3 text-sm text-gray-600">
                        Step 2: check the PDF below. Step 3: after verify, assign from the class matrix like any other set.
                    </p>
                </div>

                <div v-if="sheet.written_pdf_url" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <iframe :src="sheet.written_pdf_url" class="h-[720px] w-full" title="Written sheet preview" />
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <h3 class="font-medium text-gray-900">Questions on this sheet</h3>
                    <ol class="mt-3 space-y-3">
                        <li v-for="question in sheet.questions" :key="question.id" class="text-sm">
                            <span class="font-semibold text-gray-900">Q{{ question.number }}.</span>
                            <span class="text-gray-700" v-html="question.question_text" />
                            <div class="mt-1 text-xs text-gray-500">Answer: {{ question.correct_answer || '—' }}</div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
