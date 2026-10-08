<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    chapters: { type: Array, default: () => [] },
    selectedChapterId: { type: [Number, String, null], default: null },
    selectedGrade: { type: Object, default: null },
    chapter: { type: Object, default: null },
    cursorPrompt: { type: String, default: null },
    seedDraft: { type: String, default: null },
    figureNotes: { type: String, default: null },
    targetCount: { type: [Number, String], default: 8 },
    predictedSetCode: { type: String, default: null },
    pageError: { type: String, default: null },
});

const page = usePage();
const chapterFilter = ref(props.selectedChapterId || '');
const seed = ref(props.seedDraft || '');
const figureNotes = ref(props.figureNotes || '');
const total = ref(Number(props.targetCount) || 8);
const jsonInput = ref('');
const previewError = ref('');
const previewRows = ref([]);
const copied = ref(false);
const promptBox = ref(null);

const promptForm = useForm({
    syllabus_chapter_id: props.selectedChapterId || '',
    seed: props.seedDraft || '',
    figure_notes: props.figureNotes || '',
    total: Number(props.targetCount) || 8,
});

const saveForm = useForm({
    syllabus_chapter_id: props.selectedChapterId || '',
    seed: props.seedDraft || '',
    json: '',
});

watch(chapterFilter, (value) => {
    if (!value) {
        return;
    }
    router.get(route('admin.questions.create-mmp'), { syllabus_chapter_id: value }, {
        preserveState: false,
        replace: true,
    });
});

const stripMarkdownFences = (text) => {
    let json = String(text || '').trim();
    const fenceMatch = json.match(/^```(?:json)?\s*([\s\S]*?)```\s*$/i);
    if (fenceMatch) {
        return fenceMatch[1].trim();
    }
    return json.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/i, '').trim();
};

const parsePreview = () => {
    previewError.value = '';
    previewRows.value = [];
    const raw = stripMarkdownFences(jsonInput.value);
    if (!raw) {
        previewError.value = 'Paste the AI JSON first.';
        return;
    }

    let data;
    try {
        data = JSON.parse(raw);
    } catch {
        previewError.value = 'JSON is invalid.';
        return;
    }

    const items = Array.isArray(data?.questions) ? data.questions : (Array.isArray(data) ? data : null);
    if (!items?.length) {
        previewError.value = 'JSON must include a non-empty questions array.';
        return;
    }

    try {
        previewRows.value = items.map((item, index) => {
            const questionText = String(item.question ?? item.question_text ?? '').trim();
            if (!questionText) {
                throw new Error(`Question ${index + 1} is missing question text.`);
            }
            const typeRaw = String(item.type || 'fill_in_blank').toLowerCase();
            const isMcq = ['mcq', 'multiple_choice'].includes(typeRaw)
                || (Array.isArray(item.options) && item.options.length > 0);

            if (isMcq) {
                const options = (item.options || []).map((o) => String(o).trim()).filter(Boolean);
                if (options.length < 2) {
                    throw new Error(`Question ${index + 1} MCQ needs at least 2 options.`);
                }
                const correctIndex = Number(item.correct_index);
                if (!Number.isInteger(correctIndex) || correctIndex < 0 || correctIndex >= options.length) {
                    throw new Error(`Question ${index + 1} has an invalid correct_index.`);
                }
                return {
                    type: 'mcq',
                    topic: item.topic || item.topic_name || '',
                    question_text: questionText,
                    options,
                    correct_index: correctIndex,
                    correct_answer: options[correctIndex],
                    difficulty: item.difficulty || 'Hard',
                };
            }

            const correctAnswer = String(item.correct_answer ?? item.answer ?? '').trim();
            if (!correctAnswer) {
                throw new Error(`Question ${index + 1} is missing correct_answer.`);
            }
            if (!questionText.includes('____')) {
                throw new Error(`Question ${index + 1} must include ____ or use type mcq.`);
            }
            return {
                type: 'fill_in_blank',
                topic: item.topic || item.topic_name || '',
                question_text: questionText,
                answer_format: item.answer_format || 'integer',
                correct_answer: correctAnswer,
                difficulty: item.difficulty || 'Hard',
            };
        });
    } catch (error) {
        previewError.value = error.message || 'Could not preview JSON.';
        previewRows.value = [];
    }
};

const generatePrompt = () => {
    if (!chapterFilter.value) {
        window.alert('Choose a chapter first.');
        return;
    }
    if (seed.value.trim().length < 20) {
        window.alert('Paste a fuller seed situation (at least a short paragraph of givens and what to ask).');
        return;
    }

    promptForm.syllabus_chapter_id = chapterFilter.value;
    promptForm.seed = seed.value.trim();
    promptForm.figure_notes = figureNotes.value.trim();
    promptForm.total = Math.max(5, Math.min(12, Number(total.value) || 8));
    promptForm.post(route('admin.questions.mmp-prompt'), { preserveScroll: true });
};

const copyPrompt = async () => {
    if (!props.cursorPrompt) {
        return;
    }
    try {
        await navigator.clipboard.writeText(props.cursorPrompt);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 1600);
    } catch {
        promptBox.value?.select();
        document.execCommand('copy');
        copied.value = true;
    }
};

const saveSet = () => {
    if (!chapterFilter.value) {
        window.alert('Choose a chapter first.');
        return;
    }
    if (!previewRows.value.length) {
        parsePreview();
        if (!previewRows.value.length) {
            return;
        }
    }

    saveForm.syllabus_chapter_id = chapterFilter.value;
    saveForm.seed = seed.value.trim();
    saveForm.json = stripMarkdownFences(jsonInput.value);
    saveForm.post(route('admin.questions.store-mmp'));
};

