<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import SaveConfirmationModal from '@/Components/SaveConfirmationModal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { questionHubClassUrl } from '@/utils/questionHub';

const props = defineProps({
    chapter: Object,
    gradeLevel: Object,
    boardCode: String,
    activeYear: Object,
    setCards: Array,
    chapterTests: Array,
    stats: Object,
    board: Object,
});

const isAdmin = computed(() => usePage().props.auth?.isAdmin ?? false);
const classListUrl = computed(() => questionHubClassUrl(props.gradeLevel?.id, props.board?.id));
const page = usePage();
const showSaveModal = ref(Boolean(page.props.flash?.save_confirmation));
const saveConfirmation = computed(() => page.props.flash?.save_confirmation ?? null);

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

const packageChapterPracticeBank = () => {
    router.post(route('admin.practice-sets.chapters.from-practice-bank', props.chapter.id));
};

const packageChapterBank = () => {
    router.post(route('admin.practice-sets.chapters.from-bank', props.chapter.id));
};

const clearChapterPracticeBank = () => {
    if (!window.confirm(`Delete all practice-set questions in this chapter (${chapterPracticeBankCards.value[0]?.questions_count || 0})? This cannot be undone.`)) {
        return;
    }

    router.delete(route('admin.questions.chapters.clear-practice-bank', props.chapter.id));
};

const packageAsSet = (card) => {
    router.post(route('admin.practice-sets.from-topic', card.topic_id), { tier: card.tier });
};

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
                <p class="mt-1 text-xs text-gray-500">S821 = MCQ practice · SF821 = fill-in-blank practice · T821 = chapter test</p>
            </div>
            <div class="flex flex-wrap gap-2">
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
                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-sky-600">{{ stats.chapter_tests_count || 0 }}</p>
                        <p class="text-xs text-gray-500">Chapter tests</p>
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

                <div v-if="chapterPracticeBankCards.length" class="space-y-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-emerald-700">Practice set bank</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="card in chapterPracticeBankCards"
                            :key="`cpb-${card.set_code}`"
                            class="rounded-xl border border-emerald-300 bg-emerald-50 p-5 shadow-sm transition hover:border-emerald-500"
                        >
                            <div class="block">
                                <p class="font-mono text-3xl font-bold tracking-wide text-emerald-900">{{ card.set_code }}</p>
                                <p class="mt-2 text-sm font-semibold text-gray-800">Practice set bank</p>
                                <p class="mt-1 text-xs text-gray-600">
                                    {{ card.topics_count }} topic{{ card.topics_count === 1 ? '' : 's' }} · guided practice (one JSON = one set)
                                </p>
                                <p class="mt-2 text-sm text-gray-700">{{ card.questions_count }} questions in bank</p>
                            </div>

                            <p v-if="isAdmin" class="mt-3 border-t border-emerald-200 pt-3 text-xs text-emerald-900">
                                Package all questions as one practice set.
                                <button
                                    type="button"
                                    class="ml-1 font-medium text-indigo-600 hover:underline"
                                    @click="packageChapterPracticeBank"
                                >
                                    Package as {{ card.set_code }}
                                </button>
                                <span class="mx-1 text-emerald-600">·</span>
                                <button
                                    type="button"
                                    class="font-medium text-rose-700 hover:underline"
                                    @click="clearChapterPracticeBank"
                                >
                                    Delete all
                                </button>
                            </p>
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
                            <p class="mt-2 text-sm font-semibold text-gray-800">{{ card.tier_label }} bank</p>
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
                            Topic question bank — tap to browse questions.
                        </p>
                        <p v-else-if="card.status === 'draft'" class="mt-2 text-xs text-amber-700">Draft</p>
                    </div>
                    </div>
                </div>

                <div v-if="!topicSetCards.length && !chapterPracticeBankCards.length && !chapterBankCards.length && !chapterTests?.length" class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center text-sm text-gray-500">
                    No questions or sets in this chapter yet.
                    <span v-if="isAdmin">
                        <Link :href="route('admin.questions.create-fill-in-blank', { syllabus_chapter_id: chapter.id })" class="text-emerald-700 hover:underline">Add fill in the blanks</Link>
                        or
                        <Link :href="route('admin.questions.create', { syllabus_chapter_id: chapter.id })" class="text-indigo-600 hover:underline">Add MCQs</Link>
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
