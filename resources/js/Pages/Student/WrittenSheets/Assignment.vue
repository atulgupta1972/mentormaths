<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import WrittenGradingProgressBar from '@/Components/WrittenGradingProgressBar.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { formatScoreLabel } from '@/utils/scores';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

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

const setLabel = computed(() => {
    const sheet = props.assignment.practice_set;
    const number = Number(sheet.set_number || 0);
    const code = sheet.set_code || '';

    if (number > 0 && code) {
        return `Sheet ${number} · ${code}`;
    }

    if (number > 0) {
        return `Sheet ${number}`;
    }

    return code || 'Written sheet';
});
const submission = computed(() => props.assignment.submission);
const uploadFiles = computed(() => submission.value?.upload_files || []);
const canUpload = computed(() => {
    const status = submission.value?.status;

    return !status || status === 'failed' || status === 'uploaded' || submission.value?.can_retry;
});

const showReuploadSection = computed(() => {
    const status = submission.value?.status;

    return submission.value?.can_retry && (status === 'graded' || status === 'failed');
});

const showInitialUpload = computed(() => canUpload.value && !showReuploadSection.value);

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
        uploaded: 'Submitted — being checked',
        processing: 'Being checked…',
        graded: 'Graded',
        failed: 'Checking could not finish — your teacher will mark it',
    })[status] || status;
});

const checkingMessage = computed(() => {
    if (!isAwaitingGrade.value) {
        return '';
    }

    return 'Your work is being checked. We will email you when it is ready — you can go to the dashboard and continue with other sets.';
});

const isAwaitingGrade = computed(() => {
    const status = submission.value?.status;

    return status === 'uploaded' || status === 'processing';
});

let checkingPollTimer = null;

watch(isAwaitingGrade, (checking) => {
    if (checking && !checkingPollTimer) {
        checkingPollTimer = window.setInterval(() => {
            router.reload({
                only: ['assignment'],
                preserveScroll: true,
            });
        }, 4000);
    } else if (!checking && checkingPollTimer) {
        window.clearInterval(checkingPollTimer);
        checkingPollTimer = null;
    }
}, { immediate: true });

onUnmounted(() => {
    if (checkingPollTimer) {
        window.clearInterval(checkingPollTimer);
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

                    <div class="mt-4 space-y-3 text-sm text-gray-600">
                        <p>
                            Print the question sheet and do the sums on paper. Write every answer on a
                            <strong>separate answer sheet</strong>, one below the other, in order:
                        </p>
                        <ul class="list-disc space-y-1 pl-5">
                            <li>Label each answer clearly — <strong>Q1</strong>, then <strong>Q2</strong>, then <strong>Q3</strong>, and so on.</li>
                            <li>Do not skip around the page — keep answers in question order so the photo matches the sheet.</li>
                            <li>If you need more than one photo, upload pages <strong>in order</strong> (page 1 first, then page 2, …).</li>
                        </ul>
                        <p>Take a clear <strong>photo</strong> (JPG/PNG) of your answer sheet and upload below for AI checking. PDF uploads also work.</p>
                    </div>
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
                    v-if="showInitialUpload"
                    class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200"
                >
                    <h3 class="font-medium text-gray-900">Upload completed work</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Take a clear photo (JPG/PNG) of your answer sheet with Q1, Q2, Q3… in order, or upload a PDF.
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
                        {{ uploadSubmitting ? 'Uploading…' : 'Upload for checking' }}
                    </PrimaryButton>
                </div>

                <div v-if="isAwaitingGrade" class="rounded-lg border border-violet-200 bg-violet-50 p-4 text-sm text-violet-900">
                    <p>{{ checkingMessage }}</p>
                    <WrittenGradingProgressBar
                        class="mt-3"
                        :progress="submission?.grading_progress ?? 0"
                        :stage="submission?.grading_stage ?? 'Checking…'"
                    />
                    <p v-if="showInitialUpload" class="mt-2 text-violet-800">
                        Wrong order on your sheet? Replace the upload above before checking finishes — use Q1, Q2, Q3… in order.
                    </p>
                    <Link
                        :href="route('dashboard')"
                        class="mt-3 inline-flex rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700"
                    >
                        Go to dashboard
                    </Link>
                </div>

                <div v-if="submission?.status === 'failed'" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                    {{ submission.grading_error || 'Checking could not finish. Your teacher can mark your work — you can also upload a clearer photo if needed.' }}
                </div>

                <div v-if="submission?.status === 'graded'" class="space-y-4">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                        <p class="font-medium">Answers out of order or AI misread your sheet?</p>
                        <p class="mt-1">
                            Re-upload below with every answer labelled and written in order — Q1, then Q2, then Q3, …
                            If you use more than one photo, select them in page order.
                        </p>
                    </div>

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

                <div
                    v-if="showReuploadSection"
                    class="rounded-lg border border-indigo-200 bg-white p-6 shadow-sm ring-1 ring-indigo-100"
                >
                    <h3 class="font-medium text-gray-900">Re-upload in order</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Write each answer on your answer sheet in question order, with clear labels (Q1, Q2, Q3, …).
                        Upload a new photo or PDF — this replaces your previous upload and AI will check again.
                    </p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600">
                        <li>One answer block per question, top to bottom in order.</li>
                        <li>Multiple photos: choose files in page order (page 1, then page 2, …).</li>
                    </ul>
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        multiple
                        class="mt-4 block w-full text-sm"
                        @change="onFilesChange"
                    >
                    <p v-if="selectedFiles.length" class="mt-2 text-sm text-gray-700">
                        Selected: {{ selectedFiles.length }} file{{ selectedFiles.length === 1 ? '' : 's' }}
                        <span v-if="selectedFiles.length > 1" class="text-gray-500">— we will check them in the order you selected</span>
                    </p>
                    <InputError :message="uploadError" class="mt-2" />
                    <InputError :message="uploadForm.errors.files" class="mt-2" />
                    <InputError :message="uploadForm.errors['files.0']" class="mt-2" />
                    <PrimaryButton
                        class="mt-4"
                        :disabled="uploadSubmitting || !selectedFiles.length || submission?.status === 'processing'"
                        @click="submitUpload"
                    >
                        {{ uploadSubmitting ? 'Uploading…' : 'Re-upload in order for checking' }}
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
