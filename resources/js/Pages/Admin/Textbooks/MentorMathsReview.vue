<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    chapters: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    book_name: { type: String, default: '' },
});

const page = usePage();

const drafts = ref({});
props.chapters.forEach((chapter) => {
    drafts.value[chapter.id] = {};
    chapter.sums.forEach((sum) => {
        drafts.value[chapter.id][sum.index] = sum.text;
    });
});

const replaceForm = useForm({
    find: '',
    replace: props.book_name || '',
    grade_level_id: props.filters?.grade_level_id || null,
    textbook_id: props.filters?.textbook_id || null,
});

const acceptForm = useForm({
    grade_level_id: props.filters?.grade_level_id || null,
    textbook_id: props.filters?.textbook_id || null,
});

const saveForms = {};
const savingChapterId = ref(null);

const sumCount = computed(() => props.chapters.reduce((n, chapter) => n + chapter.sums.length, 0));
const readyCount = computed(() => props.chapters.filter((chapter) => chapter.meets_publish_minimum && chapter.is_mentormaths).length);

const changedSums = (chapter) => chapter.sums
    .filter((sum) => (drafts.value[chapter.id]?.[sum.index] ?? '') !== sum.text)
    .map((sum) => ({
        index: sum.index,
        text: drafts.value[chapter.id][sum.index] ?? '',
        field: sum.field,
    }));

const saveChapter = (chapter) => {
    const sums = changedSums(chapter);
    if (!sums.length) {
        return;
    }

    const form = useForm({ sums });
    saveForms[chapter.id] = form;
    savingChapterId.value = chapter.id;
    form.post(route('admin.mentormaths-conversion.sums', chapter.id), {
        preserveScroll: true,
        onFinish: () => {
            savingChapterId.value = null;
        },
    });
};

const replaceAll = () => {
    replaceForm.post(route('admin.mentormaths-conversion.review.replace'), {
        preserveScroll: true,
    });
};

const convertReady = () => {
    if (!window.confirm(`Convert ${readyCount.value} chapter(s) that already have enough fill-blank sums, using the current book name?`)) {
        return;
    }

    acceptForm.post(route('admin.mentormaths-conversion.review.accept'));
};
</script>

<template>
    <Head title="Review conversion sums" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Review sums</h2>
                    <p class="text-sm text-gray-500">
                        {{ sumCount }} sums across {{ chapters.length }} pending chapter{{ chapters.length === 1 ? '' : 's' }}.
                        Read the text. Replace a book or person name only if you see one.
                    </p>
                </div>
                <Link
                    :href="route('admin.mentormaths-conversion.index', {
                        grade_level_id: filters.grade_level_id || undefined,
                        textbook_id: filters.textbook_id || undefined,
                    })"
                    class="text-sm text-indigo-600 hover:underline"
                >
                    ← Back to queue
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6">
                <p v-if="page.props.flash?.success" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </p>
                <p v-if="page.props.flash?.warning" class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {{ page.props.flash.warning }}
                </p>
                <p v-if="page.props.flash?.error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </p>

                <section class="rounded-xl border border-teal-200 bg-teal-50 p-4">
                    <h3 class="text-sm font-semibold text-teal-950">Replace a name in every pending sum</h3>
                    <p class="mt-1 text-xs text-teal-900">
                        Use this for the old book name, or a person's name that should not stay. Generic sums can be left as they are.
                        Current book name is filled in as the replacement.
                    </p>
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <label class="text-xs font-semibold text-slate-700">
                            Find
                            <input
                                v-model="replaceForm.find"
                                type="text"
                                class="mt-1 block w-56 rounded-md border-slate-300 text-sm"
                                placeholder="Old book or person name"
                            />
                        </label>
                        <label class="text-xs font-semibold text-slate-700">
                            Replace with
                            <input
                                v-model="replaceForm.replace"
                                type="text"
                                class="mt-1 block w-56 rounded-md border-slate-300 text-sm"
                                placeholder="MentorMaths book name"
                            />
                        </label>
                        <button
                            type="button"
                            class="rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50"
                            :disabled="replaceForm.processing || replaceForm.find.trim().length < 2"
                            @click="replaceAll"
                        >
                            {{ replaceForm.processing ? 'Replacing…' : 'Replace in all sums' }}
                        </button>
                    </div>
                    <p v-if="replaceForm.errors.find" class="mt-2 text-xs text-red-700">{{ replaceForm.errors.find }}</p>
                </section>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                    <p class="text-sm text-slate-700">
                        <span class="font-semibold">{{ readyCount }}</span>
                        chapter{{ readyCount === 1 ? '' : 's' }} can convert now
                        (already MentorMaths, with enough fill-blank sums).
                    </p>
                    <button
                        type="button"
                        class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800 disabled:opacity-40"
                        :disabled="acceptForm.processing || readyCount === 0"
                        @click="convertReady"
                    >
                        {{ acceptForm.processing ? 'Converting…' : 'Convert ready chapters' }}
                    </button>
                </div>

                <p v-if="!chapters.length" class="rounded-lg border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500">
                    No pending chapters in this filter.
                </p>

                <section
                    v-for="chapter in chapters"
                    :key="chapter.id"
                    class="rounded-xl border border-slate-200 bg-white shadow-sm"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">
                                {{ chapter.grade_name }} · {{ chapter.book_name }}
                            </h3>
                            <p class="text-xs text-slate-500">{{ chapter.label }} · {{ chapter.sums.length }} sums</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                :class="chapter.meets_publish_minimum && chapter.is_mentormaths
                                    ? 'bg-emerald-100 text-emerald-900'
                                    : 'bg-amber-100 text-amber-950'"
                            >
                                {{ chapter.meets_publish_minimum && chapter.is_mentormaths ? 'Ready to convert' : 'Not ready yet' }}
                            </span>
                            <button
                                type="button"
                                class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-800 hover:bg-slate-50 disabled:opacity-40"
                                :disabled="savingChapterId === chapter.id || changedSums(chapter).length === 0"
                                @click="saveChapter(chapter)"
                            >
                                {{ savingChapterId === chapter.id ? 'Saving…' : 'Save edits' }}
                            </button>
                        </div>
                    </div>
                    <ol class="divide-y divide-slate-100">
                        <li
                            v-for="sum in chapter.sums"
                            :key="`${chapter.id}-${sum.index}`"
                            class="px-4 py-3"
                        >
                            <div class="mb-1 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                <span>Q{{ sum.number }}</span>
                                <span v-if="sum.will_publish" class="text-emerald-700">Will publish</span>
                                <span v-else class="text-slate-400">Not a fill-blank yet</span>
                            </div>
                            <textarea
                                v-model="drafts[chapter.id][sum.index]"
                                rows="3"
                                class="w-full rounded-md border-slate-300 text-sm leading-relaxed"
                            />
                        </li>
                    </ol>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
