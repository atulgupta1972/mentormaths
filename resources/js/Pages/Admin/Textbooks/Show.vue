<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextbookChapterGeminiPanel from '@/Components/TextbookChapterGeminiPanel.vue';
import { safeRoute } from '@/utils/routes';
import { formatScoreLabel } from '@/utils/scores';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps({
    chapter: { type: Object, required: true },
    mcqImport: { type: Object, required: true },
    fillBlankConversion: { type: Object, default: null },
    publishedSets: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
    gradeLevels: { type: Array, default: () => [] },
    defaultGradeLevelId: { type: [Number, null], default: null },
    activeYear: { type: Object, default: null },
    routeNamespace: { type: String, default: 'admin' },
    uploaderMode: { type: Boolean, default: false },
    contentUploadTask: { type: Object, default: null },
    stagingGemini: { type: Object, default: () => ({}) },
    textbooks: { type: Array, default: () => [] },
    syllabusChaptersForRelink: { type: Array, default: () => [] },
});

const chapterRoute = (action, fallback = '#') =>
    safeRoute(`${props.routeNamespace}.textbooks.${action}`, props.chapter.id, fallback);

const cloneItems = (items) => JSON.parse(JSON.stringify(items ?? []));
const clonePlan = (plan) => JSON.parse(JSON.stringify(plan ?? []));

const page = usePage();
const items = ref(cloneItems(props.chapter.items));
const setPlan = ref(clonePlan(props.chapter.mcq_set_plan));
const copied = ref(false);
const conversionCopied = ref(false);
const jsonInput = ref('');
const fillBlankJsonInput = ref('');

const importForm = useForm({ json: '' });
const fillBlankImportForm = useForm({ json: '' });
const publishFillBlankForm = useForm({});
const zipImportForm = useForm({ pack: null });
const zipPackInput = ref(null);

const draftForm = useForm({ items: items.value, mcq_set_plan: setPlan.value });
const publishForm = useForm({ items: items.value, mcq_set_plan: setPlan.value });
const reclassifyForm = useForm({ item_index: null, target: 'fill_blank' });
const startReviewForm = useForm({});
const pdfForm = useForm({ pdf: null });
const changeBookForm = useForm({
    mode: 'existing',
    textbook_id: '',
    book_name: '',
    book_code: '',
});
const changeSyllabusForm = useForm({
    syllabus_chapter_id: props.chapter.syllabus_chapter_id || '',
});

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
const showUploaderReviewCta = computed(() =>
    props.uploaderMode
    && props.chapter.status === 'published'
    && props.contentUploadTask?.can_start_review,
);
const showUploaderEditor = ref(false);
const hideUploaderEditPanels = computed(() => showUploaderReviewCta.value && !showUploaderEditor.value);
const awaitingImport = computed(() => props.chapter.status === 'draft' && !hasItems.value);
const canEdit = computed(() => ['review', 'published', 'failed'].includes(props.chapter.status) || hasItems.value);
const showImportSteps = computed(() => awaitingImport.value || (canEdit.value && !hasItems.value));
const approvedCount = computed(() => items.value.filter((item) => item.approved !== false).length);
const fillBlankItemCount = computed(() => items.value.filter((item) => item.question_type === 'fill_blank').length);
const mcqItemCount = computed(() => items.value.filter((item) => item.question_type === 'mcq' || !item.question_type).length);
const showLegacyFillBlankStep = computed(() => fillBlankItemCount.value === 0 && hasItems.value);
const optionLetter = (index) => String.fromCharCode(65 + index);
const isFillBlankItem = (item) => item.question_type === 'fill_blank';
const diagramLinkedCount = computed(() => items.value.filter((item) => item.diagram_staging_path || item.diagram_preview_url).length);
const replacingDiagramIndex = ref(null);

const replaceDiagram = (index, event) => {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }

    replacingDiagramIndex.value = index;
    const formData = new FormData();
    formData.append('item_index', String(index));
    formData.append('diagram', file);

    router.post(chapterRoute('replace-diagram'), formData, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => applyFromProps(),
        onFinish: () => {
            replacingDiagramIndex.value = null;
            event.target.value = '';
        },
    });
};

const removeDiagram = (index) => {
    if (!confirm('Remove the chart image for this question?')) {
        return;
    }

    router.post(chapterRoute('remove-diagram'), {
        item_index: index,
    }, {
        preserveScroll: true,
        onSuccess: () => applyFromProps(),
    });
};

const reclassifyItem = (index, target) => {
    reclassifyForm.item_index = index;
    reclassifyForm.target = target;
    reclassifyForm.post(chapterRoute('reclassify-item'), {
        preserveScroll: true,
        onSuccess: () => applyFromProps(),
    });
};
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
            // Split near the middle so AP/GP-style chapters can edit q_from/q_to easily.
            const firstEnd = Math.max(1, Math.min(Math.floor(total / 2), total - 1));

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

/** Avoid PHP max_input_vars truncation (common around 1000 fields ≈ ~40–60 MCQs). */
const useLeanMcqPayload = () => items.value.length > 25;

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

const copyConversionPrompt = async () => {
    await navigator.clipboard.writeText(props.fillBlankConversion?.prompt || '');
    conversionCopied.value = true;
    window.setTimeout(() => {
        conversionCopied.value = false;
    }, 2000);
};

