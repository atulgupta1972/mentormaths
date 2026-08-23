<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import SaveConfirmationModal from '@/Components/SaveConfirmationModal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { questionHubClassUrl } from '@/utils/questionHub';

const props = defineProps({
    chapter: Object,
    gradeLevel: Object,
    boardCode: String,
    activeYear: Object,
    setCards: Array,
    chapterTests: Array,
    writtenSheets: { type: Array, default: () => [] },
    formulaSets: { type: Array, default: () => [] },
    bookContent: { type: Array, default: () => [] },
    contentUploaders: { type: Array, default: () => [] },
    stats: Object,
    board: Object,
    masterProfiles: { type: Array, default: () => [] },
});

const isAdmin = computed(() => usePage().props.auth?.isAdmin ?? false);
const classListUrl = computed(() => questionHubClassUrl(props.gradeLevel?.id, props.board?.id));
const formulaBankHref = computed(() => route('admin.formula-bank.chapters.show', props.chapter.id));
const page = usePage();
const showSaveModal = ref(Boolean(page.props.flash?.save_confirmation));
const saveConfirmation = computed(() => page.props.flash?.save_confirmation ?? null);
const packageTier = ref(props.masterProfiles?.[0]?.value || 'starter');
const bankPackageTier = reactive({});

const bankTierKey = (card) => (card?.fill_in_blank ? 'fill' : 'mcq');

const selectedBankTier = (card) => bankPackageTier[bankTierKey(card)]
    ?? packageTier.value
    ?? card?.tier
    ?? 'starter';

const selectedBankOption = (card) => {
    const tier = selectedBankTier(card);
    return (card?.package_options || []).find((option) => option.value === tier)
        ?? card?.package_options?.[0]
        ?? null;
};

const selectedBankSetCode = (card) => selectedBankOption(card)?.set_code || card?.set_code || '—';

const selectedBankLabel = (card) => selectedBankOption(card)?.label
    || (props.masterProfiles || []).find((profile) => profile.value === selectedBankTier(card))?.label
    || 'Learner';

