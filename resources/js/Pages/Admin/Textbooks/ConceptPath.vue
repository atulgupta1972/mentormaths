<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DiagramCropModal from '@/Components/DiagramCropModal.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    uploaderMode: { type: Boolean, default: false },
    chapter: { type: Object, required: true },
    conceptPath: { type: Object, required: true },
    routes: { type: Object, required: true },
    pdfPages: { type: Array, default: () => [] },
    pdfPagesAvailable: { type: Boolean, default: false },
});

const page = usePage();
const promptBox = ref(null);
const copied = ref(false);
const cards = ref([]);
const rawJson = ref('');
const previewError = ref('');
const previewNote = ref('');
const diagramFileInputs = ref({});
const diagramUploading = ref({});
const cropTarget = ref(null);
const pagePickerCard = ref(null);
const pagesBusy = ref(false);

const statusLabel = computed(() => props.conceptPath?.status_label || 'Not started');
const isApproved = computed(() => props.conceptPath?.status === 'approved');
const figuresSavedOnServer = computed(() => Boolean(props.conceptPath?.status));
const pdfPageCount = computed(() => props.pdfPages?.length || 0);

const saveForm = useForm({
    chapter_title: props.conceptPath?.chapter_title || props.chapter.title,
    payload_json: '',
});

const approveForm = useForm({});
const resetForm = useForm({});

const syncCardsFromProps = (saved) => {
    if (!saved?.length) {
        return;
    }

    cards.value = saved.map((card) => ({
        ...card,
        approved: card.approved !== false,
    }));

    if (props.conceptPath?.chapter_title) {
        saveForm.chapter_title = props.conceptPath.chapter_title;
    }
};

watch(
    () => props.conceptPath?.cards,
    (saved) => {
        syncCardsFromProps(saved);
    },
    { immediate: true, deep: true },
);

const includedCount = computed(() => cards.value.filter((c) => c.approved !== false).length);
const teachCount = computed(() => cards.value.filter((c) => c.type === 'teach' && c.approved !== false).length);
const checkCount = computed(() => cards.value.filter((c) => c.type === 'check' && c.approved !== false).length);

const copyPrompt = async () => {
    const text = props.conceptPath?.prompt || '';
    if (!text) {
        return;
    }

    try {
        await navigator.clipboard.writeText(text);
        copied.value = true;
        window.setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        promptBox.value?.select();
    }
};

const stripFences = (text) => {
    let value = String(text || '').trim();
    if (value.startsWith('```')) {
        value = value.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');
    }
    return value.trim();
};

const normalizeQuestion = (q, fallbackType = 'mcq') => {
    let questionType = String(q?.question_type || q?.type || fallbackType).toLowerCase();
    if (questionType === 'fill_in_blank') {
        questionType = 'fill_blank';
    }
    if (!['mcq', 'fill_blank'].includes(questionType)) {
        questionType = 'mcq';
    }

    const question = String(q?.question || q?.question_text || '').trim();
    const explanation = q?.explanation ? String(q.explanation).trim() : null;

    if (questionType === 'fill_blank') {
        return {
            question_type: 'fill_blank',
            question,
            options: [],
            correct_index: null,
            correct_answer: String(q?.correct_answer ?? '').trim(),
            answer_format: String(q?.answer_format || 'integer').trim() || 'integer',
            explanation,
        };
    }

    const options = Array.isArray(q?.options)
        ? q.options.map((opt) => String(opt ?? '').trim()).filter(Boolean).slice(0, 4)
        : [];
    while (options.length < 4) {
        options.push('—');
    }

    let correctIndex = Number.isFinite(Number(q?.correct_index)) ? Number(q.correct_index) : 0;
    if (correctIndex < 0 || correctIndex > 3) {
        correctIndex = 0;
    }

    return {
        question_type: 'mcq',
        question,
        options,
        correct_index: correctIndex,
        correct_answer: null,
        answer_format: null,
        explanation,
    };
};

