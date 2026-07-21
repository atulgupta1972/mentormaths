<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChapterQuestionPlan from '@/Components/ChapterQuestionPlan.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import WorksheetPdfViewer from '@/Components/WorksheetPdfViewer.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    defaultFillBlankRow,
    fillBlankFormats,
    parseFillBlankJson,
} from '@/utils/fillBlankImport';

const props = defineProps({
    gradeLevel: { type: Object, default: null },
    chapters: { type: Array, default: () => [] },
    topics: { type: Array, default: () => [] },
    questions: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    cursorPrompt: { type: String, default: '' },
    promptOptions: { type: Object, default: () => ({}) },
    chapterPlan: { type: Array, default: () => [] },
    manualQuestionsDraft: { type: Array, default: () => [] },
    answerKeyDraft: { type: Array, default: () => [] },
    selectedQuestionIds: { type: Array, default: () => [] },
    supportsDiagrams: { type: Boolean, default: false },
});

const page = usePage();

const promptSettings = ref({
    total: props.promptOptions?.total ?? 6,
    easy: props.promptOptions?.easy ?? 2,
    medium: props.promptOptions?.medium ?? 2,
    hard: props.promptOptions?.hard ?? 2,
    focus: props.promptOptions?.focus ?? '',
});

const topicScope = ref(props.filters.topic_scope || 'one');
const selectedTopicIds = ref(
    (props.filters.topic_ids || []).map((id) => String(id)),
);

const buildDefaultChapterPlan = () => props.topics.map((topic, index) => ({
    topic_id: topic.id,
    topic_name: topic.name,
    easy: 0,
    medium: 0,
    hard: 0,
    sort_order: index + 1,
}));

const chapterPlanRows = ref(
    props.chapterPlan?.length
        ? props.chapterPlan
        : buildDefaultChapterPlan(),
);

const generatingChapterPrompt = ref(false);
const zipPackInput = ref(null);
const writtenSheetZipInput = ref(null);
const zipImportForm = useForm({
    pack: null,
    scope: 'chapter',
    syllabus_chapter_id: '',
    syllabus_topic_id: '',
    after_import: 'written_sheet',
    written_sheet_kind: '',
    written_topic_scope: '',
});
const writtenSheetZipForm = useForm({
    pack: null,
    chapter_id: '',
    sheet_kind: '',
    topic_id: '',
    topic_scope: '',
    notes: '',
});

const defaultAnswerKeyRow = (topicName = '') => ({
    correct_answer: '',
    answer_format: 'integer',
    method_hint: '',
    topic_name: topicName,
    syllabus_topic_id: '',
});

const pdfInput = ref(null);
const answerPdfInput = ref(null);
const selectedPdfName = ref('');
const selectedAnswerPdfName = ref('');
const pdfImportToken = ref('');
const pdfPreviewUrl = ref('');
const pdfStageWarning = ref('');
const pdfStaging = ref(false);
const pdfStageError = ref('');
const answerPdfParsing = ref(false);
const answerPdfError = ref('');
const answerPdfWarnings = ref([]);
const answerPdfParsedCount = ref(0);
const worksheetEstimatedQuestions = ref(null);
const answerKeyJsonInput = ref('');
const answerKeyJsonError = ref('');

const form = useForm({
    source_mode: props.filters.source_mode || 'bank',
    sheet_kind: props.filters.sheet_kind || 'practice',
    chapter_id: props.filters.chapter_id || '',
    topic_scope: props.filters.topic_scope || 'one',
    topic_id: props.filters.topic_id || '',
    topic_ids: (props.filters.topic_ids || []).map((id) => Number(id)),
    question_ids: props.selectedQuestionIds?.length
        ? [...props.selectedQuestionIds]
        : (props.filters.question_ids?.length ? [...props.filters.question_ids] : []),
    manual_questions: props.manualQuestionsDraft?.length
        ? props.manualQuestionsDraft
        : [defaultFillBlankRow()],
    answer_key: props.answerKeyDraft?.length
        ? props.answerKeyDraft
        : [defaultAnswerKeyRow()],
    pdf_import_token: '',
    chapter_plan: [],
    notes: '',
});

const filtersInitialized = ref(false);

const selectedTopicName = computed(() =>
    props.topics.find((topic) => String(topic.id) === String(form.topic_id))?.name || '',
);

const jsonInput = ref('');
const jsonError = ref('');
const copiedPrompt = ref(false);
const copyError = ref('');
const promptBox = ref(null);

const difficultySum = computed(
    () => Number(promptSettings.value.easy) + Number(promptSettings.value.medium) + Number(promptSettings.value.hard),
);

const difficultyMismatch = computed(
    () => difficultySum.value !== Number(promptSettings.value.total),
);

const isOneTopicScope = computed(() => topicScope.value === 'one');
const isMultipleTopicScope = computed(() => topicScope.value === 'multiple');

const allTopicsSelected = computed(() =>
    props.topics.length > 0 && selectedTopicIds.value.length === props.topics.length,
);

const showTopicPicker = computed(() => form.sheet_kind === 'practice');
const isBankMode = computed(() => form.source_mode === 'bank');
const isManualMode = computed(() => form.source_mode === 'manual');
const isPdfMode = computed(() => form.source_mode === 'pdf');
const useChapterCursorPlan = computed(() =>
    isManualMode.value
    && form.chapter_id
    && (form.sheet_kind === 'test' || isMultipleTopicScope.value),
);

const visibleChapterPlanRows = computed(() => {
    if (useChapterCursorPlan.value) {
        return chapterPlanRows.value;
    }

    const selected = new Set(selectedTopicIds.value.map(String));

    return chapterPlanRows.value.filter((row) => selected.has(String(row.topic_id)));
});

const chapterPromptPlan = computed(() =>
    visibleChapterPlanRows.value.filter(
        (row) => (Number(row.easy) || 0) + (Number(row.medium) || 0) + (Number(row.hard) || 0) > 0,
    ),
);

const editableChapterPlan = computed({
    get: () => visibleChapterPlanRows.value,
    set: (rows) => {
        const updates = new Map(rows.map((row) => [String(row.topic_id), row]));

        chapterPlanRows.value = chapterPlanRows.value.map((row) => {
            const updated = updates.get(String(row.topic_id));

            return updated ? { ...row, ...updated } : row;
        });
    },
});

const canShowSingleTopicPrompt = computed(() =>
    form.chapter_id
    && form.sheet_kind === 'practice'
    && isOneTopicScope.value
    && Boolean(form.topic_id),
);