const importFillBlank = () => {
    fillBlankImportForm.json = fillBlankJsonInput.value;
    fillBlankImportForm.post(chapterRoute('import-fill-blank'), {
        preserveScroll: true,
        onSuccess: () => {
            fillBlankJsonInput.value = '';
            applyFromProps();
        },
    });
};

const publishFillBlankAndWritten = () => {
    publishFillBlankForm.post(chapterRoute('publish-fill-blank-written'), {
        preserveScroll: true,
        onSuccess: () => applyFromProps(),
    });
};

const fillBlankReadyCount = computed(() => props.chapter.fill_blank_ready_count ?? 0);
const canPublishFillBlank = computed(() => fillBlankReadyCount.value > 0);

const importMcq = () => {
    importForm.json = jsonInput.value;
    importForm.post(chapterRoute('import-mcq'), {
        preserveScroll: true,
        onSuccess: () => {
            jsonInput.value = '';
            applyFromProps();
        },
    });
};

const onZipPackSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }

    zipImportForm.pack = file;
    zipImportForm.post(chapterRoute('import-mcq-zip'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            applyFromProps();
        },
        onFinish: () => {
            zipImportForm.reset('pack');
            if (zipPackInput.value) {
                zipPackInput.value.value = '';
            }
        },
    });
};

const resetImport = () => {
    if (!confirm('Clear imported MCQs and start over?')) {
        return;
    }

    router.post(chapterRoute('reset-import'));
};

const saveDraft = () => {
    syncForms();
    if (useLeanMcqPayload()) {
        // Avoid PHP max_input_vars truncation on mid/large chapters (e.g. 74 MCQs).
        draftForm
            .transform(() => ({
                items_json: JSON.stringify(items.value),
                mcq_set_plan: setPlan.value,
            }))
            .post(chapterRoute('draft'), {
                preserveScroll: true,
                onFinish: () => {
                    draftForm.transform((data) => data);
                },
            });
        return;
    }

    draftForm.post(chapterRoute('draft'), { preserveScroll: true });
};

const publish = () => {
    syncForms();
    // Mid/large chapters: send set plan only — questions stay in DB from import/draft.
    // Posting every MCQ as form fields hits PHP max_input_vars and drops the split plan.
    if (useLeanMcqPayload()) {
        publishForm
            .transform(() => ({
                mcq_set_plan: setPlan.value,
            }))
            .post(chapterRoute('publish'), {
                onFinish: () => {
                    publishForm.transform((data) => data);
                },
            });
        return;
    }

    publishForm.post(chapterRoute('publish'));
};

const defaultTargetDate = () => {
    const d = new Date();
    d.setDate(d.getDate() + 7);
    return d.toISOString().slice(0, 10);
};

const selectedGradeLevelId = ref('');
const selectedStudentIds = ref([]);
const bulkTargetDate = ref(defaultTargetDate());
const assignNotes = ref('');
const quickAssignStudentId = ref('');
const quickTargetDate = ref(defaultTargetDate());
const assigningSetId = ref(null);

const bulkAssignForm = useForm({ student_ids: [], target_date: '', notes: '' });
const quickAssignForm = useForm({ student_id: '', target_date: '', notes: '' });
const classAssignForm = useForm({ grade_level_id: '', target_date: '', notes: '' });
const assigningClassSetId = ref(null);

const filteredStudents = computed(() => {
    if (!selectedGradeLevelId.value) {
        return [];
    }

    return props.students.filter(
        (student) => String(student.grade_level_id) === String(selectedGradeLevelId.value),
    );
});

const assignmentsForSet = (setId) => props.publishedSets.find((set) => set.id === setId)?.assignments ?? [];

const existingByStudentIdForSet = (setId) => {
    const map = {};

    assignmentsForSet(setId).forEach((row) => {
        map[row.student_id] = row;
    });

    return map;
};

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

onMounted(() => {
    if (props.defaultGradeLevelId) {
        selectedGradeLevelId.value = String(props.defaultGradeLevelId);
    }
});