const normalizeCard = (row, index) => {
    const type = String(row?.type || '').toLowerCase();
    if (!['teach', 'check'].includes(type)) {
        return null;
    }

    const title = String(row?.title || '').trim();
    if (!title) {
        return null;
    }

    const card = {
        step: Number(row?.step) > 0 ? Number(row.step) : index + 1,
        type,
        title,
        topic: row?.topic ? String(row.topic).trim() : null,
        approved: row?.approved !== false,
        diagram_path: row?.diagram_path || null,
        diagram_url: row?.diagram_url || null,
        figure_page: Number(row?.figure_page) > 0 ? Number(row.figure_page) : null,
    };

    if (type === 'teach') {
        const body = String(row?.body || '').trim();
        if (!body) {
            return null;
        }
        card.body = body;
        card.example = row?.example ? String(row.example).trim() : null;
        card.common_mistake = row?.common_mistake ? String(row.common_mistake).trim() : null;
        return card;
    }

    const questionsIn = Array.isArray(row?.questions) ? row.questions : [];
    const questions = questionsIn
        .map((q) => normalizeQuestion(q))
        .filter((q) => q.question)
        .slice(0, 3);

    if (!questions.length) {
        return null;
    }

    card.questions = questions;
    return card;
};

const runPreview = () => {
    previewError.value = '';
    previewNote.value = '';

    const raw = stripFences(rawJson.value);
    if (!raw) {
        previewError.value = 'Paste the concept-path JSON first.';
        return;
    }

    let decoded;
    try {
        decoded = JSON.parse(raw);
    } catch (e) {
        previewError.value = `JSON is not valid: ${e?.message || 'parse error'}`;
        return;
    }

    const cardsIn = decoded?.cards || decoded?.steps;
    if (!Array.isArray(cardsIn) || !cardsIn.length) {
        previewError.value = 'JSON must include a non-empty "cards" array.';
        return;
    }

    const previous = cards.value;
    const normalized = cardsIn
        .map((row, index) => normalizeCard(row, index))
        .filter(Boolean)
        .map((card, index) => {
            const prev = previous[index];
            if (prev?.diagram_path && prev.title === card.title) {
                card.diagram_path = prev.diagram_path;
                card.diagram_url = prev.diagram_url;
            }
            return card;
        });

    const teach = normalized.filter((c) => c.type === 'teach').length;
    const check = normalized.filter((c) => c.type === 'check').length;

    if (!normalized.length) {
        previewError.value = 'No usable teach/check cards found in JSON.';
        return;
    }
    if (!teach) {
        previewError.value = 'Include at least one teach card.';
        return;
    }
    if (!check) {
        previewError.value = 'Include at least one check card with practice questions.';
        return;
    }

    cards.value = normalized;
    if (decoded?.chapter_title) {
        saveForm.chapter_title = String(decoded.chapter_title);
    }

    const withFigures = normalized.filter((c) => c.figure_page).length;
    if (withFigures > 0) {
        previewNote.value = `${normalized.length} cards ready — pulling ${withFigures} figure page(s) from the chapter PDF…`;
        pullFiguresFromChapterPdf();
    } else {
        previewNote.value = `${normalized.length} cards ready — use Pull figures from chapter PDF, or Pick PDF page on a card.`;
    }
};

const buildPayloadJson = () => JSON.stringify({
    chapter_title: saveForm.chapter_title || props.chapter.title,
    cards: cards.value,
});

const saveDraft = () => {
    if (!cards.value.length) {
        window.alert('Preview JSON first so cards appear here.');
        return;
    }

    saveForm.payload_json = buildPayloadJson();
    saveForm.post(props.routes.save, { preserveScroll: true });
};

const pullFiguresFromChapterPdf = (options = {}) => {
    if (!cards.value.length) {
        window.alert('Preview JSON first so cards appear here.');
        return;
    }
    if (!props.routes.pull_pdf_figures || pagesBusy.value) {
        return;
    }

    pagesBusy.value = true;
    const body = {
        chapter_title: saveForm.chapter_title || props.chapter.title,
        payload_json: buildPayloadJson(),
    };
    if (options.cardIndex !== undefined && options.page) {
        body.card_index = options.cardIndex;
        body.page = options.page;
    }

    router.post(props.routes.pull_pdf_figures, body, {
        preserveScroll: true,
        onFinish: () => {
            pagesBusy.value = false;
        },
    });
};

const approve = () => {
    if (!confirm('Approve this concept flow?\n\nStudents will later learn from these cards in order. You can still reset and rebuild.')) {
        return;
    }

    approveForm.post(props.routes.approve, { preserveScroll: true });
};