const cursorPromptHint = computed(() => {
    if (!form.chapter_id) {
        return 'Select a chapter first.';
    }

    if (useChapterCursorPlan.value && chapterPromptPlan.value.length === 0) {
        return 'Set easy / medium / hard for at least one topic, then generate the Cursor prompt.';
    }

    if (canShowSingleTopicPrompt.value) {
        return '';
    }

    if (form.sheet_kind === 'practice' && isOneTopicScope.value && !form.topic_id) {
        return 'Select a topic, then click Refresh prompt.';
    }

    if (useChapterCursorPlan.value && !props.cursorPrompt) {
        return 'Set easy / medium / hard per topic, then click Generate Cursor prompt for chapter.';
    }

    return '';
});

function buildQueryParams() {
    const params = {
        chapter_id: form.chapter_id || undefined,
        sheet_kind: form.sheet_kind,
        source_mode: form.source_mode,
        topic_scope: topicScope.value,
    };

    if (showTopicPicker.value) {
        if (isOneTopicScope.value) {
            params.topic_id = form.topic_id || undefined;
        } else {
            params.topic_ids = selectedTopicIds.value.map(Number);
        }
    }

    if (!useChapterCursorPlan.value) {
        params.total = promptSettings.value.total;
        params.easy = promptSettings.value.easy;
        params.medium = promptSettings.value.medium;
        params.hard = promptSettings.value.hard;

        if (promptSettings.value.focus) {
            params.focus = promptSettings.value.focus;
        }
    }

    return params;
}

const refreshPrompt = () => {
    if (!canShowSingleTopicPrompt.value) {
        return;
    }

    router.get(route('admin.written-sheets.create'), buildQueryParams(), {
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
};

const generateChapterPrompt = () => {
    if (!form.chapter_id || generatingChapterPrompt.value || chapterPromptPlan.value.length === 0) {
        return;
    }

    generatingChapterPrompt.value = true;

    router.post(route('admin.written-sheets.chapter-prompt'), {
        chapter_id: Number(form.chapter_id),
        sheet_kind: form.sheet_kind,
        source_mode: form.source_mode,
        topic_scope: topicScope.value,
        topic_ids: isMultipleTopicScope.value ? selectedTopicIds.value.map(Number) : undefined,
        plan: chapterPromptPlan.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            generatingChapterPrompt.value = false;
        },
    });
};

const planHasCounts = (rows) => rows.some(
    (row) => (Number(row.easy) || 0) + (Number(row.medium) || 0) + (Number(row.hard) || 0) > 0,
);

const mergeChapterPlan = (incoming) => {
    if (!incoming?.length) {
        return;
    }

    chapterPlanRows.value = incoming;
};

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

const syncChapterPlanFromImportedRows = (rows) => {
    if (!useChapterCursorPlan.value || !rows.length) {
        return;
    }

    const counts = {};

    rows.forEach((row) => {
        const topicId = resolveTopicIdForRow(row);
        if (!topicId) {
            return;
        }

        const key = String(topicId);
        counts[key] ??= { easy: 0, medium: 0, hard: 0 };

        const difficulty = String(row.difficulty || 'medium').trim().toLowerCase();
        if (difficulty.startsWith('e')) {
            counts[key].easy += 1;
        } else if (difficulty.startsWith('h')) {
            counts[key].hard += 1;
        } else {
            counts[key].medium += 1;
        }
    });

    chapterPlanRows.value = chapterPlanRows.value.map((row) => {
        const count = counts[String(row.topic_id)];
        if (!count) {
            return row;
        }

        return {
            ...row,
            easy: count.easy,
            medium: count.medium,
            hard: count.hard,
        };
    });
};

const syncFormTopicFields = () => {
    form.topic_scope = topicScope.value;

    if (isOneTopicScope.value) {
        form.topic_ids = form.topic_id ? [Number(form.topic_id)] : [];
    } else {
        form.topic_id = '';
        form.topic_ids = selectedTopicIds.value.map(Number);
    }
};

watch(
    () => props.chapterPlan,
    (plan) => {
        mergeChapterPlan(plan);
    },
);

watch(
    () => props.manualQuestionsDraft,
    (rows) => {
        if (rows?.length) {
            form.manual_questions = rows;
            syncChapterPlanFromImportedRows(rows);
        }
    },
    { immediate: true },
);

watch(
    () => props.answerKeyDraft,
    (rows) => {
        if (rows?.length) {
            form.answer_key = rows;
            answerPdfParsedCount.value = rows.filter((row) => String(row.correct_answer || '').trim()).length;
        }
    },
    { immediate: true },
);

watch(
    () => form.chapter_id,
    (chapterId, previousId) => {
        if (!chapterId || chapterId === previousId) {
            return;
        }

        form.topic_id = '';
        selectedTopicIds.value = [];
        chapterPlanRows.value = props.chapterPlan?.length
            ? props.chapterPlan
            : buildDefaultChapterPlan();
    },
);

watch(
    () => props.topics,
    (topics) => {
        if (!topics.length) {
            selectedTopicIds.value = [];
            if (!planHasCounts(chapterPlanRows.value)) {
                chapterPlanRows.value = [];
            }

            return;
        }

        if (isMultipleTopicScope.value && selectedTopicIds.value.length === 0) {
            selectedTopicIds.value = topics.map((topic) => String(topic.id));
        }

        if (chapterPlanRows.value.length === 0) {
            chapterPlanRows.value = props.chapterPlan?.length
                ? props.chapterPlan
                : buildDefaultChapterPlan();
        }
    },
    { immediate: true },
);

watch(
    () => [form.chapter_id, form.topic_id, form.sheet_kind, form.source_mode, topicScope.value, selectedTopicIds.value.join(',')],
    () => {
        syncFormTopicFields();

        if (!filtersInitialized.value) {
            filtersInitialized.value = true;

            return;
        }

        if (!form.chapter_id) {
            return;
        }

        router.get(route('admin.written-sheets.create'), buildQueryParams(), {
            preserveState: true,
            replace: true,
            preserveScroll: true,
        });
    },
);

watch(topicScope, (scope) => {
    if (scope === 'multiple' && props.topics.length && selectedTopicIds.value.length === 0) {
        selectedTopicIds.value = props.topics.map((topic) => String(topic.id));
    }

    if (scope === 'one') {
        form.topic_id = selectedTopicIds.value[0] || form.topic_id || '';
    }
});

watch(selectedTopicName, (name) => {
    if (!name || !isOneTopicScope.value) {
        return;
    }

    if (form.source_mode === 'manual') {
        form.manual_questions = form.manual_questions.map((row) => ({
            ...row,
            topic_name: row.topic_name || name,
        }));
    }

    if (form.source_mode === 'pdf') {
        form.answer_key = form.answer_key.map((row) => ({
            ...row,
            topic_name: row.topic_name || name,
        }));
    }
});

const toggleTopic = (topicId) => {
    const id = String(topicId);
    const ids = new Set(selectedTopicIds.value);

    if (ids.has(id)) {
        ids.delete(id);
    } else {
        ids.add(id);
    }

    selectedTopicIds.value = [...ids];
};

const toggleAllTopics = () => {
    selectedTopicIds.value = allTopicsSelected.value
        ? []
        : props.topics.map((topic) => String(topic.id));
};

const allQuestionsSelected = computed(() =>
    props.questions.length > 0 && form.question_ids.length === props.questions.length,
);

const toggleQuestion = (id) => {
    const ids = new Set(form.question_ids);

    if (ids.has(id)) {
        ids.delete(id);
    } else {
        ids.add(id);
    }

    form.question_ids = [...ids];
};

const toggleSelectAll = () => {
    form.question_ids = allQuestionsSelected.value
        ? []
        : props.questions.map((question) => question.id);
};

const addManualRow = () => {
    form.manual_questions.push(defaultFillBlankRow(selectedTopicName.value));
};

const addAnswerKeyRow = () => {
    form.answer_key.push(defaultAnswerKeyRow(selectedTopicName.value));
};

const removeAnswerKeyRow = (index) => {
    if (form.answer_key.length === 1) {
        form.answer_key[0] = defaultAnswerKeyRow(selectedTopicName.value);

        return;
    }

    form.answer_key.splice(index, 1);
};

const removeManualRow = (index) => {
    if (form.manual_questions.length === 1) {
        form.manual_questions[0] = defaultFillBlankRow(selectedTopicName.value);

        return;
    }

    form.manual_questions.splice(index, 1);
};

const importJson = () => {
    jsonError.value = '';

    try {
        const rows = parseFillBlankJson(jsonInput.value).map((row) => {
            const topicId = resolveTopicIdForRow(row);
            const topicName = row.topic_name
                || props.topics.find((topic) => topic.id === topicId)?.name
                || selectedTopicName.value;

            return sanitizeManualRow({
                ...defaultFillBlankRow(topicName),
                ...row,
                topic_name: topicName,
                syllabus_topic_id: topicId || '',
            });
        });

        form.manual_questions = rows;
        syncChapterPlanFromImportedRows(rows);
        jsonInput.value = '';
    } catch (error) {
        jsonError.value = error.message || 'Could not parse JSON.';
    }
};

const copyPrompt = async () => {
    if (!props.cursorPrompt) {
        return;
    }

    copyError.value = '';

    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(props.cursorPrompt);
            copiedPrompt.value = true;
            window.setTimeout(() => {
                copiedPrompt.value = false;
            }, 2500);

            return;
        }
    } catch {
        // Fall through to manual select below.
    }

    promptBox.value?.focus();
    promptBox.value?.select();

    try {
        document.execCommand('copy');
        copiedPrompt.value = true;
        window.setTimeout(() => {
            copiedPrompt.value = false;
        }, 2500);
    } catch {
        copyError.value = 'Could not copy automatically. Select the prompt text and press Ctrl+C.';
    }
};

