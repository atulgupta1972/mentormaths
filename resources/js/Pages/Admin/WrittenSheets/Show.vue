<script setup>
import WorksheetPdfViewer from '@/Components/WorksheetPdfViewer.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { formatScoreLabel } from '@/utils/scores';
import {
    defaultFillBlankRow,
    fillBlankFormats,
    parseFillBlankJson,
} from '@/utils/fillBlankImport';

const props = defineProps({
    sheet: { type: Object, required: true },
    topics: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
    selectedStudentId: { type: [Number, null], default: null },
    focusAssignmentId: { type: [Number, null], default: null },
    studentProgress: { type: Object, default: null },
    assignments: { type: Array, default: () => [] },
    activeYear: { type: Object, default: null },
    gradeLevels: { type: Array, default: () => [] },
    uploadLimits: {
        type: Object,
        default: () => ({ max_files: 15, max_file_mb: 20 }),
    },
});

const page = usePage();
const regenerateForm = useForm({});
const verifyForm = useForm({});
const rejectForm = useForm({});
const replacePdfForm = useForm({ pdf_import_token: '' });
const removePdfForm = useForm({});
const reimportZipForm = useForm({ pack: null });
const reimportJsonForm = useForm({ manual_questions: [] });

const replacePdfInput = ref(null);
const reimportZipInput = ref(null);
const reimportJsonFileInput = ref(null);
const reimportJsonText = ref('');
const reimportJsonError = ref('');
const selectedReplacePdfName = ref('');
const replacePdfPreviewUrl = ref('');
const replacePdfStaging = ref(false);
const replacePdfError = ref('');
const replacePdfToken = ref('');

const defaultTargetDate = () => {
    const d = new Date();
    d.setDate(d.getDate() + 7);
    return d.toISOString().slice(0, 10);
};

const selectedStudent = ref(props.selectedStudentId || '');
const targetDate = ref(defaultTargetDate());
const bulkTargetDate = ref(defaultTargetDate());
const assignStudentId = ref('');
const selectedGradeLevelId = ref('');
const selectedStudentIds = ref([]);
const assignNotes = ref('');

const assignForm = useForm({ student_id: '', target_date: '', notes: '' });
const bulkForm = useForm({ student_ids: [], target_date: '', notes: '' });
const reassignForm = useForm({ target_date: '', notes: '' });
const gradeForm = useForm({ feedback: '', remarks: '', handwriting_rating: '', items: [] });
const gradingAssignmentId = ref(null);
const revisionForm = useForm({ files: [], skip_ai: false });
const revisionFileInput = ref(null);
const revisionSelectedFiles = ref([]);
const revisionUploadError = ref('');
const revisionUploading = ref(false);

const findAssignmentRow = (assignmentId) =>
    props.assignments.find((item) => item.assignment_id === assignmentId)
    || (props.studentProgress?.assignment_id === assignmentId ? props.studentProgress : null);

const reloadAssignmentRow = (assignmentId) => {
    router.reload({
        only: ['assignments', 'studentProgress'],
        preserveScroll: true,
        onFinish: () => {
            const row = findAssignmentRow(assignmentId);
            if (row) {
                openGrade(row);
            }
        },
    });
};

const handwritingOptions = [
    { value: 'very_good', label: 'Very good' },
    { value: 'good', label: 'Good' },
    { value: 'ok', label: 'OK' },
    { value: 'poor', label: 'Poor' },
    { value: 'very_poor', label: 'Very poor' },
];
const showAnswerEditor = ref(false);
const answerPdfInput = ref(null);
const answerPdfParsing = ref(false);
const answerPdfError = ref('');
const answerPdfWarnings = ref([]);
const answersForm = useForm({
    answers: [],
});

const openAnswerEditor = () => {
    answersForm.answers = (props.sheet.questions || []).map((question) => ({
        correct_answer: question.correct_answer || '',
        answer_format: question.answer_format || 'text',
    }));
    answerPdfError.value = '';
    answerPdfWarnings.value = [];
    showAnswerEditor.value = true;
};

const cancelAnswerEditor = () => {
    showAnswerEditor.value = false;
    answersForm.reset();
    answersForm.clearErrors();
    answerPdfError.value = '';
    answerPdfWarnings.value = [];
    if (answerPdfInput.value) {
        answerPdfInput.value.value = '';
    }
};

const parseAnswerPdfForSheet = async () => {
    const file = answerPdfInput.value?.files?.[0];
    if (!file) {
        answerPdfError.value = 'Choose an answer sheet PDF first.';

        return;
    }

    answerPdfParsing.value = true;
    answerPdfError.value = '';
    answerPdfWarnings.value = [];

    try {
        const formData = new FormData();
        formData.append('pdf', file);
        formData.append('expected_count', String(props.sheet.questions_count || props.sheet.questions?.length || 0));
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const response = await fetch(route('admin.written-sheets.parse-answer-pdf'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
            },
            body: formData,
        });
        const payload = await response.json();

        if (!response.ok) {
            answerPdfError.value = payload.error || 'Could not read the answer sheet PDF.';

            return;
        }

        const parsed = payload.answer_key || [];
        answersForm.answers = (props.sheet.questions || []).map((question, index) => ({
            correct_answer: parsed[index]?.correct_answer || answersForm.answers[index]?.correct_answer || '',
            answer_format: parsed[index]?.answer_format || answersForm.answers[index]?.answer_format || 'text',
        }));
        answerPdfWarnings.value = payload.warnings || [];

        if (parsed.length !== (props.sheet.questions || []).length) {
            answerPdfWarnings.value = [
                ...answerPdfWarnings.value,
                `Parsed ${parsed.length} answers; this sheet has ${(props.sheet.questions || []).length}. Check and edit before saving.`,
            ];
        }
    } catch {
        answerPdfError.value = 'Could not read the answer sheet PDF.';
    } finally {
        answerPdfParsing.value = false;
    }
};

const submitAnswers = () => {
    answersForm.post(route('admin.written-sheets.update-answers', props.sheet.id), {
        preserveScroll: true,
        onSuccess: () => cancelAnswerEditor(),
    });
};