const resetPath = () => {
    if (!confirm('Clear the saved concept path for this chapter?')) {
        return;
    }

    resetForm.post(props.routes.reset, { preserveScroll: true });
};

const removeCard = (index) => {
    cards.value = cards.value.filter((_, i) => i !== index);
};

const optionLetter = (index) => String.fromCharCode(65 + index);

const ensureSavedForFigures = () => true;

const postDiagramFile = (cardIndex, file, onFinish) => {
    if (!file || !props.routes.replace_diagram || diagramUploading.value[cardIndex]) {
        return;
    }

    // Manual file upload still needs cards on the server — pull/save first if needed.
    if (!figuresSavedOnServer.value) {
        pullFiguresFromChapterPdf();
        window.setTimeout(() => {
            // After pull redirects back, user can replace; for same-turn upload, save then attach file.
        }, 0);
        onFinish?.();
        window.alert('Pulled/saving from chapter PDF first. When the page reloads, use Edit/crop — or click Upload file again if you still want a custom image.');
        return;
    }

    const formData = new FormData();
    formData.append('card_index', String(cardIndex));
    formData.append('diagram', file);

    diagramUploading.value = { ...diagramUploading.value, [cardIndex]: true };

    router.post(props.routes.replace_diagram, formData, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            diagramUploading.value = { ...diagramUploading.value, [cardIndex]: false };
            onFinish?.();
        },
    });
};

const uploadDiagram = (cardIndex, event) => {
    const file = event.target?.files?.[0];
    postDiagramFile(cardIndex, file, () => {
        if (event.target) {
            event.target.value = '';
        }
    });
};

const openCropEditor = (card, cardIndex) => {
    if (!card?.diagram_url) {
        return;
    }

    cropTarget.value = {
        card_index: cardIndex,
        diagram_url: card.diagram_url,
    };
};

const closeCropEditor = () => {
    if (cropTarget.value && diagramUploading.value[cropTarget.value.card_index]) {
        return;
    }

    cropTarget.value = null;
};

const saveCroppedDiagram = (file) => {
    if (!cropTarget.value) {
        return;
    }

    const cardIndex = cropTarget.value.card_index;
    postDiagramFile(cardIndex, file, () => {
        cropTarget.value = null;
    });
};

const removeDiagram = (cardIndex) => {
    if (!props.routes.remove_diagram || diagramUploading.value[cardIndex]) {
        return;
    }
    if (!figuresSavedOnServer.value) {
        window.alert('Nothing saved on the server yet.');
        return;
    }
    if (!window.confirm('Remove this figure from the concept card?')) {
        return;
    }

    diagramUploading.value = { ...diagramUploading.value, [cardIndex]: true };

    router.post(props.routes.remove_diagram, {
        card_index: cardIndex,
    }, {
        preserveScroll: true,
        onFinish: () => {
            diagramUploading.value = { ...diagramUploading.value, [cardIndex]: false };
        },
    });
};

const loadPdfPages = (force = false) => {
    if (!props.routes.load_pages || pagesBusy.value) {
        return;
    }

    // Ensure cards exist on server so picking a page can attach immediately after.
    if (!figuresSavedOnServer.value && cards.value.length) {
        pullFiguresFromChapterPdf();
        return;
    }

    pagesBusy.value = true;
    router.post(props.routes.load_pages, { force: force ? 1 : 0 }, {
        preserveScroll: true,
        onFinish: () => {
            pagesBusy.value = false;
        },
    });
};

const autoAttachPages = () => {
    pullFiguresFromChapterPdf();
};

const attachPdfPage = (cardIndex, page) => {
    if (diagramUploading.value[cardIndex]) {
        return;
    }

    pullFiguresFromChapterPdf({ cardIndex, page });
};

const pullSuggestedPage = (cardIndex, page) => {
    pullFiguresFromChapterPdf({ cardIndex, page });
};

const togglePagePicker = (index) => {
    if (pagePickerCard.value === index) {
        pagePickerCard.value = null;
        return;
    }
    pagePickerCard.value = index;
    if (!pdfPageCount.value) {
        loadPdfPages(false);
    }
};
</script>