const formatDate = (value) => {
    if (!value) {
        return '—';
    }

    return new Date(`${value}T00:00:00`).toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const progressLabel = (row) => {
    if (!row) {
        return { label: 'Not assigned', class: 'bg-gray-100 text-gray-600' };
    }

    if (row.assignment_status === 'completed' && row.latest_score != null) {
        const late = row.submission_timing === 'late' ? ' · Delayed' : '';

        return {
            label: `${row.latest_score_label || formatScoreLabel(row.latest_score, row.latest_max_score)}${late}`,
            class: row.submission_timing === 'late' ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-800',
        };
    }

    if (row.is_overdue) {
        return { label: 'Overdue', class: 'bg-red-100 text-red-800' };
    }

    if (row.assignment_status === 'in_progress') {
        return { label: 'In progress', class: 'bg-yellow-100 text-yellow-800' };
    }

    return { label: 'Assigned', class: 'bg-blue-100 text-blue-800' };
};

const assignSelectedStudents = (setId) => {
    assigningSetId.value = setId;
    bulkAssignForm.student_ids = selectedStudentIds.value;
    bulkAssignForm.target_date = bulkTargetDate.value;
    bulkAssignForm.notes = assignNotes.value;
    bulkAssignForm.post(safeRoute('admin.practice-sets.assign-students', setId, '#'), {
        preserveScroll: true,
        onFinish: () => {
            assigningSetId.value = null;
        },
    });
};

const assignWholeClass = (setId) => {
    assigningClassSetId.value = setId;
    classAssignForm.grade_level_id = selectedGradeLevelId.value || String(props.defaultGradeLevelId || '');
    classAssignForm.target_date = bulkTargetDate.value;
    classAssignForm.notes = assignNotes.value;
    classAssignForm.post(safeRoute('admin.practice-sets.assign-bulk', setId, '#'), {
        preserveScroll: true,
        onFinish: () => {
            assigningClassSetId.value = null;
        },
    });
};

const quickAssignSet = (setId) => {
    quickAssignForm.student_id = quickAssignStudentId.value;
    quickAssignForm.target_date = quickTargetDate.value;
    quickAssignForm.notes = assignNotes.value;
    quickAssignForm.post(safeRoute('admin.practice-sets.assign', setId, '#'), { preserveScroll: true });
};

const submitPdf = () => {
    pdfForm.post(chapterRoute('upload-pdf'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => pdfForm.reset('pdf'),
    });
};

const submitChangeBook = () => {
    changeBookForm.transform((data) => ({
        textbook_id: data.mode === 'existing' ? data.textbook_id : null,
        book_name: data.mode === 'new' ? data.book_name : null,
        book_code: data.mode === 'new' ? data.book_code : null,
    })).post(chapterRoute('change-book'), {
        preserveScroll: true,
        onSuccess: () => {
            if (changeBookForm.mode === 'new') {
                changeBookForm.book_name = '';
                changeBookForm.book_code = '';
            }
        },
    });
};

const submitChangeSyllabus = () => {
    changeSyllabusForm.post(chapterRoute('change-syllabus'), {
        preserveScroll: true,
    });
};

const canChangeBook = computed(() =>
    props.uploaderMode
        ? props.contentUploadTask?.can_change_book
        : true,
);
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
                        {{ chapter.label || `Ch ${chapter.chapter_number} — ${chapter.title}` }}
                        · {{ chapter.status_label }}
                        · MCQ {{ mcqPublishSummary }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a
                        v-if="chapter.pdf_url"
                        :href="chapterRoute('download', chapter.pdf_url)"
                        class="text-sm text-indigo-600 hover:underline"
                    >
                        Download PDF
                    </a>
                    <Link
                        v-if="uploaderMode"
                        :href="safeRoute('content.chapters.show', chapter.id, `/content/chapters/${chapter.id}`)"
                        class="text-sm text-indigo-600 hover:underline"
                    >
                        View all questions
                    </Link>
                    <Link
                        v-if="uploaderMode"
                        :href="safeRoute('content.tasks.index', null, '/content/tasks')"
                        class="text-sm text-indigo-600 hover:underline"
                    >
                        ← My content tasks
                    </Link>
                    <Link
                        v-else
                        :href="safeRoute('admin.textbooks.index', null, '/admin/textbooks')"
                        class="text-sm text-gray-600 hover:underline"
                    >
                        All chapters
                    </Link>
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

                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Chapter PDF</p>
                            <p class="mt-1 text-xs text-gray-600">
                                Required before review/submit. Upload the textbook PDF for this chapter.
                            </p>
                            <p class="mt-2">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ring-1"
                                    :class="chapter.has_pdf ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-rose-50 text-rose-800 ring-rose-200'"
                                >
                                    {{ chapter.has_pdf ? 'PDF uploaded' : 'PDF missing' }}
                                </span>
                                <a
                                    v-if="chapter.pdf_url"
                                    :href="chapterRoute('download', chapter.pdf_url)"
                                    class="ml-3 text-sm text-indigo-600 hover:underline"
                                >
                                    Download
                                </a>
                                <Link
                                    v-if="chapter.has_pdf"
                                    :href="chapterRoute('concept-path')"
                                    class="ml-3 text-sm font-semibold text-violet-700 hover:underline"
                                >
                                    Concept path
                                    <span v-if="chapter.concept_path_status_label" class="font-normal text-slate-500">
                                        ({{ chapter.concept_path_status_label }})
                                    </span>
                                </Link>
                            </p>
                        </div>
                        <form class="flex flex-wrap items-end gap-2" @submit.prevent="submitPdf">
                            <input
                                type="file"
                                accept=".pdf,application/pdf"
                                class="max-w-xs text-xs"
                                @change="pdfForm.pdf = $event.target.files?.[0] || null"
                            >
                            <PrimaryButton type="submit" class="!py-1.5 !text-xs" :disabled="pdfForm.processing || !pdfForm.pdf">
                                {{ pdfForm.processing ? 'Uploading…' : chapter.has_pdf ? 'Replace PDF' : 'Upload PDF' }}
                            </PrimaryButton>
                        </form>
                    </div>
                    <InputError class="mt-1" :message="pdfForm.errors.pdf" />
                </div>

                <div v-if="canChangeBook" class="rounded-lg border border-indigo-200 bg-indigo-50/40 p-4 shadow-sm">
                    <p class="text-sm font-semibold text-indigo-950">Book / author</p>
                    <p class="mt-1 text-xs text-indigo-900">
                        Current: <strong>{{ chapter.book?.name }}</strong> ({{ chapter.book?.code }})
                        <span v-if="uploaderMode"> — fix here if you picked the wrong book before submit.</span>
                        <span v-else> — admin can re-link even after publish.</span>
                    </p>
                    <form class="mt-3 space-y-2" @submit.prevent="submitChangeBook">
                        <div class="flex flex-wrap gap-4 text-xs">
                            <label class="inline-flex items-center gap-1.5">
                                <input v-model="changeBookForm.mode" type="radio" value="existing">
                                Pick existing book
                            </label>
                            <label class="inline-flex items-center gap-1.5">
                                <input v-model="changeBookForm.mode" type="radio" value="new">
                                New book name + code
                            </label>
                        </div>
                        <div v-if="changeBookForm.mode === 'existing'" class="flex flex-wrap items-end gap-2">
                            <select v-model="changeBookForm.textbook_id" class="min-w-[220px] rounded-md border-gray-300 text-sm" required>
                                <option value="" disabled>Select book</option>
                                <option v-for="book in textbooks" :key="book.id" :value="book.id">{{ book.label }}</option>
                            </select>
                        </div>
                        <div v-else class="flex flex-wrap items-end gap-2">
                            <input v-model="changeBookForm.book_name" type="text" placeholder="Book name (e.g. RD Sharma)" class="rounded-md border-gray-300 text-sm" required>
                            <input v-model="changeBookForm.book_code" type="text" placeholder="Code (e.g. rdsharma)" class="w-36 rounded-md border-gray-300 text-sm" required>
                        </div>
                        <PrimaryButton type="submit" class="!py-1.5 !text-xs" :disabled="changeBookForm.processing">
                            {{ changeBookForm.processing ? 'Saving…' : 'Update book' }}
                        </PrimaryButton>
                        <InputError :message="changeBookForm.errors.textbook_id || changeBookForm.errors.book_name || changeBookForm.errors.book_code" />
                    </form>
                </div>

                <div v-if="!uploaderMode && syllabusChaptersForRelink.length" class="rounded-lg border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
                    <p class="text-sm font-semibold text-amber-950">Reuse MCQs on another board or class</p>
                    <p class="mt-1 text-xs text-amber-900">
                        Current heading: <strong>{{ chapter.syllabus_chapter_label || '—' }}</strong>.
                        Relink here if this bank belongs on another board or class of the same subject.
                    </p>
                    <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="submitChangeSyllabus">
                        <select v-model="changeSyllabusForm.syllabus_chapter_id" class="min-w-[280px] rounded-md border-gray-300 text-sm" required>
                            <option v-for="option in syllabusChaptersForRelink" :key="option.id" :value="option.id">
                                {{ option.label }}
                            </option>
                        </select>
                        <PrimaryButton type="submit" class="!py-1.5 !text-xs" :disabled="changeSyllabusForm.processing">
                            {{ changeSyllabusForm.processing ? 'Moving…' : 'Move MCQ bank' }}
                        </PrimaryButton>
                        <InputError :message="changeSyllabusForm.errors.syllabus_chapter_id" />
                    </form>
                </div>

                <div
                    v-if="!uploaderMode && contentUploadTask?.can_verify"
                    class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-950"
                >
                    <strong>Uploader task:</strong> {{ contentUploadTask.status_label }}
                    <span v-if="contentUploadTask.assignee_name"> · {{ contentUploadTask.assignee_name }}</span>
                    — fix wrong MCQ options/explanations in verification, or send back to uploader.
                    <Link
                        :href="route('admin.content-tasks.show', contentUploadTask.id)"
                        class="ml-2 font-medium text-indigo-700 hover:underline"
                    >
                        Open MCQ verification →
                    </Link>
                </div>

                <div
                    v-if="showUploaderReviewCta"
                    class="rounded-xl border-2 border-emerald-400 bg-emerald-50 p-6 text-center shadow-sm"
                >
                    <p class="text-lg font-semibold text-emerald-950">MCQ sets saved — {{ mcqPublishSummary }}</p>
                    <p class="mt-2 text-sm text-emerald-900">
                        Next: review each question, fix the correct option and explanation, then submit for admin.
                    </p>
                    <PrimaryButton
                        class="mt-4"
                        type="button"
                        :disabled="startReviewForm.processing"
                        @click="startReviewForm.post(route('content.tasks.start-review', contentUploadTask.id))"
                    >
                        {{ startReviewForm.processing ? 'Opening…' : 'Review & complete →' }}
                    </PrimaryButton>
                    <SecondaryButton
                        v-if="showUploaderReviewCta"
                        class="mt-3"
                        type="button"
                        @click="showUploaderEditor = true"
                    >
                        Edit MCQ sets again
                    </SecondaryButton>
                </div>

                <div v-if="hasItems && canEdit && !hideUploaderEditPanels" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    <strong>Review {{ items.length }} question(s)</strong>
                    —
                    <span v-if="fillBlankItemCount || mcqItemCount">
                        {{ fillBlankItemCount }} fill-in-blank · {{ mcqItemCount }} MCQ (8 options).
                    </span>
                    <span v-if="uploaderMode">
                        Tick <strong>Include</strong>, upload missing figures, run <strong>Gemini check</strong>, then publish sets.
                    </span>
                    <span v-else>
                        Uploader runs Gemini check; you publish when content looks good.
                        Use the set plan matrix below — one row for small chapters, split for large ones.
                    </span>
                    <SecondaryButton type="button" class="ml-3 !py-1 !text-xs" @click="resetImport">
                        Clear &amp; re-import
                    </SecondaryButton>
                </div>

                <div v-if="canEdit && hasItems && !hideUploaderEditPanels" class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-600">
                        {{ approvedCount }} of {{ items.length }} approved · {{ uploaderMode ? 'save as' : 'publish as' }} {{ mcqPublishSummary }}.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <SecondaryButton type="button" :disabled="draftForm.processing" @click="saveDraft">Save draft</SecondaryButton>
                        <PrimaryButton type="button" :disabled="publishForm.processing || approvedCount === 0" @click="publish">
                            {{ chapter.status === 'published'
                                ? (uploaderMode ? 'Re-save MCQ sets' : 'Re-publish MCQ sets')
                                : (uploaderMode ? 'Save MCQ sets (ready to verify)' : 'Publish MCQ sets') }}
                        </PrimaryButton>
                    </div>
                </div>

                <div v-if="hasItems && canEdit && !hideUploaderEditPanels && diagramLinkedCount" class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                    <strong>Charts from zip are often full PDF pages.</strong>
                    Replace any row with a clean cropped graph only (PNG/JPG) — the MCQ question text is already separate.
                    If sets are published, the new chart shows to students right away.
                </div>

                <div v-if="hasItems && canEdit && !hideUploaderEditPanels && diagramLinkedCount" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                    <strong>{{ diagramLinkedCount }} chart/diagram image(s)</strong> linked — students will see these when attempting MCQs.
                </div>

                <div v-if="canEdit && hasItems && !hideUploaderEditPanels" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="font-semibold text-gray-900">MCQ set plan</h3>
                                <p class="text-xs text-gray-500">
                                    Split into 2+ assignable sets with <strong>Add row / split</strong>, edit Q from / Q to, then click
                                    <strong>{{ chapter.status === 'published' ? 'Re-publish MCQ sets' : 'Publish MCQ sets' }}</strong> above to apply.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <SecondaryButton type="button" class="!py-1 !text-xs" @click="resetToSingleSet">
                                    One set (all)
                                </SecondaryButton>
                                <SecondaryButton type="button" class="!py-1 !text-xs" @click="addSetPlanRow">
                                    Add row / split
                                </SecondaryButton>
                                <PrimaryButton
                                    type="button"
                                    class="!py-1 !text-xs"
                                    :disabled="publishForm.processing || approvedCount === 0"
                                    @click="publish"
                                >
                                    {{ chapter.status === 'published' ? 'Apply split (re-publish)' : 'Publish sets' }}
                                </PrimaryButton>
                            </div>
                        </div>
                        <p v-if="setPlan.length > 1" class="mt-2 text-xs text-indigo-700">
                            Plan has {{ setPlan.length }} sets — click <strong>Apply split (re-publish)</strong> to create them.
                            Adjust set codes / Q ranges first if needed (e.g. AP vs GP).
                        </p>
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

                <div v-if="canEdit && hasItems && !hideUploaderEditPanels" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left">#</th>
                                <th class="px-3 py-2 text-left">Use</th>
                                <th class="px-3 py-2 text-left">Type</th>
                                <th class="px-3 py-2 text-left">Label</th>
                                <th class="px-3 py-2 text-left">Chart</th>
                                <th class="px-3 py-2 text-left">Question</th>
                                <th class="px-3 py-2 text-left">Answer / options</th>
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
                                    <p v-if="item.gemini_verified" class="mt-1 text-[10px] font-semibold text-emerald-700">✓ Gemini</p>
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <span
                                        class="inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                        :class="isFillBlankItem(item) ? 'bg-violet-100 text-violet-900' : 'bg-indigo-100 text-indigo-900'"
                                    >
                                        {{ isFillBlankItem(item) ? 'Fill blank' : 'MCQ ×8' }}
                                    </span>
                                    <div class="mt-1 space-y-1">
                                        <button
                                            v-if="!isFillBlankItem(item)"
                                            type="button"
                                            class="block text-[10px] text-violet-700 hover:underline disabled:opacity-50"
                                            :disabled="reclassifyForm.processing"
                                            @click="reclassifyItem(index, 'fill_blank')"
                                        >
                                            → Fill blank
                                        </button>
                                        <button
                                            v-else
                                            type="button"
                                            class="block text-[10px] text-indigo-700 hover:underline disabled:opacity-50"
                                            :disabled="reclassifyForm.processing"
                                            @click="reclassifyItem(index, 'mcq')"
                                        >
                                            → MCQ
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-3 align-top font-medium text-gray-800">{{ item.label }}</td>
                                <td class="px-3 py-3 align-top">
                                    <p
                                        v-if="item.needs_diagram && !item.diagram_preview_url"
                                        class="mb-2 rounded bg-amber-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-amber-900"
                                    >
                                        Requires figure upload
                                    </p>
                                    <img
                                        v-if="item.diagram_preview_url"
                                        :src="item.diagram_preview_url"
                                        alt="Chart preview"
                                        class="max-h-36 max-w-full rounded border border-gray-200 object-contain"
                                    >
                                    <p v-else-if="item.diagram_file" class="text-xs text-amber-700">{{ item.diagram_file }} (missing)</p>
                                    <span v-else-if="!item.needs_diagram" class="text-xs text-gray-400">—</span>
                                    <div class="mt-2 space-y-1">
                                        <label class="block">
                                            <span class="sr-only">Replace chart for question {{ index + 1 }}</span>
                                            <input
                                                type="file"
                                                accept="image/png,image/jpeg,image/webp"
                                                class="block w-full min-w-[9rem] text-[10px] text-gray-600 file:mr-1 file:rounded file:border-0 file:bg-indigo-50 file:px-2 file:py-1 file:text-[10px] file:text-indigo-700"
                                                :disabled="replacingDiagramIndex === index"
                                                @change="replaceDiagram(index, $event)"
                                            >
                                        </label>
                                        <button
                                            v-if="item.diagram_preview_url"
                                            type="button"
                                            class="text-[10px] text-rose-600 hover:underline"
                                            @click="removeDiagram(index)"
                                        >
                                            Remove chart
                                        </button>
                                        <p v-if="replacingDiagramIndex === index" class="text-[10px] text-gray-500">Uploading…</p>
                                    </div>
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <textarea v-model="item.question_text" rows="3" class="w-full min-w-[16rem] rounded-md border-gray-300 text-sm" />
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <template v-if="isFillBlankItem(item)">
                                        <p class="mb-1 text-xs text-violet-900">
                                            Answer: <strong>{{ item.fill_blank_correct_answer || item.correct_answer }}</strong>
                                            <span v-if="item.fill_blank_answer_format" class="text-gray-500">({{ item.fill_blank_answer_format }})</span>
                                        </p>
                                        <textarea
                                            v-model="item.fill_blank_question_text"
                                            rows="2"
                                            class="w-full min-w-[12rem] rounded-md border-violet-200 text-xs"
                                            placeholder="Fill-blank stem with ____"
                                        />
                                    </template>
                                    <template v-else>
                                        <div class="grid grid-cols-2 gap-x-2 gap-y-1 min-w-[14rem]">
                                            <label
                                                v-for="(opt, optIndex) in (item.mcq_options || []).slice(0, 8)"
                                                :key="optIndex"
                                                class="flex items-start gap-1 text-[11px] leading-tight"
                                            >
                                                <input
                                                    v-model="opt.is_correct"
                                                    type="radio"
                                                    :name="`correct-${index}`"
                                                    class="mt-0.5 shrink-0"
                                                    @change="(item.mcq_options || []).forEach((o, i) => { if (i !== optIndex) o.is_correct = false; })"
                                                >
                                                <span class="font-mono text-gray-500">{{ optionLetter(optIndex) }}.</span>
                                                <input
                                                    v-model="opt.text"
                                                    type="text"
                                                    class="min-w-0 flex-1 rounded border-gray-200 px-1 py-0.5 text-[11px]"
                                                >
                                            </label>
                                        </div>
                                    </template>
                                    <textarea v-model="item.explanation" rows="2" class="mt-2 w-full rounded-md border-gray-300 text-xs" placeholder="Explanation" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <InputError :message="draftForm.errors.items" class="px-4 py-2" />
                    <InputError :message="publishForm.errors.items" class="px-4 py-2" />
                </div>

                <TextbookChapterGeminiPanel
                    v-if="canEdit && hasItems && !hideUploaderEditPanels"
                    :staging-gemini="stagingGemini"
                    :paste-route="chapterRoute('staging-gemini-paste')"
                    :reset-route="chapterRoute('reset-staging-gemini')"
                />

                <div
                    v-if="!uploaderMode && chapter.status === 'published' && publishedSets.length"
                    id="assign"
                    class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-indigo-200"
                >
                    <h3 class="font-semibold text-gray-900">Assign published MCQ sets</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Pick {{ chapter.book?.grade_name || 'the class' }} (default), choose students, then assign each part — same as chapter practice sets.
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
                                            <option v-for="grade in gradeLevels" :key="grade.id" :value="String(grade.id)">
                                                {{ grade.name }}
                                            </option>
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
                                    {{ filteredStudents.length }} student(s) · {{ selectedStudentIds.length }} selected
                                </p>

                                <div
                                    v-if="selectedGradeLevelId && !filteredStudents.length"
                                    class="mt-3 rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500"
                                >
                                    No active students in this class for {{ activeYear?.name || 'the current year' }}.
                                </div>

                                <div
                                    v-else-if="selectedGradeLevelId"
                                    class="mt-3 max-h-56 overflow-y-auto rounded-lg border border-gray-200 divide-y divide-gray-100"
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
                                        <span class="min-w-0 flex-1 text-sm">
                                            <span class="block font-medium text-gray-900">{{ student.name }}</span>
                                            <span class="text-xs text-gray-500">{{ student.class_name }}</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4">
                                    <div>
                                        <InputLabel value="Target date" class="!text-xs" />
                                        <input v-model="bulkTargetDate" type="date" class="mt-1 rounded-md border-gray-300 text-sm">
                                    </div>
                                    <div class="min-w-[12rem] flex-1">
                                        <InputLabel value="Notes (optional)" class="!text-xs" />
                                        <input v-model="assignNotes" type="text" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                    </div>
                                </div>
                            </div>

                            <div
                                v-for="set in publishedSets"
                                :key="set.id"
                                class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-4"
                            >
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="font-mono text-lg font-bold text-emerald-900">{{ set.set_code }}</p>
                                        <p class="text-sm text-gray-600">{{ set.questions_count }} MCQ(s)</p>
                                    </div>
                                    <span class="text-xs text-gray-500">{{ set.assignments?.length || 0 }} assigned</span>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <PrimaryButton
                                        type="button"
                                        class="!py-2"
                                        :disabled="!selectedStudentIds.length || !bulkTargetDate || (assigningSetId === set.id && bulkAssignForm.processing)"
                                        @click="assignSelectedStudents(set.id)"
                                    >
                                        {{ assigningSetId === set.id && bulkAssignForm.processing ? 'Assigning…' : 'Assign to selected' }}
                                    </PrimaryButton>
                                    <SecondaryButton
                                        type="button"
                                        class="!py-2"
                                        :disabled="!selectedGradeLevelId || !bulkTargetDate || (assigningClassSetId === set.id && classAssignForm.processing)"
                                        @click="assignWholeClass(set.id)"
                                    >
                                        {{ assigningClassSetId === set.id && classAssignForm.processing ? 'Assigning…' : 'Assign whole class' }}
                                    </SecondaryButton>
                                </div>

                                <div v-if="set.assignments?.length" class="mt-4 overflow-x-auto rounded-md border border-emerald-100 bg-white">
                                    <table class="min-w-full text-xs">
                                        <thead class="bg-gray-50 text-gray-500">
                                            <tr>
                                                <th class="px-3 py-2 text-left">Student</th>
                                                <th class="px-3 py-2 text-left">Due</th>
                                                <th class="px-3 py-2 text-left">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <tr v-for="row in set.assignments" :key="row.assignment_id">
                                                <td class="px-3 py-2">{{ row.student_name }}</td>
                                                <td class="px-3 py-2">{{ formatDate(row.target_date) }}</td>
                                                <td class="px-3 py-2">
                                                    <span
                                                        class="rounded-full px-2 py-0.5 font-medium"
                                                        :class="progressLabel(row).class"
                                                    >
                                                        {{ progressLabel(row).label }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm">
                                <p class="font-medium text-gray-900">Quick assign one student</p>
                                <p class="mt-1 text-xs text-gray-500">Use for a single set without changing checkboxes.</p>
                                <div class="mt-3">
                                    <InputLabel value="Student" class="!text-xs" />
                                    <select v-model="quickAssignStudentId" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                        <option value="">Select</option>
                                        <option v-for="student in students" :key="student.id" :value="student.id">
                                            {{ student.label || student.name }}
                                        </option>
                                    </select>
                                </div>
                                <div class="mt-3">
                                    <InputLabel value="Target date" class="!text-xs" />
                                    <input v-model="quickTargetDate" type="date" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                </div>
                                <div class="mt-4 space-y-2">
                                    <PrimaryButton
                                        v-for="set in publishedSets"
                                        :key="`quick-${set.id}`"
                                        type="button"
                                        class="w-full !py-2 !text-xs"
                                        :disabled="!quickAssignStudentId || !quickTargetDate || quickAssignForm.processing"
                                        @click="quickAssignSet(set.id)"
                                    >
                                        Assign {{ set.set_code }}
                                    </PrimaryButton>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="chapter.status === 'published'"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900"
                >
                    Published MCQ sets:
                    <strong>{{ publishedMcqSetCodes.join(', ') }}</strong>.
                </div>

                <div id="convert" v-if="hasItems && fillBlankConversion && !hideUploaderEditPanels && showLegacyFillBlankStep" class="space-y-4 rounded-lg border-2 border-violet-300 bg-violet-50 p-6 shadow-sm">
                    <div>
                        <h3 class="font-semibold text-violet-950">Step 4 — Fill in blank &amp; written (from MCQs)</h3>
                        <p class="mt-1 text-sm text-violet-900">
                            MCQs are already imported. Use AI to convert each MCQ into a fill-in-blank question
                            (same order, numeric answers from the MCQ correct option).
                            Import the JSON below, then publish matching parts:
                            fill-in-blank
                            <strong>{{ (fillBlankConversion.fill_blank_set_codes || [chapter.fill_blank_set_code || fillBlankConversion.fill_blank_set_code]).join(', ') }}</strong>
                            and written
                            <strong>{{ (fillBlankConversion.written_set_codes || [chapter.written_set_code || fillBlankConversion.written_set_code]).join(', ') }}</strong>
                            (same splits as the MCQ sets). Skip number-names and long English answers — those stay MCQ only.
                        </p>
                        <p v-if="fillBlankReadyCount" class="mt-2 text-sm font-medium text-violet-950">
                            {{ fillBlankReadyCount }} of {{ items.length }} converted and ready to publish.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <p class="text-sm text-violet-900">
                            1. Download <strong>mcq-reference.json</strong> ({{ fillBlankConversion.question_count }} questions)
                            · 2. Copy prompt into Cursor/Claude with that file attached
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <a
                                :href="chapterRoute('mcq-reference', '#')"
                                class="inline-flex items-center rounded-md border border-violet-400 bg-white px-3 py-2 text-sm font-medium text-violet-900 hover:bg-violet-100"
                            >
                                Download MCQ reference JSON
                            </a>
                            <SecondaryButton type="button" @click="copyConversionPrompt">
                                {{ conversionCopied ? 'Copied!' : 'Copy conversion prompt' }}
                            </SecondaryButton>
                        </div>
                    </div>

                    <textarea
                        :value="fillBlankConversion.prompt"
                        rows="10"
                        readonly
                        class="w-full rounded-md border-violet-200 bg-white font-mono text-xs text-gray-800"
                    />

                    <details class="text-sm text-violet-900">
                        <summary class="cursor-pointer font-medium">Sample fill-blank JSON</summary>
                        <pre class="mt-2 overflow-x-auto rounded-md bg-white p-3 text-xs">{{ fillBlankConversion.sample_json }}</pre>
                    </details>

                    <div class="border-t border-violet-200 pt-4">
                        <InputLabel value="3. Paste AI fill-blank JSON" />
                        <textarea
                            v-model="fillBlankJsonInput"
                            rows="8"
                            class="mt-1 w-full rounded-md border-violet-300 font-mono text-xs"
                            placeholder='{"questions": [ { "source_index": 1, "question": "... ____.", ... } ]}'
                        />
                        <InputError :message="fillBlankImportForm.errors.json" class="mt-1" />
                        <div class="mt-3 flex flex-wrap gap-2">
                            <PrimaryButton
                                type="button"
                                :disabled="fillBlankImportForm.processing || !fillBlankJsonInput.trim()"
                                @click="importFillBlank"
                            >
                                {{ fillBlankImportForm.processing ? 'Importing…' : 'Import fill-blank JSON' }}
                            </PrimaryButton>
                            <PrimaryButton
                                type="button"
                                :disabled="publishFillBlankForm.processing || !canPublishFillBlank"
                                @click="publishFillBlankAndWritten"
                            >
                                {{ publishFillBlankForm.processing ? 'Publishing…' : 'Publish fill-blank + written' }}
                            </PrimaryButton>
                        </div>
                    </div>
                </div>

                <div v-if="!hasItems" class="rounded-lg border border-indigo-200 bg-indigo-50 p-5 text-sm text-indigo-950">
                    <h3 class="font-semibold">Mixed practice set workflow</h3>
                    <ol class="mt-2 list-decimal space-y-1 pl-5">
                        <li>Upload the chapter PDF (stored on server — download link above).</li>
                        <li>Copy the AI prompt → paste in Cursor, Claude, or Gemini with the PDF. Covers every example and exercise as fill-blank (numeric) or MCQ (8 options).</li>
                        <li>Import — paste JSON or upload a .zip pack with questions.json + chart images.</li>
                        <li>Review questions, upload figures, run <strong>Gemini check</strong>, then publish one mixed set.</li>
                    </ol>
                </div>

                <div v-if="showImportSteps" class="space-y-4 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-900">Step 2 — AI prompt (mixed fill-blank + MCQ)</h3>
                            <p class="mt-1 text-sm text-gray-600">Copy into Claude/Cursor/Gemini with the chapter PDF. Numeric answers become fill-in-blank; others stay MCQ with 8 options.</p>
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

                <div v-if="showImportSteps" class="space-y-4 rounded-lg border-2 border-emerald-300 bg-emerald-50 p-6 shadow-sm">
                    <div>
                        <h3 class="font-semibold text-emerald-950">Step 3a — Import zip pack (charts / pictures)</h3>
                        <p class="mt-1 text-sm text-emerald-900">
                            Upload a zip with <strong>questions.json</strong> plus PNG/JPG images.
                            In JSON, set <strong>"needs_diagram": true</strong> and
                            <strong>"diagram_file": "chart1.png"</strong> (or <strong>"chart_file"</strong>) on each question that needs a figure.
                            Start the chart text with <strong>THIS QUESTION REQUIRES A FIGURE UPLOAD —</strong> so reviewers know to upload the image.
                            Multiple questions can share one image. Optional <strong>chart</strong> / <strong>table</strong> text is kept as backup.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <PrimaryButton
                            type="button"
                            :disabled="zipImportForm.processing"
                            @click="zipPackInput?.click()"
                        >
                            {{ zipImportForm.processing ? 'Importing…' : 'Upload .zip pack → import MCQs' }}
                        </PrimaryButton>
                        <InputError :message="zipImportForm.errors.pack" />
                    </div>
                    <input
                        ref="zipPackInput"
                        type="file"
                        accept=".zip,application/zip"
                        class="hidden"
                        @change="onZipPackSelected"
                    />
                </div>

                <div v-if="showImportSteps" class="space-y-4 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div>
                        <h3 class="font-semibold text-gray-900">Step 3b — Or paste JSON</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Paste AI output here (JSON with <strong>questions</strong> only). Text-only charts/tables — no images.
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