const gradeSheetQuestions = computed(() => props.sheet.questions || []);

const gradeCorrectCount = computed(() =>
    gradeForm.items.filter((item) => item.is_correct === true).length,
);

const gradeMarkedCount = computed(() =>
    gradeForm.items.filter((item) => item.is_correct === true || item.is_correct === false).length,
);

const allQuestionsMarked = computed(() =>
    gradeForm.items.length > 0 && gradeMarkedCount.value === gradeForm.items.length,
);

const openGrade = (row) => {
    gradingAssignmentId.value = row.assignment_id;

    const existingById = {};
    (row.question_results || []).forEach((result) => {
        existingById[result.question_id] = result;
    });

    gradeForm.remarks = row.teacher_remarks || row.written_feedback || '';
    gradeForm.feedback = gradeForm.remarks;
    gradeForm.handwriting_rating = row.handwriting_rating || '';
    gradeForm.items = gradeSheetQuestions.value.map((question) => {
        const existing = existingById[question.id];

        return {
            question_id: question.id,
            is_correct: existing ? existing.is_correct : null,
            note: existing?.note && existing.note !== 'Correct' && existing.note !== 'Incorrect'
                ? existing.note
                : '',
        };
    });
    gradeForm.clearErrors();
};

const cancelGrade = () => {
    gradingAssignmentId.value = null;
    gradeForm.reset();
    gradeForm.clearErrors();
    revisionSelectedFiles.value = [];
    revisionForm.reset();
    revisionForm.skip_ai = false;
    revisionForm.clearErrors();
    if (revisionFileInput.value) {
        revisionFileInput.value.value = '';
    }
};

const onRevisionFilesChange = (event) => {
    revisionUploadError.value = '';
    revisionSelectedFiles.value = [...(event.target.files || [])];
    revisionForm.files = revisionSelectedFiles.value;

    if (revisionSelectedFiles.value.length > props.uploadLimits.max_files) {
        revisionUploadError.value = `Select up to ${props.uploadLimits.max_files} files. You chose ${revisionSelectedFiles.value.length}.`;
    }
};

const submitRevisionUpload = (assignmentId) => {
    const inputFiles = revisionFileInput.value?.files;
    const files = inputFiles?.length ? [...inputFiles] : revisionSelectedFiles.value;

    if (!assignmentId || !files.length) {
        revisionUploadError.value = 'Choose at least one photo or PDF.';

        return;
    }

    if (files.length > props.uploadLimits.max_files) {
        revisionUploadError.value = `Upload up to ${props.uploadLimits.max_files} photos or PDFs at once. You chose ${files.length}.`;

        return;
    }

    revisionUploadError.value = '';
    revisionUploading.value = true;

    const formData = new FormData();
    files.forEach((file) => formData.append('files[]', file));
    if (revisionForm.skip_ai) {
        formData.append('skip_ai', '1');
    }

    router.post(route('admin.written-assignments.upload-work', assignmentId), formData, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            revisionUploading.value = false;
        },
        onSuccess: () => {
            revisionSelectedFiles.value = [];
            revisionForm.skip_ai = false;
            if (revisionFileInput.value) {
                revisionFileInput.value.value = '';
            }
            reloadAssignmentRow(assignmentId);
        },
        onError: (errors) => {
            revisionUploadError.value = errors.files
                || errors['files.0']
                || 'Upload failed. If you chose many pages, try again after deploy — or tick Skip AI and save.';
        },
    });
};

const setQuestionResult = (questionId, isCorrect) => {
    const item = gradeForm.items.find((row) => row.question_id === questionId);
    if (item) {
        item.is_correct = isCorrect;
    }
};

const markAllCorrect = () => {
    gradeForm.items.forEach((item) => {
        item.is_correct = true;
    });
};

const markAllWrong = () => {
    gradeForm.items.forEach((item) => {
        item.is_correct = false;
    });
};

const submitGrade = () => {
    if (!gradingAssignmentId.value) {
        return;
    }

    if (!allQuestionsMarked.value) {
        gradeForm.setError('items', 'Tick every question as correct or wrong.');

        return;
    }

    if (!gradeForm.handwriting_rating) {
        gradeForm.setError('handwriting_rating', 'Choose a handwriting rating.');

        return;
    }

    gradeForm.transform((data) => ({
        remarks: data.remarks || data.feedback || null,
        handwriting_rating: data.handwriting_rating,
        items: data.items.map((item) => ({
            question_id: item.question_id,
            is_correct: item.is_correct === true,
            note: item.note || null,
        })),
    })).post(route('admin.written-assignments.manual-grade', gradingAssignmentId.value), {
        preserveScroll: true,
        onSuccess: () => cancelGrade(),
    });
};

const filteredStudents = computed(() => {
    if (!selectedGradeLevelId.value) {
        return [];
    }

    return props.students.filter(
        (student) => String(student.grade_level_id) === String(selectedGradeLevelId.value),
    );
});

const existingByStudentId = computed(() => {
    const map = {};

    props.assignments.forEach((row) => {
        map[row.student_id] = row;
    });

    return map;
});

const selectAllFiltered = () => {
    selectedStudentIds.value = filteredStudents.value.map((student) => student.id);
};

const clearSelectedStudents = () => {
    selectedStudentIds.value = [];
};

const toggleStudent = (studentId) => {
    const index = selectedStudentIds.value.indexOf(studentId);

    if (index === -1) {
        selectedStudentIds.value.push(studentId);
    } else {
        selectedStudentIds.value.splice(index, 1);
    }
};

watch(selectedGradeLevelId, (value) => {
    if (value) {
        selectAllFiltered();
    } else {
        clearSelectedStudents();
    }
});

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

const onStudentChange = () => {
    router.get(
        route('admin.written-sheets.show', props.sheet.id),
        { student_id: selectedStudent.value || undefined },
        { preserveState: true, preserveScroll: true },
    );
};