const fillBlankCount = computed(() => previewRows.value.filter((row) => row.type === 'fill_in_blank').length);
const mcqCount = computed(() => previewRows.value.filter((row) => row.type === 'mcq').length);

const focusPrompt = async () => {
    await nextTick();
    promptBox.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
};

watch(
    () => props.cursorPrompt,
    (value) => {
        if (value) {
            focusPrompt();
        }
    },
);
</script>

<template>
    <Head title="Add Mentormaths Perfection" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link
                        v-if="chapter"
                        :href="route('admin.questions.chapters.show', chapter.id)"
                        class="text-sm text-indigo-600 hover:underline"
                    >
                        ← {{ chapter.board_code }} {{ chapter.grade_name }} · Ch {{ chapter.chapter_number }}
                    </Link>
                    <h2 class="mt-1 text-xl font-semibold text-gray-800">Mentormaths Perfection (MMP)</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Seed one rich sum → AI builds 5–12 exhaustive variants → save as
                        <span class="font-mono font-semibold text-rose-800">{{ predictedSetCode || 'MMP…' }}</span>
                    </p>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.error || pageError"
                    class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
                >
                    {{ page.props.flash?.error || pageError }}
                </div>

                <div class="rounded-lg border border-rose-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-semibold text-rose-950">1. Chapter and seed</p>
                    <p class="mt-1 text-xs text-gray-600">
                        Paste your draft situation (parallels, heights, twin triangles, what to find). Fill-in-blank is preferred; MCQ only when a blank is impossible.
                    </p>

                    <div class="mt-4">
                        <InputLabel value="Chapter" />
                        <select v-model="chapterFilter" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">Select chapter</option>
                            <option v-for="ch in chapters" :key="ch.id" :value="ch.id">
                                {{ ch.label || `${ch.chapter_number} — ${ch.name}` }}
                            </option>
                        </select>
                    </div>

                    <div class="mt-4">
                        <InputLabel value="Seed situation" />
                        <textarea
                            v-model="seed"
                            rows="8"
                            class="mt-1 w-full rounded-md border-gray-300 font-mono text-sm"
                            placeholder="l ∥ m ∥ n, AC ∥ DF, AB ∥ EF, AH = GF, angles at A are 30° and 45°… Find ∠BCA, ∠BAC, …"
                        />
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Figure notes (optional)" />
                            <textarea
                                v-model="figureNotes"
                                rows="3"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                placeholder="Three parallel lines, upper △ABC and lower △DEF, equal heights…"
                            />
                        </div>
                        <div>
                            <InputLabel value="Total sums (including seed as Q1)" />
                            <input
                                v-model.number="total"
                                type="number"
                                min="5"
                                max="12"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            >
                            <p class="mt-1 text-xs text-gray-500">Default 8 · allowed 5–12</p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <PrimaryButton
                            type="button"
                            class="!bg-rose-700 hover:!bg-rose-800"
                            :disabled="promptForm.processing"
                            @click="generatePrompt"
                        >
                            {{ promptForm.processing ? 'Building prompt…' : 'Build Cursor / Gemini prompt' }}
                        </PrimaryButton>
                        <InputError class="mt-1" :message="promptForm.errors.seed || promptForm.errors.syllabus_chapter_id" />
                    </div>
                </div>

                <div v-if="cursorPrompt" class="rounded-lg border border-indigo-200 bg-indigo-50/70 p-5 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-indigo-950">2. Copy prompt into Cursor / Gemini</p>
                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="copyPrompt">
                            {{ copied ? 'Copied' : 'Copy prompt' }}
                        </SecondaryButton>
                    </div>
                    <textarea
                        ref="promptBox"
                        class="mt-3 h-56 w-full rounded-md border-indigo-200 bg-white font-mono text-xs text-slate-800"
                        readonly
                        :value="cursorPrompt"
                    />
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-semibold text-slate-900">3. Paste AI JSON and save MMP set</p>
                    <textarea
                        v-model="jsonInput"
                        rows="12"
                        class="mt-3 w-full rounded-md border-slate-300 font-mono text-xs"
                        placeholder='{ "questions": [ ... ] }'
                    />
                    <InputError class="mt-1" :message="previewError || saveForm.errors.json" />
                    <div class="mt-3 flex flex-wrap gap-2">
                        <SecondaryButton type="button" :disabled="!jsonInput.trim()" @click="parsePreview">
                            Preview
                        </SecondaryButton>
                        <PrimaryButton
                            type="button"
                            class="!bg-rose-700 hover:!bg-rose-800"
                            :disabled="saveForm.processing || !jsonInput.trim()"
                            @click="saveSet"
                        >
                            {{ saveForm.processing ? 'Saving…' : `Save as ${predictedSetCode || 'MMP set'}` }}
                        </PrimaryButton>
                    </div>

                    <div v-if="previewRows.length" class="mt-4 space-y-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ previewRows.length }} sums · {{ fillBlankCount }} fill-blank · {{ mcqCount }} MCQ
                        </p>
                        <div
                            v-for="(row, index) in previewRows"
                            :key="index"
                            class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm"
                        >
                            <p class="text-[11px] font-bold uppercase text-slate-500">
                                Q{{ index + 1 }} · {{ row.type === 'mcq' ? 'MCQ' : 'Fill-blank' }}
                                <span v-if="row.topic"> · {{ row.topic }}</span>
                                · {{ row.difficulty }}
                            </p>
                            <p class="mt-1 text-slate-900">{{ row.question_text }}</p>
                            <p class="mt-1 font-mono text-xs text-emerald-800">
                                Answer: {{ row.correct_answer }}
                                <span v-if="row.answer_format"> ({{ row.answer_format }})</span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