const selectPrompt = () => {
    promptBox.value?.select();
};

const validTopicIds = computed(() => new Set(props.topics.map((topic) => String(topic.id))));

const sanitizeManualRow = (row) => {
    const topicId = row.syllabus_topic_id ? String(row.syllabus_topic_id) : '';
    const answerFormat = fillBlankFormats.includes(row.answer_format) ? row.answer_format : 'text';

    return {
        ...row,
        answer_format: answerFormat,
        syllabus_topic_id: validTopicIds.value.has(topicId) ? Number(topicId) : '',
    };
};

const formErrorEntries = computed(() =>
    Object.entries(form.errors).filter(([, message]) => Boolean(message)),
);

const manualRowError = (index, field) => form.errors[`manual_questions.${index}.${field}`] ?? '';

const sanitizeAnswerKeyRow = (row) => {
    const topicId = row.syllabus_topic_id ? String(row.syllabus_topic_id) : '';
    const answerFormat = fillBlankFormats.includes(row.answer_format) ? row.answer_format : 'text';

    return {
        ...row,
        answer_format: answerFormat,
        syllabus_topic_id: validTopicIds.value.has(topicId) ? Number(topicId) : '',
    };
};

const validAnswerKeyRows = computed(() =>
    form.answer_key
        .map(sanitizeAnswerKeyRow)
        .filter((row) => String(row.correct_answer || '').trim()),
);

const answerKeyRowError = (index, field) => form.errors[`answer_key.${index}.${field}`] ?? '';

const importAnswerKeyJson = () => {
    answerKeyJsonError.value = '';

    try {
        let rows;

        try {
            rows = parseFillBlankJson(answerKeyJsonInput.value).map((row) => sanitizeAnswerKeyRow({
                ...defaultAnswerKeyRow(row.topic_name || selectedTopicName.value),
                correct_answer: row.correct_answer,
                answer_format: row.answer_format,
                method_hint: row.method_hint,
                topic_name: row.topic_name || selectedTopicName.value,
                syllabus_topic_id: row.syllabus_topic_id || '',
            }));
        } catch {
            const cleaned = answerKeyJsonInput.value.trim()
                .replace(/^```(?:json)?\s*/i, '')
                .replace(/\s*```$/i, '');
            const data = JSON.parse(cleaned);
            const items = Array.isArray(data?.questions) ? data.questions : (Array.isArray(data) ? data : []);

            if (!items.length) {
                throw new Error('No answers found in JSON.');
            }

            rows = items.map((item, index) => {
                const correctAnswer = String(item.correct_answer ?? item.answer ?? '').trim();

                if (!correctAnswer) {
                    throw new Error(`Answer ${index + 1} is missing correct_answer.`);
                }

                const answerFormat = String(item.answer_format ?? item.format ?? 'integer').trim().toLowerCase();

                return sanitizeAnswerKeyRow({
                    ...defaultAnswerKeyRow(String(item.topic ?? item.topic_name ?? selectedTopicName.value)),
                    correct_answer: correctAnswer,
                    answer_format: fillBlankFormats.includes(answerFormat) ? answerFormat : 'text',
                    method_hint: String(item.method_hint ?? item.hint ?? '').trim(),
                    topic_name: String(item.topic ?? item.topic_name ?? selectedTopicName.value).trim(),
                    syllabus_topic_id: item.syllabus_topic_id ?? item.topic_id ?? '',
                });
            });
        }

        form.answer_key = rows;
        answerKeyJsonInput.value = '';
    } catch (error) {
        answerKeyJsonError.value = error.message || 'Could not parse JSON.';
    }
};

const onPdfSelected = (event) => {
    const file = event.target.files?.[0] ?? null;
    selectedPdfName.value = file?.name ?? '';
    pdfStageError.value = '';
    pdfImportToken.value = '';
    pdfPreviewUrl.value = '';
    pdfStageWarning.value = '';
    worksheetEstimatedQuestions.value = null;
    form.pdf_import_token = '';
    selectedAnswerPdfName.value = '';
    answerPdfParsedCount.value = 0;
    answerPdfWarnings.value = [];
    if (answerPdfInput.value) {
        answerPdfInput.value.value = '';
    }
};