onMounted(() => {
    if (!props.focusAssignmentId) {
        return;
    }

    const row = findAssignmentRow(props.focusAssignmentId);
    if (row) {
        openGrade(row);
    }
});

const assignSheet = () => {
    assignForm.student_id = assignStudentId.value;
    assignForm.target_date = targetDate.value;
    assignForm.post(route('admin.practice-sets.assign', props.sheet.id), { preserveScroll: true });
};

const assignSelected = () => {
    bulkForm.student_ids = selectedStudentIds.value;
    bulkForm.target_date = bulkTargetDate.value;
    bulkForm.notes = assignNotes.value;
    bulkForm.post(route('admin.practice-sets.assign-students', props.sheet.id), { preserveScroll: true });
};

const reassign = (assignmentId) => {
    if (!confirm('Re-assign this sheet? Student can upload again with a new target date.')) {
        return;
    }

    reassignForm.target_date = targetDate.value;
    reassignForm.post(route('admin.set-assignments.reassign', assignmentId), { preserveScroll: true });
};

const onReplacePdfSelected = (event) => {
    const file = event.target.files?.[0] ?? null;
    selectedReplacePdfName.value = file?.name ?? '';
    replacePdfError.value = '';
    replacePdfToken.value = '';
    replacePdfPreviewUrl.value = '';
};

const stageReplacementPdf = async () => {
    const file = replacePdfInput.value?.files?.[0];

    if (!file) {
        replacePdfError.value = 'Choose a replacement PDF first.';

        return;
    }

    replacePdfStaging.value = true;
    replacePdfError.value = '';

    try {
        const formData = new FormData();
        formData.append('pdf', file);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const response = await fetch(route('admin.written-sheets.stage-pdf'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
            },
            body: formData,
        });
        const payload = await response.json();

        if (!response.ok) {
            replacePdfError.value = payload.error || 'Could not upload PDF.';

            return;
        }

        replacePdfToken.value = payload.token;
        replacePdfPreviewUrl.value = payload.pdf_url;
    } catch {
        replacePdfError.value = 'Could not upload PDF.';
    } finally {
        replacePdfStaging.value = false;
    }
};

const submitReplacePdf = () => {
    if (!replacePdfToken.value) {
        replacePdfError.value = 'Upload the replacement PDF first.';

        return;
    }

    if (!confirm('Replace the worksheet PDF? Students who have not uploaded yet will download the new file.')) {
        return;
    }

    replacePdfForm.pdf_import_token = replacePdfToken.value;
    replacePdfForm.post(route('admin.written-sheets.replace-pdf', props.sheet.id), {
        preserveScroll: true,
        onSuccess: () => {
            selectedReplacePdfName.value = '';
            replacePdfToken.value = '';
            replacePdfPreviewUrl.value = '';
            if (replacePdfInput.value) {
                replacePdfInput.value.value = '';
            }
        },
    });
};

const clearSheet = () => {
    if (!confirm('Clear this sheet completely? The PDF and all questions will be removed so you can start over.')) {
        return;
    }

    removePdfForm.post(route('admin.written-sheets.remove-pdf', props.sheet.id), { preserveScroll: true });
};

const onReimportZipSelected = (event) => {
    reimportZipForm.pack = event.target.files?.[0] ?? null;
};

const defaultTopicName = computed(() => props.sheet.topic_name || '');

const validTopicIds = computed(() => new Set(props.topics.map((topic) => String(topic.id))));

const resolveTopicIdForRow = (row) => {
    if (row.syllabus_topic_id) {
        return row.syllabus_topic_id;
    }

    const name = String(row.topic_name || row.topic || '').trim().toLowerCase();
    if (!name) {
        return null;
    }

    return props.topics.find((topic) => topic.name.toLowerCase() === name)?.id ?? null;
};

const sanitizeManualRow = (row) => {
    const topicId = row.syllabus_topic_id ? String(row.syllabus_topic_id) : '';
    const answerFormat = fillBlankFormats.includes(row.answer_format) ? row.answer_format : 'text';

    return {
        ...row,
        answer_format: answerFormat,
        syllabus_topic_id: validTopicIds.value.has(topicId) ? Number(topicId) : '',
    };
};

const buildManualRowsFromJson = (text) => parseFillBlankJson(text).map((row) => {
    const topicId = resolveTopicIdForRow(row);
    const topicName = row.topic_name
        || props.topics.find((topic) => topic.id === topicId)?.name
        || defaultTopicName.value;

    return sanitizeManualRow({
        ...defaultFillBlankRow(topicName),
        ...row,
        topic_name: topicName,
        syllabus_topic_id: topicId || '',
    });
});

const onReimportJsonFileSelected = async (event) => {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }

    reimportJsonError.value = '';

    try {
        reimportJsonText.value = await file.text();
    } catch {
        reimportJsonError.value = 'Could not read the JSON file.';
    }
};

const submitReimportJson = () => {
    reimportJsonError.value = '';

    if (!reimportJsonText.value.trim()) {
        reimportJsonError.value = 'Paste JSON or choose a .json file first.';

        return;
    }

    let rows;

    try {
        rows = buildManualRowsFromJson(reimportJsonText.value);
    } catch (error) {
        reimportJsonError.value = error.message || 'Could not parse JSON.';

        return;
    }

    if (!rows.length) {
        reimportJsonError.value = 'Add at least one question in the JSON.';

        return;
    }

    if (!confirm('Replace all questions and the PDF with this JSON? The current sheet content will be deleted first.')) {
        return;
    }

    reimportJsonForm.manual_questions = rows;
    reimportJsonForm.post(route('admin.written-sheets.reimport-json', props.sheet.id), {
        preserveScroll: true,
        onSuccess: () => {
            reimportJsonText.value = '';
            reimportJsonForm.reset();
            if (reimportJsonFileInput.value) {
                reimportJsonFileInput.value.value = '';
            }
        },
    });
};

const submitReimportZip = () => {
    if (!reimportZipForm.pack) {
        return;
    }

    if (!confirm('Replace all questions and the PDF with this zip? The current sheet content will be deleted first.')) {
        return;
    }

    reimportZipForm.post(route('admin.written-sheets.reimport-zip-pack', props.sheet.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            reimportZipForm.reset();
            if (reimportZipInput.value) {
                reimportZipInput.value.value = '';
            }
        },
    });
};

