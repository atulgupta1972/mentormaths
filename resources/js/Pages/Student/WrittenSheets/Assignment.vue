<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatScoreLabel } from '@/utils/scores';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    assignment: { type: Object, required: true },
    upload_limits: {
        type: Object,
        default: () => ({ max_files: 15, max_file_mb: 20 }),
    },
});

const page = usePage();
const fileInput = ref(null);
const selectedFiles = ref([]);
const uploadError = ref('');
const uploadSubmitting = ref(false);

const uploadForm = useForm({
    files: [],
});

const setLabel = computed(() => props.assignment.practice_set.set_code || 'Written sheet');
const submission = computed(() => props.assignment.submission);
const uploadFiles = computed(() => submission.value?.upload_files || []);
const canUpload = computed(() => {
    const status = submission.value?.status;

    return !status || status === 'failed' || status === 'uploaded' || submission.value?.can_retry;
});
const isRevision = computed(() => submission.value?.status === 'graded' || (uploadFiles.value.length > 0 && submission.value?.can_retry));

const onFilesChange = (event) => {
    uploadError.value = '';
    selectedFiles.value = [...(event.target.files || [])];
    uploadForm.files = selectedFiles.value;

    if (selectedFiles.value.length > props.upload_limits.max_files) {
        uploadError.value = `Select up to ${props.upload_limits.max_files} files. You chose ${selectedFiles.value.length}.`;
    }
};

const submitUpload = () => {
    const inputFiles = fileInput.value?.files;
    const files = inputFiles?.length ? [...inputFiles] : selectedFiles.value;

    if (!files.length) {
        uploadError.value = 'Choose at least one photo or PDF.';

        return;
    }

    if (files.length > props.upload_limits.max_files) {
        uploadError.value = `Upload up to ${props.upload_limits.max_files} photos or PDFs at once. You chose ${files.length}.`;

        return;
    }

    uploadError.value = '';
    uploadSubmitting.value = true;

    const formData = new FormData();
    files.forEach((file) => formData.append('files[]', file));

    router.post(route('student.written-assignments.upload', props.assignment.id), formData, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            uploadSubmitting.value = false;
        },
        onSuccess: () => {
            selectedFiles.value = [];
            if (fileInput.value) {
                fileInput.value.value = '';
            }
            router.reload({ only: ['assignment'], preserveScroll: true });
        },
        onError: (errors) => {
            uploadError.value = errors.files
                || errors['files.0']
                || 'Upload failed. Try fewer or smaller photos.';
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
        uploaded: 'Uploaded — waiting to be marked',
        processing: 'Being checked…',
        graded: 'Graded',
        failed: 'Checking could not finish — your teacher will mark it',
    })[status] || status;
});

const checkingMessage = computed(() => {
    const minutes = submission.value?.checking_minutes ?? 0;

    if (!isAwaitingGrade.value) {
        return '';
    }

    if (minutes >= 10) {
        return 'This is taking longer than usual for a large upload. You can close this page — your teacher can mark it manually and your result will appear on the dashboard.';
    }

    if (minutes >= 3) {
        return 'Large photos can take a few minutes. This page refreshes automatically.';
    }

    return 'Checking usually finishes within a minute. This page refreshes automatically.';
});

const isAwaitingGrade = computed(() => {
    const status = submission.value?.status;

    return status === 'uploaded' || status === 'processing';
});

let pollTimer = null;

const refreshSubmission = () => {
    router.reload({
        only: ['assignment'],
        preserveScroll: true,
        preserveState: true,
    });
};

onMounted(() => {
    if (!isAwaitingGrade.value) {
        return;
    }

    pollTimer = window.setInterval(() => {
        if (!isAwaitingGrade.value) {
            window.clearInterval(pollTimer);
            pollTimer = null;

            return;
        }

        refreshSubmission();
    }, 8000);
});