const stageUploadedPdf = async () => {
    const file = pdfInput.value?.files?.[0];

    if (!file) {
        pdfStageError.value = 'Choose a PDF file first.';

        return;
    }

    pdfStaging.value = true;
    pdfStageError.value = '';

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
            pdfStageError.value = payload.error || 'Could not upload PDF.';

            return;
        }

        pdfImportToken.value = payload.token;
        pdfPreviewUrl.value = payload.pdf_url;
        pdfStageWarning.value = payload.warning || '';
        worksheetEstimatedQuestions.value = payload.estimated_question_count ?? null;
        form.pdf_import_token = payload.token;
    } catch {
        pdfStageError.value = 'Could not upload PDF.';
    } finally {
        pdfStaging.value = false;
    }
};

const onAnswerPdfSelected = (event) => {
    const file = event.target.files?.[0] ?? null;
    selectedAnswerPdfName.value = file?.name ?? '';
    answerPdfError.value = '';
    answerPdfWarnings.value = [];
    answerPdfParsedCount.value = 0;
};

const parseAnswerSheetPdf = async () => {
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

        if (form.pdf_import_token) {
            formData.append('worksheet_pdf_token', form.pdf_import_token);
        }

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

        form.answer_key = (payload.answer_key || []).map((row) => sanitizeAnswerKeyRow({
            ...defaultAnswerKeyRow(selectedTopicName.value),
            ...row,
            topic_name: row.topic_name || selectedTopicName.value,
        }));
        answerPdfParsedCount.value = payload.parsed_count || form.answer_key.length;
        answerPdfWarnings.value = payload.warnings || [];
    } catch {
        answerPdfError.value = 'Could not read the answer sheet PDF.';
    } finally {
        answerPdfParsing.value = false;
    }
};

const mcqImportLink = computed(() => {
    const params = {
        mode: 'pdf_worksheet',
    };

    if (form.chapter_id) {
        params.syllabus_chapter_id = form.chapter_id;
    }

    if (form.topic_id) {
        params.syllabus_topic_id = form.topic_id;
    }

    return route('admin.questions.create', params);
});

const zipImportScope = computed(() => {
    if (form.sheet_kind === 'test' || isMultipleTopicScope.value) {
        return 'chapter';
    }

    return isOneTopicScope.value && form.topic_id ? 'topic' : 'chapter';
});

const canImportZipPack = computed(() => {
    if (!form.chapter_id) {
        return false;
    }

    if (zipImportScope.value === 'topic') {
        return Boolean(form.topic_id);
    }

    return true;
});

const canImportWrittenSheetZip = computed(() => {
    if (!form.chapter_id) {
        return false;
    }

    if (form.sheet_kind === 'test' || isMultipleTopicScope.value) {
        return true;
    }

    return isOneTopicScope.value && Boolean(form.topic_id);
});

const onWrittenSheetZipSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }

    if (!canImportWrittenSheetZip.value) {
        event.target.value = '';

        return;
    }

    writtenSheetZipForm.pack = file;
    writtenSheetZipForm.chapter_id = form.chapter_id;
    writtenSheetZipForm.sheet_kind = form.sheet_kind;
    writtenSheetZipForm.topic_id = form.topic_id || '';
    writtenSheetZipForm.topic_scope = topicScope.value;
    writtenSheetZipForm.notes = form.notes;

    writtenSheetZipForm.post(route('admin.written-sheets.import-zip-pack'), {
        forceFormData: true,
    });

    event.target.value = '';
};

const onZipPackSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }

    if (!canImportZipPack.value) {
        event.target.value = '';

        return;
    }

    zipImportForm.pack = file;
    zipImportForm.scope = zipImportScope.value;
    zipImportForm.syllabus_chapter_id = zipImportScope.value === 'chapter' ? form.chapter_id : '';
    zipImportForm.syllabus_topic_id = zipImportScope.value === 'topic' ? form.topic_id : '';
    zipImportForm.written_sheet_kind = form.sheet_kind;
    zipImportForm.written_topic_scope = topicScope.value;

    zipImportForm.post(route('admin.questions.import-zip-pack'), {
        forceFormData: true,
    });

    event.target.value = '';
};

const validManualRows = computed(() =>
    form.manual_questions
        .map(sanitizeManualRow)
        .filter(
            (row) => String(row.question_text || '').trim() && String(row.correct_answer || '').trim(),
        ),
);

const hasTopicSelection = computed(() => {
    if (form.sheet_kind === 'test') {
        return Boolean(form.chapter_id);
    }

    if ((isManualMode.value || isPdfMode.value) && isMultipleTopicScope.value) {
        return Boolean(form.chapter_id);
    }

    if (isOneTopicScope.value) {
        return Boolean(form.topic_id);
    }

    return selectedTopicIds.value.length > 0;
});

const hasWorksheetPdfToken = computed(() => Boolean(pdfImportToken.value || form.pdf_import_token));

const pdfReadiness = computed(() => ({
    chapter: Boolean(form.chapter_id),
    topic: hasTopicSelection.value,
    worksheetPdf: hasWorksheetPdfToken.value,
    answers: validAnswerKeyRows.value.length > 0,
}));

const submitBlockedReason = computed(() => {
    if (!isPdfMode.value || canSubmit.value) {
        return '';
    }

    if (!form.chapter_id) {
        return 'Select a chapter first.';
    }

    if (!hasTopicSelection.value) {
        return form.sheet_kind === 'test'
            ? 'Select a chapter for this test sheet.'
            : 'Select a topic for this practice sheet.';
    }

    if (!hasWorksheetPdfToken.value) {
        return 'Upload the worksheet PDF first (click “Upload PDF preview” in the green/blue box above).';
    }

    if (validAnswerKeyRows.value.length === 0) {
        return 'Add at least one answer — upload an answer sheet PDF or fill in the answer rows.';
    }

    return 'Complete all steps above before saving.';
});

const canSubmit = computed(() => {
    if (isBankMode.value) {
        return form.question_ids.length > 0 && hasTopicSelection.value;
    }

    if (isPdfMode.value) {
        return hasWorksheetPdfToken.value
            && validAnswerKeyRows.value.length > 0
            && hasTopicSelection.value;
    }

    return validManualRows.value.length > 0 && hasTopicSelection.value;
});

const submitLabel = computed(() => {
    if (isPdfMode.value) {
        return 'Save uploaded PDF for review';
    }

    return 'Generate PDF for review';
});