const formatDate = (d) => {
    if (!d) {
        return '—';
    }

    return new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
};

const openUpload = (row) => {
    openGrade(row);
};

const progressLabel = (p) => {
    if (!p) {
        return { label: 'Not assigned', class: 'bg-gray-100 text-gray-600' };
    }

    if (p.written_submission_status === 'graded' && p.latest_score != null) {
        return {
            label: p.latest_score_label || formatScoreLabel(p.latest_score, p.latest_max_score),
            class: 'bg-green-100 text-green-800',
        };
    }

    if (p.written_submission_status === 'processing') {
        const minutes = p.checking_minutes ?? 0;

        return {
            label: minutes >= 5 ? `Checking… (${minutes}m)` : 'Checking…',
            class: 'bg-yellow-100 text-yellow-800',
        };
    }

    if (p.written_submission_status === 'uploaded') {
        const minutes = p.checking_minutes ?? 0;

        return {
            label: minutes >= 3 ? 'Uploaded — waiting' : 'Uploaded — queued',
            class: 'bg-yellow-100 text-yellow-800',
        };
    }

    if (p.written_submission_status === 'failed') {
        return { label: 'Needs teacher mark', class: 'bg-rose-100 text-rose-800' };
    }

    if (p.is_overdue) {
        return { label: 'Overdue', class: 'bg-red-100 text-red-800' };
    }

    return { label: 'Assigned', class: 'bg-blue-100 text-blue-800' };
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
                <div v-if="page.props.flash?.warning" class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {{ page.props.flash.warning }}
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
                        <SecondaryButton
                            v-if="!sheet.uses_uploaded_pdf && sheet.questions_count"
                            type="button"
                            :disabled="regenerateForm.processing"
                            @click="regenerate"
                        >
                            Regenerate PDF
                        </SecondaryButton>
                        <p v-if="sheet.uses_uploaded_pdf" class="w-full text-sm text-gray-600">
                            This sheet uses an uploaded PDF. Use the replace panel below to swap the file if needed.
                        </p>
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
                        Step 2: check the PDF below. Step 3: verify, then assign to students below (same as online practice sets).
                    </p>

                    <div v-if="sheet.has_student_submissions" class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        At least one student has uploaded written work — the worksheet PDF and questions can no longer be changed.
                    </div>

                    <div v-else-if="sheet.can_reimport_json" class="mt-4 space-y-4">
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-4">
                            <h4 class="text-sm font-semibold text-emerald-950">Re-import JSON</h4>
                            <p class="mt-1 text-sm text-emerald-900">
                                Gaps or mistakes in the generated PDF? Paste corrected JSON from Cursor (same format as create) to replace all questions and regenerate the PDF.
                            </p>

                            <div class="mt-3">
                                <InputLabel value="Paste JSON" />
                                <textarea
                                    v-model="reimportJsonText"
                                    rows="5"
                                    class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs"
                                    placeholder='{"questions":[{"question":"...","correct_answer":"...","answer_format":"integer"}]}'
                                />
                            </div>

                            <div class="mt-3">
                                <InputLabel value="Or upload a .json file" />
                                <input
                                    ref="reimportJsonFileInput"
                                    type="file"
                                    accept="application/json,.json"
                                    class="mt-1 block w-full max-w-lg text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-emerald-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-emerald-800"
                                    @change="onReimportJsonFileSelected"
                                >
                            </div>

                            <p v-if="reimportJsonError" class="mt-2 text-sm text-rose-700">{{ reimportJsonError }}</p>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <PrimaryButton
                                    type="button"
                                    :disabled="!reimportJsonText.trim() || reimportJsonForm.processing"
                                    @click="submitReimportJson"
                                >
                                    {{ reimportJsonForm.processing ? 'Re-importing…' : 'Re-import JSON & regenerate PDF' }}
                                </PrimaryButton>
                            </div>
                        </div>

                        <div class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-4">
                            <h4 class="text-sm font-semibold text-emerald-950">Re-import zip pack</h4>
                            <p class="mt-1 text-sm text-emerald-900">
                                For geometry sums with figures, upload a corrected .zip (<strong>questions.json</strong> + diagram images) instead.
                            </p>

                            <input
                                ref="reimportZipInput"
                                type="file"
                                accept="application/zip,.zip"
                                class="mt-3 block w-full max-w-lg text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-emerald-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-emerald-800"
                                @change="onReimportZipSelected"
                            >

                            <div class="mt-3 flex flex-wrap gap-2">
                                <PrimaryButton
                                    type="button"
                                    :disabled="!reimportZipForm.pack || reimportZipForm.processing"
                                    @click="submitReimportZip"
                                >
                                    {{ reimportZipForm.processing ? 'Re-importing…' : 'Re-import zip & regenerate PDF' }}
                                </PrimaryButton>
                                <DangerButton
                                    v-if="sheet.can_reset_sheet"
                                    type="button"
                                    :disabled="removePdfForm.processing"
                                    @click="clearSheet"
                                >
                                    Clear sheet & start over
                                </DangerButton>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="sheet.uses_uploaded_pdf && sheet.can_manage_pdf" class="mt-4 rounded-lg border border-sky-200 bg-sky-50/50 p-4">
                        <h4 class="text-sm font-semibold text-sky-950">
                            {{ sheet.written_pdf_url ? 'Replace worksheet PDF' : 'Upload worksheet PDF' }}
                        </h4>
                        <p class="mt-1 text-sm text-sky-900">
                            PDF not right? Upload a replacement — works even after assigning, as long as no student has uploaded their answers yet.
                        </p>

                        <input
                            ref="replacePdfInput"
                            type="file"
                            accept="application/pdf,.pdf"
                            class="mt-3 block w-full max-w-lg text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-sky-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-sky-800"
                            @change="onReplacePdfSelected"
                        >

                        <p v-if="selectedReplacePdfName" class="mt-2 text-sm font-medium text-gray-700">
                            Selected: {{ selectedReplacePdfName }}
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <SecondaryButton
                                type="button"
                                :disabled="replacePdfStaging || !selectedReplacePdfName"
                                @click="stageReplacementPdf"
                            >
                                {{ replacePdfStaging ? 'Uploading…' : 'Preview replacement PDF' }}
                            </SecondaryButton>
                            <PrimaryButton
                                type="button"
                                :disabled="!replacePdfToken || replacePdfForm.processing"
                                @click="submitReplacePdf"
                            >
                                {{ replacePdfForm.processing ? 'Saving…' : (sheet.written_pdf_url ? 'Save replacement PDF' : 'Save PDF') }}
                            </PrimaryButton>
                            <DangerButton
                                v-if="sheet.can_reset_sheet"
                                type="button"
                                :disabled="removePdfForm.processing"
                                @click="clearSheet"
                            >
                                Clear sheet & start over
                            </DangerButton>
                        </div>

                        <p v-if="replacePdfError" class="mt-2 text-sm text-rose-700">{{ replacePdfError }}</p>

                        <WorksheetPdfViewer
                            v-if="replacePdfPreviewUrl"
                            class="mt-4"
                            :url="replacePdfPreviewUrl"
                            title="Replacement PDF preview"
                            helper-text="Click Save replacement PDF to swap this in for students who have not uploaded yet."
                        />
                    </div>

                    <div v-else-if="sheet.can_reset_sheet" class="mt-4 rounded-lg border border-rose-200 bg-rose-50/50 p-4">
                        <h4 class="text-sm font-semibold text-rose-950">Clear sheet</h4>
                        <p class="mt-1 text-sm text-rose-900">
                            Remove the PDF and all questions so you can re-import or rebuild from scratch.
                        </p>
                        <DangerButton
                            class="mt-3"
                            type="button"
                            :disabled="removePdfForm.processing"
                            @click="clearSheet"
                        >
                            Clear sheet & start over
                        </DangerButton>
                    </div>
                </div>

                <div
                    v-if="sheet.can_assign"
                    id="assign"
                    class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-indigo-200"
                >
                    <h3 class="font-semibold text-gray-900">Assign to students</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Pick a class to load students (all selected by default), deselect anyone who should not get this sheet, then assign. Or assign one student quickly below.
                    </p>

                    <div class="mt-4 grid gap-6 lg:grid-cols-3">
                        <div class="lg:col-span-2 space-y-4">
                            <div class="rounded-md border border-gray-200 p-4">
                                <div class="flex flex-wrap items-end gap-4">
                                    <div>
                                        <InputLabel value="Class" class="!text-xs" />
                                        <select
                                            v-model="selectedGradeLevelId"
                                            class="mt-1 rounded-md border-gray-300 text-sm"
                                        >
                                            <option value="">Select class</option>
                                            <option v-for="g in gradeLevels" :key="g.id" :value="g.id">{{ g.name }}</option>
                                        </select>
                                    </div>
                                    <div v-if="selectedGradeLevelId" class="flex flex-wrap gap-2">
                                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="selectAllFiltered">
                                            Select all ({{ filteredStudents.length }})
                                        </SecondaryButton>
                                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="clearSelectedStudents">
                                            Clear
                                        </SecondaryButton>
                                    </div>
                                </div>

                                <p v-if="selectedGradeLevelId" class="mt-2 text-xs text-gray-500">
                                    {{ filteredStudents.length }} student(s) in this class · {{ selectedStudentIds.length }} selected
                                </p>
                                <p v-else class="mt-2 text-xs text-gray-500">
                                    Choose a class to see students with checkboxes.
                                </p>

                                <div
                                    v-if="selectedGradeLevelId && !filteredStudents.length"
                                    class="mt-3 rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500"
                                >
                                    No active students in this class.
                                </div>

                                <div
                                    v-else-if="selectedGradeLevelId"
                                    class="mt-3 max-h-72 overflow-y-auto rounded-lg border border-gray-200 divide-y divide-gray-100"
                                >
                                    <label
                                        v-for="student in filteredStudents"
                                        :key="student.id"
                                        class="flex cursor-pointer items-start gap-3 px-4 py-3 hover:bg-gray-50"
                                    >
                                        <input
                                            type="checkbox"
                                            class="mt-0.5 rounded border-gray-300 text-indigo-600"
                                            :checked="selectedStudentIds.includes(student.id)"
                                            @change="toggleStudent(student.id)"
                                        >
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-medium text-gray-900">{{ student.name }}</span>
                                            <span class="mt-0.5 block text-xs text-gray-500">
                                                {{ student.class_name }}
                                                <span v-if="student.board_code"> · {{ student.board_code }}</span>
                                            </span>
                                            <span
                                                v-if="existingByStudentId[student.id]"
                                                class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                                :class="progressLabel(existingByStudentId[student.id]).class"
                                            >
                                                Already: {{ progressLabel(existingByStudentId[student.id]).label }}
                                            </span>
                                        </span>
                                    </label>
                                </div>

                                <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4">
                                    <div>
                                        <InputLabel value="Target date" class="!text-xs" />
                                        <input v-model="bulkTargetDate" type="date" class="mt-1 rounded-md border-gray-300 text-sm" />
                                    </div>
                                    <div class="min-w-[12rem] flex-1">
                                        <InputLabel value="Note (optional)" class="!text-xs" />
                                        <input
                                            v-model="assignNotes"
                                            type="text"
                                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                            placeholder="e.g. Complete before test"
                                        >
                                    </div>
                                    <PrimaryButton
                                        type="button"
                                        class="!py-2"
                                        :disabled="!selectedGradeLevelId || !selectedStudentIds.length || !bulkTargetDate || bulkForm.processing"
                                        @click="assignSelected"
                                    >
                                        Assign {{ selectedStudentIds.length || '' }} student{{ selectedStudentIds.length === 1 ? '' : 's' }}
                                    </PrimaryButton>
                                </div>
                            </div>

                            <div class="rounded-md border border-dashed border-gray-200 p-4">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Quick assign one student</p>
                                <div class="mt-3 flex flex-wrap items-end gap-3">
                                    <div>
                                        <InputLabel value="Student" class="!text-xs" />
                                        <select v-model="assignStudentId" class="mt-1 rounded-md border-gray-300 text-sm">
                                            <option value="">Select</option>
                                            <option v-for="s in students" :key="s.id" :value="s.id">{{ s.label || s.name }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel value="Target date" class="!text-xs" />
                                        <input v-model="targetDate" type="date" class="mt-1 rounded-md border-gray-300 text-sm" />
                                    </div>
                                    <PrimaryButton
                                        type="button"
                                        class="!py-2"
                                        :disabled="!assignStudentId || !targetDate || assignForm.processing"
                                        @click="assignSheet"
                                    >
                                        Assign
                                    </PrimaryButton>
                                </div>
                            </div>

                            <div v-if="selectedStudent && studentProgress" class="rounded-md bg-gray-50 p-4 text-sm">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="font-medium text-gray-900">Selected student progress</p>
                                    <span
                                        class="rounded-full px-3 py-1 text-xs font-medium"
                                        :class="progressLabel(studentProgress).class"
                                    >
                                        {{ progressLabel(studentProgress).label }}
                                    </span>
                                </div>
                                <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-xs text-gray-500">Target date</dt>
                                        <dd class="font-medium">{{ formatDate(studentProgress.target_date) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-gray-500">Upload / graded</dt>
                                        <dd class="font-medium">{{ studentProgress.submitted_at ? formatDate(studentProgress.submitted_at.slice(0, 10)) : '—' }}</dd>
                                    </div>
                                    <div v-if="studentProgress.handwriting_label">
                                        <dt class="text-xs text-gray-500">Handwriting</dt>
                                        <dd class="font-medium">{{ studentProgress.handwriting_label }}</dd>
                                    </div>
                                    <div v-if="studentProgress.teacher_remarks || studentProgress.written_feedback">
                                        <dt class="text-xs text-gray-500">Remarks</dt>
                                        <dd class="font-medium">{{ studentProgress.teacher_remarks || studentProgress.written_feedback }}</dd>
                                    </div>
                                </dl>
                                <div class="mt-3 flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        class="text-indigo-600 hover:underline"
                                        @click="openUpload(studentProgress)"
                                    >
                                        Upload work
                                    </button>
                                    <button
                                        type="button"
                                        class="text-indigo-600 hover:underline"
                                        @click="openGrade(studentProgress)"
                                    >
                                        {{ studentProgress.written_submission_status === 'graded' ? 'Edit marks' : 'Enter marks' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="text-gray-600 hover:underline"
                                        @click="reassign(studentProgress.assignment_id)"
                                    >
                                        Re-assign
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div class="rounded-md border border-gray-200 p-4">
                                <h4 class="font-medium text-gray-800">Filter by student</h4>
                                <select v-model="selectedStudent" class="mt-2 w-full rounded-md border-gray-300 text-sm" @change="onStudentChange">
                                    <option value="">—</option>
                                    <option v-for="s in students" :key="s.id" :value="s.id">{{ s.label || s.name }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div v-if="assignments.length" class="mt-6">
                        <h4 class="text-sm font-semibold text-gray-800">Current assignments ({{ assignments.length }})</h4>
                        <p class="mt-1 text-xs text-gray-500">
                            Uploads are checked automatically. For large photos, open <strong>Upload work</strong> to upload on behalf of a student or mark manually without waiting for AI.
                        </p>
                        <div class="mt-2 overflow-hidden rounded-md border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium text-gray-600">Student</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-600">Target</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-600">Status</th>
                                        <th class="px-3 py-2 text-right font-medium text-gray-600">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <template v-for="row in assignments" :key="row.assignment_id">
                                        <tr>
                                            <td class="px-3 py-2">{{ row.student_name }}</td>
                                            <td class="px-3 py-2">{{ formatDate(row.target_date) }}</td>
                                            <td class="px-3 py-2">
                                                <div class="space-y-1">
                                                    <span
                                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                                        :class="progressLabel(row).class"
                                                    >
                                                        {{ progressLabel(row).label }}
                                                    </span>
                                                    <p v-if="row.handwriting_label" class="text-xs text-gray-500">
                                                        Handwriting: {{ row.handwriting_label }}
                                                    </p>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-right space-x-3">
                                                <button type="button" class="text-indigo-600 hover:underline" @click="openUpload(row)">
                                                    Upload work
                                                </button>
                                                <button type="button" class="text-indigo-600 hover:underline" @click="openGrade(row)">
                                                    {{ row.written_submission_status === 'graded' ? 'Edit marks' : 'Enter marks' }}
                                                </button>
                                                <button type="button" class="text-gray-600 hover:underline" @click="reassign(row.assignment_id)">
                                                    Re-assign
                                                </button>
                                            </td>
                                        </tr>
                                        <tr v-if="gradingAssignmentId === row.assignment_id">
                                            <td colspan="4" class="bg-indigo-50/50 px-3 py-4">
                                                <div class="space-y-3">
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <p class="text-sm font-medium text-gray-900">
                                                            Marks for {{ row.student_name }}
                                                        </p>
                                                        <p class="text-sm font-semibold text-indigo-800">
                                                            Score: {{ gradeCorrectCount }}/{{ gradeForm.items.length || gradeSheetQuestions.length }}
                                                            <span v-if="!allQuestionsMarked" class="ml-1 text-xs font-normal text-amber-700">
                                                                ({{ gradeMarkedCount }}/{{ gradeForm.items.length }} marked)
                                                            </span>
                                                        </p>
                                                    </div>
                                                    <div v-if="(row.upload_files || []).length" class="space-y-3">
                                                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-800">
                                                            Uploaded answer sheet (view online)
                                                        </p>
                                                        <div
                                                            v-for="(file, index) in row.upload_files"
                                                            :key="file.url"
                                                            class="overflow-hidden rounded-md border border-indigo-100 bg-white"
                                                        >
                                                            <div class="flex items-center justify-between border-b border-gray-100 px-3 py-1.5">
                                                                <p class="text-xs text-gray-600">{{ file.label || `Page ${index + 1}` }}</p>
                                                                <a :href="file.url" target="_blank" class="text-xs text-indigo-600 hover:underline">Open full size</a>
                                                            </div>
                                                            <iframe
                                                                v-if="file.kind === 'pdf'"
                                                                :src="file.url"
                                                                class="h-[360px] w-full"
                                                                :title="file.label || `Upload ${index + 1}`"
                                                            />
                                                            <a v-else :href="file.url" target="_blank" class="block bg-gray-50">
                                                                <img
                                                                    :src="file.url"
                                                                    :alt="file.label || `Upload ${index + 1}`"
                                                                    class="mx-auto max-h-[420px] w-auto max-w-full object-contain"
                                                                >
                                                            </a>
                                                        </div>
                                                    </div>
                                                    <p v-else class="text-xs text-amber-800">
                                                        No photo/PDF uploaded yet — you can still tick questions after checking the paper offline.
                                                    </p>

                                                    <div class="rounded-md border border-dashed border-indigo-200 bg-white p-3">
                                                        <p class="text-xs font-semibold text-gray-800">Upload answer sheet</p>
                                                        <p class="mt-1 text-xs text-gray-600">
                                                            Upload the student&apos;s completed work (photo or PDF).
                                                            Up to {{ uploadLimits.max_files }} files, {{ uploadLimits.max_file_mb }} MB each.
                                                        </p>
                                                        <input
                                                            ref="revisionFileInput"
                                                            type="file"
                                                            accept="image/jpeg,image/png,image/webp,application/pdf"
                                                            multiple
                                                            class="mt-2 block w-full text-xs"
                                                            @change="onRevisionFilesChange"
                                                        >
                                                        <p v-if="revisionSelectedFiles.length" class="mt-2 text-xs text-gray-700">
                                                            Selected: {{ revisionSelectedFiles.length }} file{{ revisionSelectedFiles.length === 1 ? '' : 's' }}
                                                        </p>
                                                        <p v-if="revisionUploadError" class="mt-2 text-xs text-rose-700">{{ revisionUploadError }}</p>
                                                        <p v-else-if="revisionForm.errors.files" class="mt-2 text-xs text-rose-700">{{ revisionForm.errors.files }}</p>
                                                        <label class="mt-3 flex items-start gap-2 text-xs text-gray-700">
                                                            <input
                                                                v-model="revisionForm.skip_ai"
                                                                type="checkbox"
                                                                class="mt-0.5 rounded border-gray-300 text-indigo-600"
                                                            >
                                                            <span>Skip AI — I will mark this manually (recommended for large or unclear scans)</span>
                                                        </label>
                                                        <SecondaryButton
                                                            type="button"
                                                            class="mt-2 !py-1 !text-xs"
                                                            :disabled="revisionUploading || !revisionSelectedFiles.length"
                                                            @click="submitRevisionUpload(row.assignment_id)"
                                                        >
                                                            {{ revisionUploading ? 'Uploading…' : 'Save upload' }}
                                                        </SecondaryButton>
                                                        <p v-if="page.props.flash?.success && gradingAssignmentId === row.assignment_id" class="mt-2 text-xs text-green-800">
                                                            {{ page.props.flash.success }}
                                                        </p>
                                                        <p v-if="page.props.flash?.error && gradingAssignmentId === row.assignment_id" class="mt-2 text-xs text-rose-700">
                                                            {{ page.props.flash.error }}
                                                        </p>
                                                    </div>

                                                    <p class="text-xs text-indigo-900">
                                                        Override AI if handwriting was misread — tick ✓ Correct or ✗ Wrong, rate handwriting, add remarks, then save marks.
                                                    </p>

                                                    <div class="flex flex-wrap gap-2">
                                                        <SecondaryButton type="button" class="!py-1 !text-xs" @click="markAllCorrect">
                                                            All correct
                                                        </SecondaryButton>
                                                        <SecondaryButton type="button" class="!py-1 !text-xs" @click="markAllWrong">
                                                            All wrong
                                                        </SecondaryButton>
                                                    </div>

                                                    <div class="overflow-hidden rounded-md border border-indigo-100 bg-white">
                                                        <div
                                                            v-for="(question, index) in gradeSheetQuestions"
                                                            :key="question.id"
                                                            class="border-b border-gray-100 px-3 py-2 last:border-b-0"
                                                        >
                                                            <div class="flex flex-wrap items-start justify-between gap-3">
                                                                <div class="min-w-0 flex-1 text-sm">
                                                                    <p class="font-semibold text-gray-900">Q{{ question.number || index + 1 }}</p>
                                                                    <p class="mt-0.5 text-gray-700" v-html="question.question_text" />
                                                                    <dl class="mt-2 grid gap-1 text-xs sm:grid-cols-2">
                                                                        <div>
                                                                            <dt class="text-gray-500">AI read</dt>
                                                                            <dd class="font-medium text-gray-800">
                                                                                {{ row.question_results?.find((r) => r.question_id === question.id)?.extracted_answer || '—' }}
                                                                            </dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt class="text-gray-500">Correct answer</dt>
                                                                            <dd class="font-medium text-emerald-800">
                                                                                {{ question.correct_answer || '—' }}
                                                                            </dd>
                                                                        </div>
                                                                    </dl>
                                                                </div>
                                                                <div class="flex shrink-0 gap-2">
                                                                    <button
                                                                        type="button"
                                                                        class="rounded-md px-3 py-1.5 text-xs font-bold"
                                                                        :class="gradeForm.items[index]?.is_correct === true
                                                                            ? 'bg-emerald-600 text-white'
                                                                            : 'border border-emerald-300 bg-emerald-50 text-emerald-800'"
                                                                        @click="setQuestionResult(question.id, true)"
                                                                    >
                                                                        ✓ Correct
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        class="rounded-md px-3 py-1.5 text-xs font-bold"
                                                                        :class="gradeForm.items[index]?.is_correct === false
                                                                            ? 'bg-rose-600 text-white'
                                                                            : 'border border-rose-300 bg-rose-50 text-rose-800'"
                                                                        @click="setQuestionResult(question.id, false)"
                                                                    >
                                                                        ✗ Wrong
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <p v-if="gradeForm.errors.items" class="text-xs text-rose-600">{{ gradeForm.errors.items }}</p>

                                                    <div>
                                                        <InputLabel value="Handwriting (required)" class="!text-xs" />
                                                        <div class="mt-2 flex flex-wrap gap-2">
                                                            <button
                                                                v-for="option in handwritingOptions"
                                                                :key="option.value"
                                                                type="button"
                                                                class="rounded-md px-3 py-1.5 text-xs font-semibold"
                                                                :class="gradeForm.handwriting_rating === option.value
                                                                    ? 'bg-indigo-600 text-white'
                                                                    : 'border border-indigo-200 bg-white text-indigo-900'"
                                                                @click="gradeForm.handwriting_rating = option.value"
                                                            >
                                                                {{ option.label }}
                                                            </button>
                                                        </div>
                                                        <p v-if="gradeForm.errors.handwriting_rating" class="mt-1 text-xs text-rose-600">
                                                            {{ gradeForm.errors.handwriting_rating }}
                                                        </p>
                                                    </div>

                                                    <div>
                                                        <InputLabel value="Remarks for this sheet (optional)" class="!text-xs" />
                                                        <textarea
                                                            v-model="gradeForm.remarks"
                                                            rows="2"
                                                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                                            placeholder="e.g. Neat layout; revise Q3 working. Shown to student and in weekly report."
                                                        />
                                                        <p v-if="gradeForm.errors.remarks" class="mt-1 text-xs text-rose-600">{{ gradeForm.errors.remarks }}</p>
                                                    </div>
                                                    <div class="flex flex-wrap gap-2">
                                                        <PrimaryButton
                                                            type="button"
                                                            class="!py-1.5 !text-xs"
                                                            :disabled="gradeForm.processing || !allQuestionsMarked"
                                                            @click="submitGrade"
                                                        >
                                                            {{ gradeForm.processing ? 'Saving…' : 'Save marks' }}
                                                        </PrimaryButton>
                                                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="cancelGrade">
                                                            Cancel
                                                        </SecondaryButton>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div v-else-if="sheet.written_status === 'pending_review'" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    Verify this sheet first — then you can assign it to students here.
                </div>

                <div v-if="sheet.written_pdf_url" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <iframe :src="sheet.written_pdf_url" class="h-[720px] w-full" title="Written sheet preview" />
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-medium text-gray-900">Questions on this sheet</h3>
                        <SecondaryButton
                            v-if="sheet.can_update_answers && !showAnswerEditor"
                            type="button"
                            class="!py-1.5 !text-xs"
                            @click="openAnswerEditor"
                        >
                            Re-upload / edit answers
                        </SecondaryButton>
                    </div>

                    <div v-if="showAnswerEditor" class="mt-4 rounded-lg border border-amber-200 bg-amber-50/40 p-4">
                        <h4 class="text-sm font-semibold text-amber-950">Update answer key</h4>
                        <p class="mt-1 text-sm text-amber-900">
                            Answers wrong? Edit them below, or upload a corrected answer-sheet PDF to fill the rows, then save.
                        </p>

                        <input
                            ref="answerPdfInput"
                            type="file"
                            accept="application/pdf,.pdf"
                            class="mt-3 block w-full max-w-lg text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-amber-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-amber-900"
                        >

                        <div class="mt-3 flex flex-wrap gap-2">
                            <SecondaryButton
                                type="button"
                                :disabled="answerPdfParsing"
                                @click="parseAnswerPdfForSheet"
                            >
                                {{ answerPdfParsing ? 'Reading PDF…' : 'Fill from answer PDF' }}
                            </SecondaryButton>
                        </div>

                        <p v-if="answerPdfError" class="mt-2 text-sm text-rose-700">{{ answerPdfError }}</p>
                        <ul v-if="answerPdfWarnings.length" class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">
                            <li v-for="(warning, index) in answerPdfWarnings" :key="index">{{ warning }}</li>
                        </ul>

                        <div class="mt-4 space-y-3">
                            <div
                                v-for="(question, index) in sheet.questions"
                                :key="question.id"
                                class="rounded-md border border-amber-100 bg-white p-3"
                            >
                                <p class="text-sm font-semibold text-gray-900">Q{{ question.number }}</p>
                                <p class="mt-1 text-sm text-gray-700" v-html="question.question_text" />
                                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                                    <div class="sm:col-span-2">
                                        <InputLabel value="Correct answer" class="!text-xs" />
                                        <input
                                            v-model="answersForm.answers[index].correct_answer"
                                            type="text"
                                            maxlength="64"
                                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                        >
                                        <p v-if="answersForm.errors[`answers.${index}.correct_answer`]" class="mt-1 text-xs text-rose-600">
                                            {{ answersForm.errors[`answers.${index}.correct_answer`] }}
                                        </p>
                                    </div>
                                    <div>
                                        <InputLabel value="Format" class="!text-xs" />
                                        <select v-model="answersForm.answers[index].answer_format" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                            <option value="text">Text</option>
                                            <option value="integer">Integer</option>
                                            <option value="decimal">Decimal</option>
                                            <option value="fraction">Fraction</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p v-if="answersForm.errors.answers" class="mt-2 text-sm text-rose-700">{{ answersForm.errors.answers }}</p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <PrimaryButton type="button" :disabled="answersForm.processing" @click="submitAnswers">
                                {{ answersForm.processing ? 'Saving…' : 'Save answers' }}
                            </PrimaryButton>
                            <SecondaryButton type="button" @click="cancelAnswerEditor">Cancel</SecondaryButton>
                        </div>
                    </div>

                    <ol v-else class="mt-3 space-y-3">
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
