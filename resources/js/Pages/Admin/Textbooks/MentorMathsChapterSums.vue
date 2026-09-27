<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    chapter: { type: Object, required: true },
    current_book_name: { type: String, default: '' },
    other_books: { type: Array, default: () => [] },
    suggested_book_name: { type: String, default: '' },
});

const page = usePage();

const replaceForm = useForm({
    book_name: props.suggested_book_name || props.other_books[0]?.name || '',
});

const convertForm = useForm({});

const canConvert = computed(() => props.chapter.is_mentormaths && props.chapter.meets_publish_minimum);

const replaceBook = () => {
    replaceForm.post(route('admin.mentormaths-conversion.replace-book', props.chapter.id), {
        preserveScroll: true,
    });
};

const convertChapter = () => {
    if (!window.confirm(`Convert this chapter using the book name “${props.current_book_name}”?`)) {
        return;
    }

    convertForm.post(route('admin.mentormaths-conversion.convert-chapter', props.chapter.id));
};
</script>

<template>
    <Head :title="chapter.label || 'Chapter sums'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">{{ chapter.label }}</h2>
                    <p class="text-sm text-gray-500">
                        {{ chapter.grade_name }} · {{ chapter.sums.length }} sum{{ chapter.sums.length === 1 ? '' : 's' }}
                    </p>
                </div>
                <Link
                    :href="route('admin.mentormaths-conversion.index')"
                    class="text-sm text-indigo-600 hover:underline"
                >
                    ← Back to queue
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6">
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
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-800">Current book name</p>
                    <p class="mt-1 text-lg font-semibold text-teal-950">{{ current_book_name }}</p>
                    <p class="mt-2 text-sm text-teal-900">
                        Pick another book name. It is replaced with {{ current_book_name }} in the sums below.
                    </p>
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <label class="min-w-[16rem] flex-1 text-xs font-semibold text-slate-700">
                            Other book name
                            <select
                                v-model="replaceForm.book_name"
                                class="mt-1 block w-full rounded-md border-slate-300 text-sm"
                            >
                                <option v-if="!other_books.length" value="">No other book names</option>
                                <option v-for="book in other_books" :key="book.name" :value="book.name">
                                    {{ book.name }}
                                </option>
                            </select>
                        </label>
                        <button
                            type="button"
                            class="rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50"
                            :disabled="replaceForm.processing || !replaceForm.book_name"
                            @click="replaceBook"
                        >
                            {{ replaceForm.processing ? 'Replacing…' : 'Replace in these sums' }}
                        </button>
                    </div>
                    <p v-if="replaceForm.errors.book_name" class="mt-2 text-xs text-red-700">
                        {{ replaceForm.errors.book_name }}
                    </p>
                </section>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                    <p class="text-sm text-slate-700">
                        <template v-if="canConvert">These sums can convert with {{ current_book_name }}.</template>
                        <template v-else-if="!chapter.sums.length">This chapter has no sums yet.</template>
                        <template v-else>
                            {{ chapter.fill_blank_ready_count }} fill-blank sums are ready. Read the text, then convert when it is enough.
                        </template>
                    </p>
                    <button
                        type="button"
                        class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800 disabled:opacity-40"
                        :disabled="convertForm.processing || !canConvert"
                        @click="convertChapter"
                    >
                        {{ convertForm.processing ? 'Converting…' : 'Convert this chapter' }}
                    </button>
                </div>

                <p
                    v-if="!chapter.sums.length"
                    class="rounded-lg border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500"
                >
                    No sums to read.
                    <Link
                        :href="route('admin.mentormaths-conversion.show', chapter.id)"
                        class="font-semibold text-indigo-700 hover:underline"
                    >
                        Import questions
                    </Link>
                    first.
                </p>

                <ol v-else class="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white">
                    <li
                        v-for="sum in chapter.sums"
                        :key="sum.index"
                        class="px-4 py-3"
                    >
                        <div class="mb-1 flex flex-wrap items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                            <span>Q{{ sum.number }}</span>
                            <span v-if="sum.mentions.length" class="text-amber-800">
                                {{ sum.mentions.join(', ') }}
                            </span>
                        </div>
                        <p class="whitespace-pre-wrap text-sm leading-relaxed text-slate-900">{{ sum.text }}</p>
                    </li>
                </ol>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