watch(
    () => page.props.flash?.save_confirmation,
    (confirmation) => {
        if (confirmation) {
            showSaveModal.value = true;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    },
);

onMounted(() => {
    if (saveConfirmation.value) {
        window.scrollTo({ top: 0, behavior: 'auto' });
    }
});

const topicSetCards = computed(() => (props.setCards || []).filter((card) => card.type === 'bank' || card.type === 'set'));
const chapterPracticeBankCards = computed(() => (props.setCards || []).filter((card) => card.type === 'chapter_practice_bank'));
const chapterBankCards = computed(() => (props.setCards || []).filter((card) => card.type === 'chapter_bank'));

const tierColor = (tier, type) => {
    if (type === 'chapter_test') return 'border-sky-300 bg-sky-50 hover:border-sky-500';
    if (tier === 'starter') return 'border-emerald-300 bg-emerald-50 hover:border-emerald-500';
    if (tier === 'builder') return 'border-amber-300 bg-amber-50 hover:border-amber-500';
    if (tier === 'champion') return 'border-purple-300 bg-purple-50 hover:border-purple-500';
    return 'border-gray-200 bg-white hover:border-indigo-400';
};

const cardHref = (card) => {
    if (card.type === 'chapter_test' || card.type === 'set') {
        return route('admin.questions.sets.show', card.id);
    }
    if (card.type === 'chapter_bank') {
        return route('admin.practice-sets.chapters.create', props.chapter.id);
    }
    if (card.type === 'chapter_practice_bank') {
        return route('admin.questions.chapters.show', props.chapter.id);
    }
    return route('admin.questions.topics.show', card.topic_id);
};

const packageChapterPracticeBank = (card) => {
    const tier = selectedBankTier(card);
    const setCode = selectedBankSetCode(card);
    const label = selectedBankLabel(card);

    if (!window.confirm(`Package this bank as ${label} practice set ${setCode}?`)) {
        return;
    }

    router.post(route('admin.practice-sets.chapters.from-practice-bank', props.chapter.id), {
        fill_in_blank: card?.fill_in_blank ?? false,
        master_profile: tier,
    });
};

const packageChapterBank = () => {
    router.post(route('admin.practice-sets.chapters.from-bank', props.chapter.id), {
        master_profile: packageTier.value,
    });
};

const packageAsSet = (card) => {
    router.post(route('admin.practice-sets.from-topic', card.topic_id), {
        tier: packageTier.value || card.tier,
        fill_in_blank: card.fill_in_blank ?? false,
    });
};

const clearChapterPracticeBank = () => {
    if (!window.confirm(`Delete all practice-set questions in this chapter (${chapterPracticeBankCards.value[0]?.questions_count || 0})? This cannot be undone.`)) {
        return;
    }

    router.delete(route('admin.questions.chapters.clear-practice-bank', props.chapter.id));
};

const conversionForms = reactive({});
watch(
    () => props.bookContent,
    (books) => {
        (books || []).forEach((book) => {
            if (!conversionForms[book.textbook_chapter_id]) {
                conversionForms[book.textbook_chapter_id] = {
                    assigned_to_user_id: '',
                    offered_amount_inr: '',
                };
            }
        });
    },
    { immediate: true },
);

const assignConversion = (book) => {
    const form = conversionForms[book.textbook_chapter_id];
    if (!form?.assigned_to_user_id) {
        return;
    }

    router.post(route('admin.content-tasks.assign-fill-blank-conversion'), {
        textbook_chapter_id: book.textbook_chapter_id,
        assigned_to_user_id: form.assigned_to_user_id,
        offered_amount_inr: form.offered_amount_inr || null,
    });
};

const isFillBlankCode = (code) => /^[SBC]F/i.test(code || '');

const clearBank = (card) => {
    if (!window.confirm(`Delete all ${card.questions_count} questions in “${card.topic_name}”? This cannot be undone.`)) {
        return;
    }

    router.delete(route('admin.questions.topics.clear-bank', card.topic_id));
};
</script>

<template>
    <Head :title="`${chapter.name} — Sets`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                <Link
                    v-if="gradeLevel"
                    :href="classListUrl"
                    class="text-sm text-indigo-600"
                >
                    ← {{ gradeLevel.name }}
                </Link>
                <p class="mt-1 text-sm text-gray-500">
                    {{ boardCode }} {{ gradeLevel?.name }} · Ch {{ chapter.chapter_number }} · {{ chapter.name }}
                </p>
                <h2 class="text-xl font-semibold text-gray-800">Practice sets & chapter tests</h2>
                <p class="mt-1 text-xs text-gray-500">Book sets: C5-MM-CH01-M / F1 / W1 · S821 = MCQ practice · SF821 = fill-in-blank · T821 = chapter test · S821-W = written · F711 = formula</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-if="isAdmin"
                    :href="route('admin.questions.set-code')"
                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
                >
                    Look up set code
                </Link>
                <Link
                    v-if="isAdmin"
                    :href="route('admin.questions.create-fill-in-blank', { syllabus_chapter_id: chapter.id })"
                    class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-900 hover:bg-emerald-100"
                >
                    Add fill in the blanks
                </Link>
                <Link
                    v-if="isAdmin"
                    :href="route('admin.questions.create', { syllabus_chapter_id: chapter.id })"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    Add MCQs
                </Link>
                <Link
                    v-if="isAdmin"
                    :href="route('admin.practice-sets.chapters.show', chapter.id)"
                    class="rounded-md border border-sky-300 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-800 hover:bg-sky-100"
                >
                    Chapter tests
                </Link>
                <Link
                    v-if="isAdmin"
                    :href="route('admin.written-sheets.create', { chapter_id: chapter.id })"
                    class="rounded-md border border-violet-300 bg-violet-50 px-4 py-2 text-sm font-medium text-violet-900 hover:bg-violet-100"
                >
                    Written sheet
                </Link>
                <Link
                    v-if="isAdmin"
                    :href="formulaBankHref"
                    class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-950 hover:bg-amber-100"
                >
                    Add formulas
                </Link>
            </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <BrowseModeNotice />
                <div
                    v-if="usePage().props.flash?.success"
                    class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
                >
                    {{ usePage().props.flash.success }}
                </div>
                <div
                    v-if="usePage().props.flash?.error"
                    class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
                >
                    {{ usePage().props.flash.error }}
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-sky-600">{{ stats.chapter_tests_count || 0 }}</p>
                        <p class="text-xs text-gray-500">Chapter tests</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-violet-600">{{ stats.written_sheets_count || 0 }}</p>
                        <p class="text-xs text-gray-500">Written sheets</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-amber-700">{{ stats.formulas_count || 0 }}</p>
                        <p class="text-xs text-gray-500">Formula cards</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ topicSetCards.length + chapterPracticeBankCards.length + chapterBankCards.length }}</p>
                        <p class="text-xs text-gray-500">Topic sets / banks</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.questions_count }}</p>
                        <p class="text-xs text-gray-500">Questions in chapter</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.sets_count }}</p>
                        <p class="text-xs text-gray-500">Packaged sets</p>
                    </div>
                </div>

                <div v-if="isAdmin && masterProfiles.length" class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3">
                    <label class="text-xs font-bold uppercase tracking-wide text-indigo-900">
                        Package as category
                        <select
                            v-model="packageTier"
                            class="mt-1 block w-full max-w-md rounded-md border-indigo-300 text-sm font-semibold text-slate-900"
                        >
                            <option
                                v-for="profile in masterProfiles"
                                :key="profile.value"
                                :value="profile.value"
                            >
                                {{ profile.label }} — {{ profile.easy }}E / {{ profile.medium }}M / {{ profile.hard }}H
                            </option>
                        </select>
                    </label>
                    <p class="mt-1 text-[11px] text-indigo-800">
                        Used when you package practice banks or chapter tests below.
                    </p>
                </div>

                <div v-if="formulaSets?.length" class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-amber-800">Formula / concept sets</h3>
                        <Link
                            v-if="isAdmin"
                            :href="formulaBankHref"
                            class="text-xs font-medium text-amber-800 hover:underline"
                        >
                            Add more formulas
                        </Link>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="set in formulaSets"
                            :key="`formula-${set.id}`"
                            :href="route('admin.formula-bank.sets.show', set.id)"
                            class="rounded-xl border border-amber-300 bg-amber-50 p-5 shadow-sm transition hover:border-amber-500"
                        >
                            <p class="font-mono text-3xl font-bold tracking-wide text-amber-900">{{ set.set_code }}</p>
                            <p class="mt-2 text-sm font-semibold text-gray-900">Formula set {{ set.set_number }}</p>
                            <p v-if="set.topic_name" class="mt-1 text-xs text-gray-700">{{ set.topic_name }}</p>
                            <p class="mt-2 text-sm text-gray-800">{{ set.questions_count }} cards</p>
                        </Link>
                    </div>
                </div>
                <div v-else-if="isAdmin" class="rounded-lg border border-dashed border-amber-300 bg-amber-50/40 p-4 text-sm text-amber-950">
                    No formula / concept sets in this chapter yet.
                    <Link :href="formulaBankHref" class="ml-1 font-medium text-amber-900 underline">Add formulas</Link>
                </div>

                <div v-if="writtenSheets?.length" class="space-y-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-violet-700">Written homework sheets</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="sheet in writtenSheets"
                            :key="`written-${sheet.id}`"
                            :href="route('admin.written-sheets.show', sheet.id)"
                            class="rounded-xl border border-violet-300 bg-violet-50 p-5 shadow-sm transition hover:border-violet-500"
                        >
                            <p class="font-mono text-3xl font-bold tracking-wide text-violet-800">{{ sheet.set_code }}</p>
                            <p class="mt-2 text-sm font-semibold text-gray-900">Written {{ sheet.kind_label.toLowerCase() }}</p>
                            <p v-if="sheet.topic_name" class="mt-1 text-xs text-gray-700">{{ sheet.topic_name }}</p>
                            <p class="mt-2 text-sm text-gray-800">{{ sheet.questions_count }} questions</p>
                            <p class="mt-2 text-xs font-medium text-violet-800">{{ sheet.written_status_label }}</p>
                        </Link>
                    </div>
                </div>

                <div v-if="chapterPracticeBankCards.length" class="space-y-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-emerald-700">Practice set bank</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="card in chapterPracticeBankCards"
                            :key="`cpb-${card.set_code}-${card.fill_in_blank ? 'f' : 'm'}`"
                            class="rounded-xl border border-emerald-300 bg-emerald-50 p-5 shadow-sm transition hover:border-emerald-500"
                        >
                            <div class="block">
                                <p class="text-sm font-semibold text-gray-800">
                                    {{ isFillBlankCode(card.set_code) ? 'Fill-in-blank practice bank' : 'MCQ practice bank' }}
                                </p>
                                <p class="mt-1 text-xs text-gray-600">
                                    {{ card.topics_count }} topic{{ card.topics_count === 1 ? '' : 's' }} · guided practice (one JSON = one set)
                                </p>
                                <p class="mt-2 text-sm text-gray-700">{{ card.questions_count }} questions in bank</p>
                            </div>

                            <div v-if="isAdmin" class="mt-3 space-y-2 border-t border-emerald-200 pt-3">
                                <label class="block text-xs font-semibold text-emerald-950">
                                    Package as
                                    <select
                                        class="mt-1 block w-full rounded-md border-emerald-300 bg-white text-sm font-semibold text-slate-900"
                                        :value="selectedBankTier(card)"
                                        @change="bankPackageTier[bankTierKey(card)] = $event.target.value"
                                    >
                                        <option
                                            v-for="option in (card.package_options?.length ? card.package_options : masterProfiles)"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }} → {{ option.set_code || selectedBankSetCode(card) }}
                                        </option>
                                    </select>
                                </label>
                                <p class="text-[11px] text-emerald-900">
                                    Will save as
                                    <span class="font-mono font-bold">{{ selectedBankSetCode(card) }}</span>
                                    ({{ selectedBankLabel(card) }}). Change if the sums fit Learner / Achiever / Expert better than the prompt.
                                </p>
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <button
                                        type="button"
                                        class="rounded-md bg-indigo-600 px-3 py-1.5 font-semibold text-white hover:bg-indigo-700"
                                        @click="packageChapterPracticeBank(card)"
                                    >
                                        Package as {{ selectedBankLabel(card) }} · {{ selectedBankSetCode(card) }}
                                    </button>
                                    <button
                                        type="button"
                                        class="font-medium text-rose-700 hover:underline"
                                        @click="clearChapterPracticeBank"
                                    >
                                        Delete all
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="chapterBankCards.length" class="space-y-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-sky-700">Chapter question bank</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="card in chapterBankCards"
                            :key="`cb-${card.set_code}`"
                            class="rounded-xl border border-sky-300 bg-sky-50 p-5 shadow-sm transition hover:border-sky-500"
                        >
                            <Link :href="cardHref(card)" class="block hover:opacity-90">
                                <p class="font-mono text-3xl font-bold tracking-wide text-sky-800">{{ card.set_code }}</p>
                                <p class="mt-2 text-sm font-semibold text-gray-800">Chapter test bank</p>
                                <p class="mt-1 text-xs text-gray-600">
                                    {{ card.topics_count }} topic{{ card.topics_count === 1 ? '' : 's' }} · mixed chapter test (unpackaged)
                                </p>
                                <p class="mt-2 text-sm text-gray-700">{{ card.questions_count }} questions in bank</p>
                            </Link>

                            <p v-if="isAdmin" class="mt-3 border-t border-sky-200 pt-3 text-xs text-sky-900">
                                Saved topic-wise in the bank — package as one chapter test.
                                <button
                                    type="button"
                                    class="ml-1 font-medium text-indigo-600 hover:underline"
                                    @click="packageChapterBank"
                                >
                                    Create as {{ card.set_code }}
                                </button>
                            </p>
                        </div>
                    </div>
                </div>

                <div v-if="bookContent?.length && isAdmin" class="space-y-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-700">Book content — MCQ, fill-in-blank, written</h3>
                    <p class="text-sm text-slate-600">Each part is one MCQ set plus matching fill-in-blank and written. Assign conversion to any uploader — they Check as a student, then you publish.</p>
                    <div
                        v-for="book in bookContent"
                        :key="book.textbook_chapter_id"
                        class="rounded-xl border border-slate-300 bg-white p-5 shadow-sm"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-900">{{ book.book_name }} <span class="font-mono text-xs text-slate-500">({{ book.book_code }})</span></p>
                                <p class="mt-1 text-xs text-slate-600">{{ book.fill_blank_ready_count || 0 }} of {{ book.mcq_count || 0 }} MCQs converted to fill-in-blank</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Link
                                    v-if="book.conversion"
                                    :href="book.conversion.task_url"
                                    class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-900 hover:bg-emerald-100"
                                >
                                    {{ book.conversion.status_label }} · {{ book.conversion.assignee_name }}
                                </Link>
                                <Link
                                    v-if="book.can_convert"
                                    :href="book.convert_url"
                                    class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                >
                                    JSON convert (admin)
                                </Link>
                            </div>
                        </div>
                        <form
                            v-if="book.can_assign_conversion && conversionForms[book.textbook_chapter_id]"
                            class="mt-4 flex flex-wrap items-end gap-2 rounded-lg border border-emerald-100 bg-emerald-50/50 p-3"
                            @submit.prevent="assignConversion(book)"
                        >
                            <div>
                                <label class="text-xs font-medium text-slate-600">Uploader</label>
                                <select
                                    v-model="conversionForms[book.textbook_chapter_id].assigned_to_user_id"
                                    required
                                    class="mt-1 rounded-md border-gray-300 text-sm"
                                >
                                    <option value="" disabled>Select</option>
                                    <option v-for="person in contentUploaders" :key="person.id" :value="person.id">{{ person.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-medium text-slate-600">₹ / question (optional)</label>
                                <input
                                    v-model="conversionForms[book.textbook_chapter_id].offered_amount_inr"
                                    type="number"
                                    min="1"
                                    class="mt-1 w-28 rounded-md border-gray-300 text-sm"
                                >
                            </div>
                            <button type="submit" class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-800">
                                Assign fill-in-blank conversion
                            </button>
                        </form>
                        <div v-if="book.parts?.length" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <div
                                v-for="part in book.parts"
                                :key="`${book.textbook_chapter_id}-part-${part.part}`"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm"
                            >
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Part {{ part.part }}</p>
                                <p class="mt-2 font-mono text-slate-900">
                                    {{ part.mcq?.set_code || 'MCQ pending' }}
                                    <span class="text-xs text-slate-500">{{ part.mcq ? `${part.mcq.questions_count} Q` : '' }}</span>
                                </p>
                                <p class="mt-1 font-mono text-emerald-900">
                                    {{ part.fill_blank?.set_code || 'F — convert' }}
                                    <span class="text-xs text-slate-500">{{ part.fill_blank ? `${part.fill_blank.questions_count} Q` : '' }}</span>
                                </p>
                                <p class="mt-1 font-mono text-violet-900">
                                    {{ part.written?.set_code || 'W — convert' }}
                                    <span class="text-xs text-slate-500">{{ part.written ? `${part.written.questions_count} Q` : '' }}</span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="chapterTests?.length" class="space-y-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-sky-700">Chapter tests (mixed)</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="card in chapterTests"
                            :key="`ct-${card.id}`"
                            class="rounded-xl border p-5 shadow-sm transition"
                            :class="tierColor(card.tier, card.type)"
                        >
                            <Link :href="cardHref(card)" class="block hover:opacity-90">
                                <p class="font-mono text-3xl font-bold tracking-wide text-sky-800">{{ card.set_code }}</p>
                                <p class="mt-2 text-sm font-semibold text-gray-800">Chapter test</p>
                                <p class="mt-1 text-xs text-gray-600">All topics in this chapter</p>
                                <p class="mt-2 text-sm text-gray-700">{{ card.questions_count }} questions</p>
                            </Link>
                            <p v-if="card.status === 'draft'" class="mt-2 text-xs text-amber-700">Draft</p>
                        </div>
                    </div>
                </div>

                <div v-if="topicSetCards.length" class="space-y-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Topic practice sets</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="card in topicSetCards"
                        :key="`${card.type}-${card.id || card.topic_id}-${card.set_code}`"
                        class="rounded-xl border p-5 shadow-sm transition"
                        :class="tierColor(card.tier, card.type)"
                    >
                        <Link :href="cardHref(card)" class="block hover:opacity-90">
                            <p class="font-mono text-3xl font-bold tracking-wide text-gray-900">
                                {{ card.set_code }}
                            </p>
                            <p class="mt-2 text-sm font-semibold text-gray-800">
                                <span v-if="card.type === 'bank'">
                                    {{ isFillBlankCode(card.set_code) ? 'Fill-in-blank bank' : 'MCQ bank' }}
                                </span>
                                <span v-else>{{ card.tier_label }} set</span>
                            </p>
                            <p class="mt-1 text-xs text-gray-600">{{ card.topic_name }}</p>
                            <p class="mt-2 text-sm text-gray-700">{{ card.questions_count }} questions</p>
                        </Link>

                        <p v-if="isAdmin && card.type === 'bank'" class="mt-3 border-t border-emerald-200 pt-3 text-xs text-emerald-800">
                            Questions saved — not packaged yet.
                            <button
                                type="button"
                                class="ml-1 font-medium text-indigo-600 hover:underline"
                                @click="packageAsSet(card)"
                            >
                                Package as {{ card.set_code }}
                            </button>
                            <span class="mx-1 text-emerald-600">·</span>
                            <button
                                type="button"
                                class="font-medium text-rose-700 hover:underline"
                                @click="clearBank(card)"
                            >
                                Delete all
                            </button>
                        </p>
                        <p v-else-if="!isAdmin && card.type === 'bank'" class="mt-3 border-t border-emerald-200 pt-3 text-xs text-emerald-800">
                            Topic question bank — counts only; questions stay hidden until assigned.
                        </p>
                        <p v-else-if="card.status === 'draft'" class="mt-2 text-xs text-amber-700">Draft</p>
                    </div>
                    </div>
                </div>

                <div v-if="!topicSetCards.length && !chapterPracticeBankCards.length && !chapterBankCards.length && !chapterTests?.length && !writtenSheets?.length" class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center text-sm text-gray-500">
                    No questions or sets in this chapter yet.
                    <span v-if="isAdmin">
                        <Link :href="route('admin.questions.create-fill-in-blank', { syllabus_chapter_id: chapter.id })" class="text-emerald-700 hover:underline">Add fill in the blanks</Link>
                        or
                        <Link :href="route('admin.questions.create', { syllabus_chapter_id: chapter.id })" class="text-indigo-600 hover:underline">Add MCQs</Link>
                        or
                        <Link :href="route('admin.written-sheets.create', { chapter_id: chapter.id })" class="text-violet-700 hover:underline">Create written sheet</Link>
                    </span>
                </div>
            </div>
        </div>

        <SaveConfirmationModal
            :show="showSaveModal"
            :confirmation="saveConfirmation"
            @close="showSaveModal = false"
        />
    </AuthenticatedLayout>
</template>