const submit = () => {
    syncFormTopicFields();

    if (isPdfMode.value) {
        form.pdf_import_token = pdfImportToken.value || form.pdf_import_token;
        form.answer_key = validAnswerKeyRows.value.map((row) => ({ ...row }));
        form.manual_questions = [];
        form.question_ids = [];
    } else if (isManualMode.value) {
        form.manual_questions = validManualRows.value.map((row) => ({ ...row }));
        form.answer_key = [];
        form.pdf_import_token = '';
        form.question_ids = [];
    } else {
        form.manual_questions = [];
        form.answer_key = [];
        form.pdf_import_token = '';
    }

    form.chapter_plan = useChapterCursorPlan.value ? chapterPlanRows.value : [];

    if (!canSubmit.value) {
        return;
    }

    form.post(route('admin.written-sheets.store'), {
        preserveScroll: true,
        onError: () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
    });
};
</script>

<template>
    <Head title="Create written sheet" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">Create written sheet</h2>
                <Link :href="route('admin.written-sheets.index')" class="text-sm text-indigo-600">Back</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <form class="space-y-6 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200" @submit.prevent="submit">
                    <div v-if="page.props.flash?.error" class="rounded-md bg-rose-50 p-4 text-sm text-rose-800">
                        {{ page.props.flash.error }}
                    </div>
                    <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 p-4 text-sm text-green-800">
                        {{ page.props.flash.success }}
                    </div>
                    <div v-if="page.props.flash?.warning" class="rounded-md bg-amber-50 p-4 text-sm text-amber-900">
                        {{ page.props.flash.warning }}
                    </div>

                    <div v-if="formErrorEntries.length" class="rounded-md bg-rose-50 p-4 text-sm text-rose-800">
                        <p>Please fix the following errors before generating the PDF:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li v-for="[field, message] in formErrorEntries" :key="field">
                                {{ message }}
                            </li>
                        </ul>
                    </div>

                    <p class="text-sm text-gray-600">
                        Step 1: pick chapter/topic and questions. Use the bank when available, or type / paste from Cursor when a new chapter has no practice sets yet.
                    </p>

                    <div>
                        <InputLabel value="Question source" />
                        <div class="mt-2 flex flex-wrap gap-4 text-sm">
                            <label class="inline-flex items-center gap-2">
                                <input v-model="form.source_mode" type="radio" value="bank" class="text-indigo-600">
                                From question bank
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input v-model="form.source_mode" type="radio" value="manual" class="text-indigo-600">
                                Type manually / paste from Cursor
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input v-model="form.source_mode" type="radio" value="pdf" class="text-indigo-600">
                                Upload your PDF + answer key
                            </label>
                        </div>
                        <p v-if="isPdfMode" class="mt-2 text-xs text-gray-600">
                            Use your existing chapter PDF as the written worksheet. Upload a separate answer sheet PDF or enter answers manually — the system maps them to Q1, Q2, … for AI checking when students upload photos.
                            For online MCQ from the same PDF, use
                            <Link :href="mcqImportLink" class="font-medium text-indigo-700 hover:underline">Question bank → PDF worksheet</Link>.
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Sheet type" />
                            <select v-model="form.sheet_kind" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option value="practice">Practice</option>
                                <option value="test">Test</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Chapter" />
                            <select v-model="form.chapter_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option value="">Select chapter</option>
                                <option v-for="chapter in chapters" :key="chapter.id" :value="String(chapter.id)">
                                    {{ chapter.label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.chapter_id" class="mt-1" />
                        </div>
                    </div>

                    <div v-if="showTopicPicker && form.chapter_id" class="rounded-lg border border-gray-200 bg-gray-50/70 p-4">
                        <InputLabel value="Topics for this practice sheet" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-2 max-w-3xl">
                            <button
                                type="button"
                                class="rounded-lg border p-3 text-left transition"
                                :class="isOneTopicScope
                                    ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
                                    : 'border-gray-200 bg-white hover:border-gray-300'"
                                @click="topicScope = 'one'"
                            >
                                <p class="font-medium text-gray-900">One topic</p>
                                <p class="mt-1 text-xs text-gray-500">Pick a single topic for this written practice sheet</p>
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border p-3 text-left transition"
                                :class="isMultipleTopicScope
                                    ? 'border-sky-500 bg-sky-50 ring-1 ring-sky-500'
                                    : 'border-gray-200 bg-white hover:border-gray-300'"
                                @click="topicScope = 'multiple'"
                            >
                                <p class="font-medium text-gray-900">Multiple topics</p>
                                <p class="mt-1 text-xs text-gray-500">Select all topics or pick several from the list</p>
                            </button>
                        </div>

                        <div v-if="isOneTopicScope" class="mt-4">
                            <InputLabel value="Topic" />
                            <select v-model="form.topic_id" class="mt-1 block w-full max-w-3xl rounded-md border-gray-300 text-sm">
                                <option value="">Select topic</option>
                                <option v-for="topic in topics" :key="topic.id" :value="String(topic.id)">
                                    {{ topic.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.topic_id" class="mt-1" />
                        </div>

                        <div v-else-if="!isManualMode && !isPdfMode" class="mt-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <InputLabel :value="`Selected topics (${selectedTopicIds.length})`" />
                                <button
                                    type="button"
                                    class="text-xs font-medium text-indigo-600 hover:underline"
                                    @click="toggleAllTopics"
                                >
                                    {{ allTopicsSelected ? 'Clear all' : 'Select all' }}
                                </button>
                            </div>
                            <div class="mt-2 max-h-48 space-y-1 overflow-y-auto rounded-lg border border-gray-200 bg-white p-3">
                                <label
                                    v-for="topic in topics"
                                    :key="topic.id"
                                    class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-800 hover:bg-gray-50"
                                >
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-indigo-600"
                                        :checked="selectedTopicIds.includes(String(topic.id))"
                                        @change="toggleTopic(topic.id)"
                                    >
                                    {{ topic.name }}
                                </label>
                            </div>
                            <p v-if="topics.length === 0" class="mt-2 text-xs text-amber-700">No topics in this chapter.</p>
                        </div>

                        <p v-else-if="isManualMode" class="mt-4 text-sm text-gray-600">
                            Set easy / medium / hard per topic in the chapter plan below, then generate the Cursor prompt.
                        </p>
                        <p v-else-if="isPdfMode" class="mt-4 text-sm text-gray-600">
                            For chapter tests or multi-topic practice sheets, you can set a topic name per answer row below.
                        </p>
                    </div>

                    <div v-if="isPdfMode && form.chapter_id" class="rounded-lg border-2 border-sky-300 bg-sky-50 p-4">
                        <h3 class="font-semibold text-sky-950">Upload worksheet PDF</h3>
                        <p class="mt-1 text-sm text-sky-900">
                            Upload the PDF you already use in class (diagrams, layout, branding). Students download this file; AI grading uses your answer key below.
                        </p>

                        <input
                            ref="pdfInput"
                            type="file"
                            accept="application/pdf,.pdf"
                            class="mt-4 block w-full max-w-lg text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-sky-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-sky-800"
                            @change="onPdfSelected"
                        >

                        <p v-if="selectedPdfName" class="mt-2 text-sm font-medium text-gray-700">
                            Selected: {{ selectedPdfName }}
                        </p>

                        <p v-if="pdfStageError" class="mt-2 text-sm text-rose-700">{{ pdfStageError }}</p>
                        <InputError :message="form.errors.pdf_import_token" class="mt-2" />

                        <PrimaryButton
                            type="button"
                            class="mt-4"
                            :disabled="pdfStaging || !selectedPdfName"
                            @click="stageUploadedPdf"
                        >
                            {{ pdfStaging ? 'Uploading…' : pdfImportToken ? 'PDF uploaded — choose another to replace' : 'Upload PDF preview' }}
                        </PrimaryButton>

                        <p v-if="pdfStageWarning" class="mt-2 text-xs text-amber-800">{{ pdfStageWarning }}</p>
                        <p v-if="pdfImportToken" class="mt-2 text-xs text-emerald-800">PDF ready. Add answer rows below, then save for review.</p>

                        <WorksheetPdfViewer
                            v-if="pdfPreviewUrl"
                            class="mt-6"
                            :url="pdfPreviewUrl"
                            title="Uploaded worksheet preview"
                            helper-text="This is the PDF students will download. Match each answer row to question numbers in the PDF."
                        />
                    </div>

                    <div v-if="form.chapter_id" class="rounded-lg border-2 border-emerald-300 bg-emerald-50 p-4">
                        <h3 class="font-semibold text-emerald-950">Diagram sums — upload .zip pack</h3>
                        <p class="mt-1 text-sm text-emerald-900">
                            Zip with <strong>questions.json</strong> plus diagram images (<strong>q1.png</strong>, <strong>q2.png</strong>, …).
                            In JSON, set <strong>"needs_diagram": true</strong> and <strong>"diagram_file": "q1.png"</strong> for geometry sums
                            <span v-if="supportsDiagrams">(this chapter supports diagrams)</span>.
                            For algebra sums, omit <strong>needs_diagram</strong> — the system ignores it.
                        </p>
                        <p class="mt-2 text-xs text-emerald-800">
                            Layout: <span class="font-mono">questions.json, q1.png, q2.png, …</span>.
                            For crispest diagrams, draw in GeoGebra or PowerPoint and export PNG (~800×500 px).
                        </p>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <PrimaryButton
                                type="button"
                                :disabled="!canImportWrittenSheetZip || writtenSheetZipForm.processing"
                                @click="writtenSheetZipInput?.click()"
                            >
                                {{ writtenSheetZipForm.processing ? 'Creating…' : 'Upload zip → generate written PDF' }}
                            </PrimaryButton>
                            <SecondaryButton
                                type="button"
                                :disabled="!canImportZipPack || zipImportForm.processing"
                                @click="zipPackInput?.click()"
                            >
                                {{ zipImportForm.processing ? 'Importing…' : 'Import zip to bank only' }}
                            </SecondaryButton>
                            <InputError :message="writtenSheetZipForm.errors.pack || zipImportForm.errors.pack" />
                        </div>
                        <p v-if="!canImportWrittenSheetZip" class="mt-2 text-xs text-amber-800">
                            Select chapter{{ isOneTopicScope && form.sheet_kind === 'practice' ? ' and topic' : '' }} first.
                        </p>
                        <input
                            ref="writtenSheetZipInput"
                            type="file"
                            accept=".zip,application/zip"
                            class="hidden"
                            @change="onWrittenSheetZipSelected"
                        >
                        <input
                            ref="zipPackInput"
                            type="file"
                            accept=".zip,application/zip"
                            class="hidden"
                            @change="onZipPackSelected"
                        >
                    </div>

                    <div v-if="isBankMode && questions.length">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <InputLabel :value="`Select questions (${form.question_ids.length} selected)`" />
                            <button
                                type="button"
                                class="text-xs font-medium text-indigo-600 hover:underline"
                                @click="toggleSelectAll"
                            >
                                {{ allQuestionsSelected ? 'Clear all' : 'Select all' }}
                            </button>
                        </div>
                        <div class="mt-2 max-h-96 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3">
                            <label
                                v-for="question in questions"
                                :key="question.id"
                                class="flex cursor-pointer gap-3 rounded-md border border-transparent p-2 hover:bg-gray-50"
                            >
                                <input
                                    type="checkbox"
                                    :checked="form.question_ids.includes(question.id)"
                                    class="mt-1 rounded border-gray-300 text-indigo-600"
                                    @change="toggleQuestion(question.id)"
                                >
                                <span>
                                    <span class="text-xs font-medium text-gray-700">
                                        {{ question.topic_name }} · {{ question.type }}
                                        <span v-if="question.has_diagram" class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-sky-800">diagram</span>
                                    </span>
                                    <span class="block text-sm font-medium text-gray-900">{{ question.question_text }}</span>
                                </span>
                            </label>
                        </div>
                        <InputError :message="form.errors.question_ids" class="mt-1" />
                    </div>

                    <div v-else-if="isBankMode && form.chapter_id && hasTopicSelection" class="rounded-md border border-dashed border-gray-300 p-4 text-sm text-gray-600">
                        No questions in the bank for this selection yet.
                        Switch to <button type="button" class="font-medium text-violet-700 hover:underline" @click="form.source_mode = 'manual'">Type manually / paste from Cursor</button>.
                    </div>

                    <div v-if="isManualMode" class="space-y-4 rounded-lg border border-violet-200 bg-violet-50/40 p-4">
                        <p class="text-sm text-gray-700">
                            Add sums here for a new chapter before online practice sets exist. Questions are saved to the bank as fill-in-blank so you can reuse them later for MCQ or packaged sets.
                        </p>

                        <ChapterQuestionPlan
                            v-if="useChapterCursorPlan && topics.length"
                            v-model="editableChapterPlan"
                            :topics="topics"
                            :generating="generatingChapterPrompt"
                            question-label="written sums"
                            @generate-prompt="generateChapterPrompt"
                        />

                        <div v-if="form.chapter_id && !useChapterCursorPlan" class="rounded-lg border border-violet-200 bg-white p-3">
                            <p class="text-sm font-medium text-gray-900">Question counts for Cursor</p>
                            <p class="mt-1 text-xs text-gray-600">
                                Set how many sums to generate, select a topic, then refresh the prompt.
                            </p>

                            <div class="mt-3 grid gap-3 sm:grid-cols-5 max-w-3xl">
                                <div>
                                    <InputLabel value="Total sums" />
                                    <input v-model.number="promptSettings.total" type="number" min="1" max="50" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <InputLabel value="Easy" />
                                    <input v-model.number="promptSettings.easy" type="number" min="0" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <InputLabel value="Medium" />
                                    <input v-model.number="promptSettings.medium" type="number" min="0" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <InputLabel value="Hard" />
                                    <input v-model.number="promptSettings.hard" type="number" min="0" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div class="flex items-end">
                                    <SecondaryButton type="button" class="w-full justify-center" :disabled="!canShowSingleTopicPrompt" @click="refreshPrompt">
                                        Refresh prompt
                                    </SecondaryButton>
                                </div>
                            </div>

                            <p v-if="difficultyMismatch" class="mt-2 text-xs text-amber-800">
                                Easy + medium + hard should equal total ({{ difficultySum }} ≠ {{ promptSettings.total }}).
                            </p>

                            <div class="mt-3">
                                <InputLabel value="Focus (optional)" />
                                <input
                                    v-model="promptSettings.focus"
                                    type="text"
                                    placeholder="e.g. unlike fractions, word problems"
                                    class="mt-1 block w-full max-w-3xl rounded-md border-gray-300 text-sm"
                                    @change="refreshPrompt"
                                />
                            </div>
                        </div>

                        <div v-if="cursorPrompt" class="rounded-lg border border-violet-200 bg-white p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-medium text-gray-900">Cursor prompt</p>
                                <div class="flex flex-wrap items-center gap-2">
                                    <SecondaryButton type="button" @click="copyPrompt">
                                        {{ copiedPrompt ? 'Copied!' : 'Copy prompt' }}
                                    </SecondaryButton>
                                    <button type="button" class="text-xs font-medium text-indigo-600 hover:underline" @click="selectPrompt">
                                        Select all
                                    </button>
                                </div>
                            </div>
                            <p v-if="copyError" class="mt-2 text-sm text-rose-700">{{ copyError }}</p>
                            <textarea
                                ref="promptBox"
                                readonly
                                rows="14"
                                class="mt-2 block w-full max-h-64 resize-y rounded-md border-violet-200 bg-white font-mono text-xs text-gray-800"
                                :value="cursorPrompt"
                                @focus="selectPrompt"
                            />
                            <p class="mt-2 text-xs text-gray-500">Click the prompt box to select all, then Ctrl+C if Copy prompt does not work.</p>
                        </div>

                        <p v-else-if="cursorPromptHint" class="rounded-md border border-dashed border-violet-200 bg-white p-3 text-sm text-gray-600">
                            {{ cursorPromptHint }}
                        </p>

                        <div>
                            <InputLabel value="Paste JSON from Cursor" />
                            <textarea
                                v-model="jsonInput"
                                rows="4"
                                class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs"
                                placeholder='{"questions":[{"question":"...","correct_answer":"...","answer_format":"integer"}]}'
                            />
                            <div class="mt-2 flex gap-2">
                                <SecondaryButton type="button" @click="importJson">Import JSON</SecondaryButton>
                            </div>
                            <p v-if="jsonError" class="mt-2 text-sm text-rose-700">{{ jsonError }}</p>
                            <p v-if="validManualRows.length && useChapterCursorPlan" class="mt-2 text-xs text-emerald-800">
                                {{ validManualRows.length }} question(s) imported. Chapter plan totals updated from JSON.
                            </p>
                            <p class="mt-2 text-xs text-gray-500">
                                For geometry sums with figures, include <strong>needs_diagram</strong> in JSON and upload images via the zip pack above.
                            </p>
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <InputLabel :value="`Manual questions (${validManualRows.length} ready)`" />
                                <button type="button" class="text-xs font-medium text-indigo-600 hover:underline" @click="addManualRow">
                                    + Add sum
                                </button>
                            </div>

                            <div
                                v-for="(row, index) in form.manual_questions"
                                :key="`manual-${index}`"
                                class="rounded-lg border border-gray-200 bg-white p-3"
                            >
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <label class="text-xs font-medium text-gray-700">Question</label>
                                        <textarea v-model="row.question_text" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" :class="{ 'border-rose-400 ring-1 ring-rose-300': manualRowError(index, 'question_text') }" />
                                        <InputError :message="manualRowError(index, 'question_text')" class="mt-1" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-medium text-gray-700">Correct answer</label>
                                        <input v-model="row.correct_answer" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" :class="{ 'border-rose-400 ring-1 ring-rose-300': manualRowError(index, 'correct_answer') }" />
                                        <InputError :message="manualRowError(index, 'correct_answer')" class="mt-1" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-medium text-gray-700">Answer format</label>
                                        <select v-model="row.answer_format" class="mt-1 block w-full rounded-md border-gray-300 text-sm" :class="{ 'border-rose-400 ring-1 ring-rose-300': manualRowError(index, 'answer_format') }">
                                            <option v-for="format in fillBlankFormats" :key="format" :value="format">
                                                {{ format }}
                                            </option>
                                        </select>
                                        <InputError :message="manualRowError(index, 'answer_format')" class="mt-1" />
                                    </div>
                                    <div v-if="supportsDiagrams" class="sm:col-span-2">
                                        <label class="inline-flex items-center gap-2 text-xs font-medium text-gray-700">
                                            <input v-model="row.needs_diagram" type="checkbox" class="rounded border-gray-300 text-indigo-600">
                                            Needs diagram (use zip upload for the image; ignored for algebra chapters)
                                        </label>
                                    </div>
                                    <div v-if="form.sheet_kind === 'test' || isMultipleTopicScope" class="sm:col-span-2">
                                        <label class="text-xs font-medium text-gray-700">Topic name</label>
                                        <input v-model="row.topic_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" :placeholder="selectedTopicName || 'Topic name'" :class="{ 'border-rose-400 ring-1 ring-rose-300': manualRowError(index, 'topic_name') }" />
                                        <InputError :message="manualRowError(index, 'topic_name')" class="mt-1" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="text-xs font-medium text-gray-700">Method hint (optional)</label>
                                        <input v-model="row.method_hint" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                    </div>
                                </div>
                                <button type="button" class="mt-2 text-xs font-medium text-rose-700 hover:underline" @click="removeManualRow(index)">
                                    Remove
                                </button>
                            </div>
                        </div>
                        <InputError :message="form.errors.manual_questions" class="mt-1" />
                    </div>

                    <div v-if="isPdfMode" class="space-y-4 rounded-lg border border-sky-200 bg-sky-50/40 p-4">
                        <p class="text-sm text-gray-700">
                            Upload a separate answer sheet PDF and the system maps numbered answers to Q1, Q2, … You can still edit rows below before saving.
                        </p>

                        <div class="rounded-lg border border-violet-200 bg-white p-4">
                            <InputLabel value="Answer sheet PDF" />
                            <p class="mt-1 text-xs text-gray-600">
                                Use a text-based PDF with lines like <span class="font-mono">1. 42</span>, <span class="font-mono">Q2: 3/4</span>, or
                                <span class="font-mono">Answer key: 1. a 2. b</span>. Upload the worksheet PDF first so the system can warn if counts do not match.
                            </p>

                            <input
                                ref="answerPdfInput"
                                type="file"
                                accept="application/pdf,.pdf"
                                class="mt-4 block w-full max-w-lg text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-violet-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-violet-800"
                                @change="onAnswerPdfSelected"
                            >

                            <p v-if="selectedAnswerPdfName" class="mt-2 text-sm font-medium text-gray-700">
                                Selected: {{ selectedAnswerPdfName }}
                            </p>

                            <PrimaryButton
                                type="button"
                                class="mt-4"
                                :disabled="answerPdfParsing || !selectedAnswerPdfName || !hasWorksheetPdfToken"
                                @click="parseAnswerSheetPdf"
                            >
                                {{ answerPdfParsing ? 'Reading answer sheet…' : 'Upload answer sheet → map answers' }}
                            </PrimaryButton>

                            <p v-if="!hasWorksheetPdfToken" class="mt-2 text-xs text-amber-800">
                                Upload the worksheet PDF first, then upload the answer sheet.
                            </p>

                            <p v-if="answerPdfError" class="mt-2 text-sm text-rose-700">{{ answerPdfError }}</p>
                            <p v-if="answerPdfParsedCount" class="mt-2 text-sm text-emerald-800">
                                {{ answerPdfParsedCount }} answer(s) mapped to question numbers. Review below, then save.
                            </p>
                            <p v-for="(warning, index) in answerPdfWarnings" :key="`answer-warning-${index}`" class="mt-2 text-xs text-amber-800">
                                {{ warning }}
                            </p>
                            <p v-if="worksheetEstimatedQuestions && !answerPdfParsedCount" class="mt-2 text-xs text-gray-600">
                                Worksheet PDF looks like it has about {{ worksheetEstimatedQuestions }} question(s).
                            </p>
                        </div>

                        <div>
                            <InputLabel value="Paste answer JSON (optional)" />
                            <textarea
                                v-model="answerKeyJsonInput"
                                rows="3"
                                class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs"
                                placeholder='{"questions":[{"correct_answer":"42","answer_format":"integer"},{"correct_answer":"3/4","answer_format":"fraction"}]}'
                            />
                            <div class="mt-2 flex gap-2">
                                <SecondaryButton type="button" @click="importAnswerKeyJson">Import answers</SecondaryButton>
                            </div>
                            <p v-if="answerKeyJsonError" class="mt-2 text-sm text-rose-700">{{ answerKeyJsonError }}</p>
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <InputLabel :value="`Answer key (${validAnswerKeyRows.length} ready)`" />
                                <button type="button" class="text-xs font-medium text-indigo-600 hover:underline" @click="addAnswerKeyRow">
                                    + Add answer
                                </button>
                            </div>

                            <div
                                v-for="(row, index) in form.answer_key"
                                :key="`answer-key-${index}`"
                                class="rounded-lg border border-gray-200 bg-white p-3"
                            >
                                <p class="text-xs font-medium text-gray-500">Question {{ index + 1 }}</p>
                                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="text-xs font-medium text-gray-700">Correct answer</label>
                                        <input v-model="row.correct_answer" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" :class="{ 'border-rose-400 ring-1 ring-rose-300': answerKeyRowError(index, 'correct_answer') }" />
                                        <InputError :message="answerKeyRowError(index, 'correct_answer')" class="mt-1" />
                                    </div>
                                    <div>
                                        <label class="text-xs font-medium text-gray-700">Answer format</label>
                                        <select v-model="row.answer_format" class="mt-1 block w-full rounded-md border-gray-300 text-sm" :class="{ 'border-rose-400 ring-1 ring-rose-300': answerKeyRowError(index, 'answer_format') }">
                                            <option v-for="format in fillBlankFormats" :key="format" :value="format">
                                                {{ format }}
                                            </option>
                                        </select>
                                        <InputError :message="answerKeyRowError(index, 'answer_format')" class="mt-1" />
                                    </div>
                                    <div v-if="form.sheet_kind === 'test' || isMultipleTopicScope" class="sm:col-span-2">
                                        <label class="text-xs font-medium text-gray-700">Topic name</label>
                                        <input v-model="row.topic_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" :placeholder="selectedTopicName || 'Topic name'" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="text-xs font-medium text-gray-700">Method hint (optional, helps AI grading)</label>
                                        <input v-model="row.method_hint" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                                    </div>
                                </div>
                                <button type="button" class="mt-2 text-xs font-medium text-rose-700 hover:underline" @click="removeAnswerKeyRow(index)">
                                    Remove
                                </button>
                            </div>
                        </div>
                        <InputError :message="form.errors.answer_key" class="mt-1" />

                        <div class="rounded-lg border border-gray-200 bg-white p-4">
                            <p class="text-sm font-medium text-gray-900">Ready to save?</p>
                            <ul class="mt-2 space-y-1 text-sm text-gray-700">
                                <li :class="pdfReadiness.chapter ? 'text-emerald-800' : 'text-amber-800'">
                                    {{ pdfReadiness.chapter ? '✓' : '○' }} Chapter selected
                                </li>
                                <li :class="pdfReadiness.topic ? 'text-emerald-800' : 'text-amber-800'">
                                    {{ pdfReadiness.topic ? '✓' : '○' }} Topic selected
                                </li>
                                <li :class="pdfReadiness.worksheetPdf ? 'text-emerald-800' : 'text-amber-800'">
                                    {{ pdfReadiness.worksheetPdf ? '✓' : '○' }} Worksheet PDF uploaded
                                </li>
                                <li :class="pdfReadiness.answers ? 'text-emerald-800' : 'text-amber-800'">
                                    {{ pdfReadiness.answers ? '✓' : '○' }} Answers mapped ({{ validAnswerKeyRows.length }})
                                </li>
                            </ul>

                            <p v-if="submitBlockedReason" class="mt-3 text-sm text-amber-800">
                                {{ submitBlockedReason }}
                            </p>

                            <PrimaryButton
                                type="submit"
                                class="mt-4"
                                :disabled="form.processing"
                                :class="{ 'opacity-50 cursor-not-allowed': !canSubmit && !form.processing }"
                            >
                                {{ form.processing ? 'Saving…' : submitLabel }}
                            </PrimaryButton>
                        </div>
                    </div>

                    <div>
                        <InputLabel value="Notes (optional)" />
                        <textarea v-model="form.notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <PrimaryButton
                            v-if="!isPdfMode"
                            :disabled="form.processing || !canSubmit"
                        >
                            {{ submitLabel }}
                        </PrimaryButton>
                        <p v-if="!isPdfMode && submitBlockedReason" class="text-sm text-amber-800">
                            {{ submitBlockedReason }}
                        </p>
                        <Link :href="route('admin.written-sheets.index')">
                            <SecondaryButton type="button">Cancel</SecondaryButton>
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