<template>
    <Head :title="`Concept path · Ch ${chapter.chapter_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Concept path</h2>
                    <p class="text-sm text-gray-500">
                        {{ chapter.grade_name }} · {{ chapter.book_name }} ({{ chapter.book_code }})
                        · {{ chapter.label || `Ch ${chapter.chapter_number} — ${chapter.title}` }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Link :href="chapter.show_url" class="text-sm text-indigo-600 hover:underline">
                        ← Chapter
                    </Link>
                    <Link
                        v-if="chapter.play_url && isApproved"
                        :href="chapter.play_url"
                        class="rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-white hover:bg-emerald-800"
                    >
                        Run concepts
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-4 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-950">
                    {{ page.props.flash.error }}
                </div>
                <div v-if="page.props.flash?.warning" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    {{ page.props.flash.warning }}
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700 shadow-sm">
                    <p class="font-semibold text-slate-900">How this works</p>
                    <ol class="mt-2 list-decimal space-y-1 pl-5">
                        <li>
                            <a :href="chapter.download_url" class="font-medium text-indigo-700 hover:underline">Download the chapter PDF</a>
                            and open it in Cursor / Claude / Gemini.
                        </li>
                        <li>Copy the concept-path prompt below and paste it with the PDF.</li>
                        <li>Paste JSON → <strong>Preview cards</strong> — the app pulls figure pages from the chapter PDF automatically when <code>figure_page</code> is set.</li>
                        <li>Then <strong>Edit / crop</strong> each page to keep only the diagram. Approve when ready.</li>
                    </ol>
                    <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Status: {{ statusLabel }}
                        <span v-if="conceptPath.teach_count || conceptPath.check_count" class="ml-2 font-normal normal-case text-slate-600">
                            · {{ conceptPath.teach_count }} teach · {{ conceptPath.check_count }} check
                            · {{ conceptPath.question_count }} mini-questions
                        </span>
                    </p>
                </div>

                <div class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-indigo-950">Cursor / Gemini prompt</p>
                        <SecondaryButton type="button" class="!py-1.5 !text-xs" :disabled="!conceptPath.prompt" @click="copyPrompt">
                            {{ copied ? 'Copied' : 'Copy prompt' }}
                        </SecondaryButton>
                    </div>
                    <textarea
                        ref="promptBox"
                        class="mt-3 h-48 w-full rounded-md border-indigo-200 bg-white font-mono text-xs text-slate-800"
                        readonly
                        :value="conceptPath.prompt"
                    />
                    <p v-if="!conceptPath.prompt" class="mt-2 text-xs text-amber-800">
                        Prompt could not be generated for this chapter. You can still paste JSON below if you already have it.
                    </p>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-sm font-semibold text-slate-900">Paste AI JSON</p>
                    <textarea
                        v-model="rawJson"
                        rows="10"
                        class="mt-2 w-full rounded-md border-slate-300 font-mono text-xs"
                        placeholder='{ "chapter_title": "...", "cards": [ ... ] }'
                    />
                    <InputError class="mt-1" :message="previewError || saveForm.errors.payload_json" />
                    <p v-if="previewNote" class="mt-1 text-xs font-medium text-emerald-800">{{ previewNote }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <PrimaryButton type="button" :disabled="!rawJson.trim()" @click="runPreview">
                            Preview cards
                        </PrimaryButton>
                        <SecondaryButton
                            v-if="conceptPath.status"
                            type="button"
                            class="!border-rose-300 !text-rose-800"
                            :disabled="resetForm.processing"
                            @click="resetPath"
                        >
                            Reset path
                        </SecondaryButton>
                    </div>
                </div>

                <div v-if="cards.length" class="space-y-3">
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">Review concept flow</p>
                            <p class="text-xs text-slate-600">
                                {{ includedCount }} included · {{ teachCount }} teach · {{ checkCount }} check
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <PrimaryButton
                                type="button"
                                class="!bg-violet-700 hover:!bg-violet-800"
                                :disabled="pagesBusy || !cards.length"
                                @click="pullFiguresFromChapterPdf()"
                            >
                                {{ pagesBusy ? 'Pulling PDF pages…' : 'Pull figures from chapter PDF' }}
                            </PrimaryButton>
                            <SecondaryButton
                                type="button"
                                class="!text-xs"
                                :disabled="pagesBusy"
                                @click="loadPdfPages(false)"
                            >
                                {{ pdfPageCount ? `Show pages (${pdfPageCount})` : 'Load PDF page picker' }}
                            </SecondaryButton>
                            <PrimaryButton type="button" :disabled="saveForm.processing || !cards.length" @click="saveDraft">
                                {{ saveForm.processing ? 'Saving…' : 'Save draft' }}
                            </PrimaryButton>
                            <PrimaryButton
                                type="button"
                                class="!bg-emerald-700 hover:!bg-emerald-800"
                                :disabled="approveForm.processing || isApproved || !conceptPath.status"
                                @click="approve"
                            >
                                {{ isApproved ? 'Already approved' : (approveForm.processing ? 'Approving…' : 'Approve concept flow') }}
                            </PrimaryButton>
                        </div>
                    </div>

                    <div
                        v-for="(card, index) in cards"
                        :key="`${card.step}-${card.title}-${index}`"
                        class="rounded-lg border bg-white p-4 shadow-sm"
                        :class="card.approved === false ? 'border-slate-200 opacity-60' : (card.type === 'teach' ? 'border-sky-200' : 'border-amber-200')"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">
                                    Step {{ card.step }} · {{ card.type === 'teach' ? 'Teach' : 'Check' }}
                                    <span v-if="card.topic" class="font-medium normal-case text-slate-600"> · {{ card.topic }}</span>
                                </p>
                                <h3 class="mt-1 text-base font-semibold text-slate-900">{{ card.title }}</h3>
                            </div>
                            <div class="flex items-center gap-3">
                                <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-700">
                                    <input v-model="card.approved" type="checkbox" class="rounded border-slate-300 text-indigo-600">
                                    Include
                                </label>
                                <button type="button" class="text-xs font-semibold text-rose-700 hover:underline" @click="removeCard(index)">
                                    Remove
                                </button>
                            </div>
                        </div>

                        <template v-if="card.type === 'teach'">
                            <p class="mt-3 whitespace-pre-wrap text-sm text-slate-800">{{ card.body }}</p>
                            <p v-if="card.example" class="mt-2 rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-950">
                                <span class="font-semibold">Example:</span> {{ card.example }}
                            </p>
                            <p v-if="card.common_mistake" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950">
                                <span class="font-semibold">Common mistake:</span> {{ card.common_mistake }}
                            </p>
                        </template>

                        <template v-else>
                            <div
                                v-for="(q, qIndex) in (card.questions || [])"
                                :key="qIndex"
                                class="mt-3 rounded-md border border-amber-100 bg-amber-50/50 px-3 py-2"
                            >
                                <p class="text-sm font-medium text-slate-900">
                                    Q{{ qIndex + 1 }}. {{ q.question }}
                                    <span class="ml-1 text-[10px] font-bold uppercase text-amber-800">{{ q.question_type }}</span>
                                </p>
                                <ul v-if="q.question_type === 'mcq'" class="mt-1 space-y-0.5 text-sm text-slate-700">
                                    <li
                                        v-for="(opt, optIndex) in (q.options || [])"
                                        :key="optIndex"
                                        :class="optIndex === q.correct_index ? 'font-semibold text-emerald-800' : ''"
                                    >
                                        {{ optionLetter(optIndex) }}. {{ opt }}
                                        <span v-if="optIndex === q.correct_index" class="text-[10px] uppercase">✓</span>
                                    </li>
                                </ul>
                                <p v-else class="mt-1 text-sm text-emerald-800">
                                    Answer: <strong>{{ q.correct_answer }}</strong>
                                    <span v-if="q.answer_format" class="text-xs text-slate-600">({{ q.answer_format }})</span>
                                </p>
                                <p v-if="q.explanation" class="mt-1 text-xs text-slate-600">{{ q.explanation }}</p>
                            </div>
                        </template>

                        <div
                            class="mt-3 rounded-md border p-3"
                            :class="card.diagram_url ? 'border-slate-200 bg-slate-50' : 'border-violet-200 bg-violet-50/70'"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="text-xs font-medium uppercase tracking-wide text-slate-600">Figure from chapter PDF</p>
                                    <p v-if="card.diagram_url" class="mt-1 text-sm text-slate-600">
                                        Page attached from the chapter PDF — crop to keep only the figure.
                                    </p>
                                    <p v-else-if="card.figure_page" class="mt-1 text-sm text-violet-950">
                                        Will use <strong>PDF page {{ card.figure_page }}</strong> from this chapter (already uploaded).
                                    </p>
                                    <p v-else class="mt-1 text-sm text-slate-700">
                                        No page number on this card — pick a PDF page below.
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <PrimaryButton
                                        v-if="!card.diagram_url && card.figure_page"
                                        type="button"
                                        class="!bg-violet-700 hover:!bg-violet-800 !px-3 !py-1.5 !text-xs"
                                        :disabled="pagesBusy || diagramUploading[index]"
                                        @click="pullSuggestedPage(index, card.figure_page)"
                                    >
                                        {{ pagesBusy ? 'Pulling…' : `Use PDF page ${card.figure_page}` }}
                                    </PrimaryButton>
                                    <PrimaryButton
                                        v-else-if="!card.diagram_url"
                                        type="button"
                                        class="!bg-violet-700 hover:!bg-violet-800 !px-3 !py-1.5 !text-xs"
                                        :disabled="pagesBusy || diagramUploading[index]"
                                        @click="pullFiguresFromChapterPdf()"
                                    >
                                        Pull from chapter PDF
                                    </PrimaryButton>
                                    <SecondaryButton
                                        type="button"
                                        class="!px-3 !py-1.5 !text-xs"
                                        :disabled="pagesBusy || diagramUploading[index]"
                                        @click="togglePagePicker(index)"
                                    >
                                        {{ pagePickerCard === index ? 'Hide pages' : 'Pick PDF page' }}
                                    </SecondaryButton>
                                    <SecondaryButton
                                        v-if="card.diagram_url"
                                        type="button"
                                        class="!px-3 !py-1.5 !text-xs"
                                        :disabled="diagramUploading[index]"
                                        @click="openCropEditor(card, index)"
                                    >
                                        Edit / crop
                                    </SecondaryButton>
                                    <SecondaryButton
                                        v-if="card.diagram_url"
                                        type="button"
                                        class="!px-3 !py-1.5 !text-xs"
                                        :disabled="diagramUploading[index]"
                                        @click="removeDiagram(index)"
                                    >
                                        Remove
                                    </SecondaryButton>
                                    <button
                                        type="button"
                                        class="text-[11px] font-medium text-slate-500 underline hover:text-slate-800"
                                        :disabled="diagramUploading[index]"
                                        @click="diagramFileInputs[index]?.click()"
                                    >
                                        {{ diagramUploading[index] ? 'Uploading…' : 'Or upload your own file' }}
                                    </button>
                                    <input
                                        :ref="(el) => { if (el) diagramFileInputs[index] = el; }"
                                        type="file"
                                        accept="image/*"
                                        class="hidden"
                                        @change="uploadDiagram(index, $event)"
                                    >
                                </div>
                            </div>

                            <div v-if="pagePickerCard === index" class="mt-3">
                                <p v-if="!pdfPageCount" class="text-xs text-slate-600">
                                    {{ pagesBusy ? 'Loading chapter PDF pages…' : 'Loading page thumbnails…' }}
                                </p>
                                <div v-else class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
                                    <button
                                        v-for="page in pdfPages"
                                        :key="page.page"
                                        type="button"
                                        class="overflow-hidden rounded border bg-white text-left hover:border-indigo-400"
                                        :class="card.figure_page === page.page ? 'border-indigo-500 ring-1 ring-indigo-300' : 'border-slate-200'"
                                        :disabled="diagramUploading[index] || pagesBusy"
                                        @click="attachPdfPage(index, page.page)"
                                    >
                                        <img :src="page.url" :alt="`Page ${page.page}`" class="h-24 w-full object-cover object-top">
                                        <span class="block px-1 py-0.5 text-center text-[10px] font-semibold text-slate-600">p.{{ page.page }}</span>
                                    </button>
                                </div>
                            </div>

                            <img
                                v-if="card.diagram_url"
                                :src="card.diagram_url"
                                :alt="`Figure for ${card.title}`"
                                class="mt-3 max-h-56 rounded border border-slate-200 bg-white object-contain"
                            >
                        </div>
                    </div>
                </div>

                <DiagramCropModal
                    :show="Boolean(cropTarget)"
                    :image-url="cropTarget?.diagram_url || ''"
                    :processing="Boolean(cropTarget && diagramUploading[cropTarget.card_index])"
                    @close="closeCropEditor"
                    @cropped="saveCroppedDiagram"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
