<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatScoreLabel } from '@/utils/scores';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    assignment: { type: Object, required: true },
});

const page = usePage();
const fileInput = ref(null);
const selectedFiles = ref([]);

const uploadForm = useForm({
    files: [],
});

const setLabel = computed(() => props.assignment.practice_set.set_code || 'Written sheet');
const submission = computed(() => props.assignment.submission);

const onFilesChange = (event) => {
    selectedFiles.value = [...(event.target.files || [])];
    uploadForm.files = selectedFiles.value;
};

const submitUpload = () => {
    uploadForm.post(route('student.written-assignments.upload', props.assignment.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            selectedFiles.value = [];
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
};

const formatDate = (value) => {
    if (!value) {
        return '—';
    }

    return new Date(String(value).includes('T') ? value : `${value}T00:00:00`).toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const statusLabel = computed(() => {
    const status = submission.value?.status;

    if (!status) {
        return 'Not uploaded';
    }

    return ({
        uploaded: 'Uploaded — waiting for AI',
        processing: 'AI is checking…',
        graded: 'Graded',
        failed: 'Checking failed — upload again',
    })[status] || status;
});
</script>

<template>
    <Head :title="setLabel" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-mono text-2xl font-bold text-indigo-600">{{ setLabel }}</p>
                    <p class="text-sm text-gray-500">{{ assignment.practice_set.kind_label }}</p>
                </div>
                <Link :href="route('dashboard')" class="text-sm text-indigo-600">Dashboard</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div class="grid gap-4 sm:grid-cols-3 text-sm">
                        <div>
                            <p class="text-xs text-gray-500">Target date</p>
                            <p class="font-semibold">{{ formatDate(assignment.target_date) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Sums</p>
                            <p class="font-semibold">{{ assignment.practice_set.questions_count }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Upload status</p>
                            <p class="font-semibold">{{ statusLabel }}</p>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <a
                            :href="assignment.practice_set.download_url"
                            class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                            target="_blank"
                        >
                            Download / print sheet
                        </a>
                    </div>

                    <p class="mt-4 text-sm text-gray-600">
                        Print the question sheet and do the sums on paper. Write every answer on a <strong>separate answer sheet</strong>
                        with the question number (Q1, Q2, …). Take a clear photo of your answer sheet and upload it below for AI checking.
                    </p>
                </div>

                <div
                    v-if="!submission || submission.status === 'failed' || (submission.status !== 'graded' && submission.status !== 'processing')"
                    class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200"
                >
                    <h3 class="font-medium text-gray-900">Upload completed work</h3>
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        multiple
                        class="mt-3 block w-full text-sm"
                        @change="onFilesChange"
                    >
                    <InputError :message="uploadForm.errors.files" class="mt-2" />
                    <InputError :message="uploadForm.errors['files.0']" class="mt-2" />
                    <PrimaryButton
                        class="mt-4"
                        :disabled="uploadForm.processing || !selectedFiles.length"
                        @click="submitUpload"
                    >
                        {{ uploadForm.processing ? 'Uploading…' : 'Upload for AI check' }}
                    </PrimaryButton>
                </div>

                <div v-if="submission?.status === 'processing' || submission?.status === 'uploaded'" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    AI is checking your work. Refresh this page in a minute for score and feedback.
                </div>

                <div v-if="submission?.status === 'failed'" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                    {{ submission.grading_error || 'Checking failed. Please upload again.' }}
                </div>

                <div v-if="submission?.status === 'graded'" class="space-y-4">
                    <div class="rounded-lg bg-indigo-50 p-6">
                        <p class="text-3xl font-bold text-indigo-700">
                            {{ formatScoreLabel(submission.score, submission.max_score) }}
                        </p>
                        <p class="text-sm text-gray-600">Overall score</p>
                        <p v-if="submission.ai_summary" class="mt-3 text-sm text-gray-800">{{ submission.ai_summary }}</p>
                    </div>

                    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left">Q</th>
                                    <th class="px-4 py-3 text-left">Your answer</th>
                                    <th class="px-4 py-3 text-left">Feedback</th>
                                    <th class="px-4 py-3 text-left">Score</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="item in submission.items" :key="item.question_number">
                                    <td class="px-4 py-3 font-semibold">{{ item.question_number }}</td>
                                    <td class="px-4 py-3">{{ item.extracted_answer || '—' }}</td>
                                    <td class="px-4 py-3">{{ item.step_feedback || '—' }}</td>
                                    <td class="px-4 py-3">
                                        {{ item.score }}/{{ item.max_score }}
                                        <span v-if="item.needs_review" class="text-xs text-amber-600"> · review</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