onBeforeUnmount(() => {
    if (pollTimer) {
        window.clearInterval(pollTimer);
    }
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
                        with the question number (Q1, Q2, …). Take a clear <strong>photo</strong> (JPG/PNG) of your answer sheet and upload it below for AI checking.
                        PDF uploads also work if the server can convert PDF pages.
                    </p>
                </div>

                <div
                    v-if="uploadFiles.length"
                    class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-medium text-gray-900">Your uploaded answer sheet</h3>
                        <a
                            v-if="uploadFiles.length === 1"
                            :href="uploadFiles[0].url"
                            target="_blank"
                            class="text-sm text-indigo-600 hover:underline"
                        >
                            Open full size
                        </a>
                    </div>
                    <div class="mt-4 space-y-4">
                        <div
                            v-for="(file, index) in uploadFiles"
                            :key="file.url"
                            class="overflow-hidden rounded-md border border-gray-200 bg-gray-50"
                        >
                            <div class="flex items-center justify-between border-b border-gray-200 bg-white px-3 py-2">
                                <p class="text-xs font-medium text-gray-600">{{ file.label || `Page ${index + 1}` }}</p>
                                <a :href="file.url" target="_blank" class="text-xs text-indigo-600 hover:underline">Open</a>
                            </div>
                            <iframe
                                v-if="file.kind === 'pdf'"
                                :src="file.url"
                                class="h-[480px] w-full bg-white"
                                :title="file.label || `Upload ${index + 1}`"
                            />
                            <a v-else :href="file.url" target="_blank" class="block">
                                <img
                                    :src="file.url"
                                    :alt="file.label || `Upload ${index + 1}`"
                                    class="mx-auto max-h-[640px] w-auto max-w-full object-contain"
                                >
                            </a>
                        </div>
                    </div>
                </div>

                <div
                    v-if="canUpload"
                    class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200"
                >
                    <h3 class="font-medium text-gray-900">
                        {{ isRevision ? 'Upload revised answer sheet' : 'Upload completed work' }}
                    </h3>
                    <p class="mt-1 text-sm text-gray-600">
                        <template v-if="isRevision">
                            Upload a clearer or corrected photo/PDF. AI will check again, and your teacher can update marks.
                        </template>
                        <template v-else>
                            Take a clear photo (JPG/PNG) of your answer sheet, or upload a PDF.
                        </template>
                        Up to {{ upload_limits.max_files }} files, {{ upload_limits.max_file_mb }} MB each.
                    </p>
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        multiple
                        class="mt-3 block w-full text-sm"
                        @change="onFilesChange"
                    >
                    <p v-if="selectedFiles.length" class="mt-2 text-sm text-gray-700">
                        Selected: {{ selectedFiles.length }} file{{ selectedFiles.length === 1 ? '' : 's' }}
                    </p>
                    <InputError :message="uploadError" class="mt-2" />
                    <InputError :message="uploadForm.errors.files" class="mt-2" />
                    <InputError :message="uploadForm.errors['files.0']" class="mt-2" />
                    <PrimaryButton
                        class="mt-4"
                        :disabled="uploadSubmitting || !selectedFiles.length || submission?.status === 'processing'"
                        @click="submitUpload"
                    >
                        {{ uploadSubmitting
                            ? 'Uploading…'
                            : (isRevision ? 'Save revised upload for checking' : 'Upload for checking') }}
                    </PrimaryButton>
                </div>

                <div v-if="isAwaitingGrade" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    {{ checkingMessage }}
                </div>

                <div v-if="submission?.status === 'failed'" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                    {{ submission.grading_error || 'Checking could not finish. Your teacher can mark your work — you can also upload a clearer photo if needed.' }}
                </div>

                <div v-if="submission?.status === 'graded'" class="space-y-4">
                    <div class="rounded-lg bg-indigo-50 p-6">
                        <p class="text-3xl font-bold text-indigo-700">
                            {{ formatScoreLabel(submission.score, submission.max_score) }}
                        </p>
                        <p class="text-sm text-gray-600">Overall score</p>
                        <p v-if="submission.handwriting_label" class="mt-3 text-sm text-gray-800">
                            <span class="font-medium text-gray-700">Handwriting:</span>
                            {{ submission.handwriting_label }}
                        </p>
                        <p v-if="submission.teacher_remarks" class="mt-2 text-sm text-gray-800">
                            <span class="font-medium text-gray-700">Teacher remarks:</span>
                            {{ submission.teacher_remarks }}
                        </p>
                        <p v-else-if="submission.ai_summary" class="mt-3 text-sm text-gray-800">
                            <span class="font-medium text-gray-700">Feedback:</span>
                            {{ submission.ai_summary }}
                        </p>
                    </div>

                    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left">Q</th>
                                    <th class="px-4 py-3 text-left">Your answer</th>
                                    <th class="px-4 py-3 text-left">Correct answer</th>
                                    <th class="px-4 py-3 text-left">Result</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="item in submission.items" :key="item.question_number">
                                    <td class="px-4 py-3 font-semibold align-top">{{ item.question_number }}</td>
                                    <td class="px-4 py-3 align-top">{{ item.extracted_answer || '—' }}</td>
                                    <td class="px-4 py-3 align-top font-medium text-emerald-800">
                                        {{ item.correct_answer || '—' }}
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="item.is_correct ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'"
                                        >
                                            {{ item.is_correct ? '✓ Correct' : '✗ Wrong' }}
                                        </span>
                                        <p v-if="item.step_feedback && item.step_feedback !== 'Correct' && item.step_feedback !== 'Incorrect'" class="mt-1 text-xs text-gray-500">
                                            {{ item.step_feedback }}
                                        </p>
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
