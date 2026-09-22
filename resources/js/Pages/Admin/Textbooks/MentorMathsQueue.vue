<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    chapters: { type: Array, default: () => [] },
    done_chapters: { type: Array, default: () => [] },
    books: { type: Array, default: () => [] },
    grades: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    pending_count: { type: Number, default: 0 },
    done_count: { type: Number, default: 0 },
    min_fill_blank_ready: { type: Number, default: 15 },
    next_chapter: { type: Object, default: null },
});

const page = usePage();

const selectedGradeId = computed(() => props.filters?.grade_level_id ?? '');
const selectedBookId = computed(() => props.filters?.textbook_id ?? '');
const nextChapter = computed(() => props.next_chapter || props.chapters?.[0] || null);
const packFileInput = ref(null);
const exportBusy = ref(false);
const importForm = useForm({
    pack: null,
    publish: true,
});

const stepLabel = (step) => ({
    rebrand: '1. Rebrand book',
    import: '2. Import questions',
    transform: `3. Transform (need ${props.min_fill_blank_ready}+)`,
    publish: '4. Publish',
    done: 'Done',
}[step] || step);

const applyFilters = (patch) => {
    router.get(route('admin.mentormaths-conversion.index'), {
        grade_level_id: patch.grade_level_id !== undefined ? patch.grade_level_id : (selectedGradeId.value || undefined),
        textbook_id: patch.textbook_id !== undefined ? patch.textbook_id : (selectedBookId.value || undefined),
    }, {
        preserveState: true,
        replace: true,
    });
};

const onGradeChange = (event) => {
    applyFilters({
        grade_level_id: event.target.value || undefined,
        textbook_id: undefined,
    });
};

const onBookChange = (event) => {
    applyFilters({
        textbook_id: event.target.value || undefined,
    });
};

const exportDonePack = async () => {
    const ids = (props.done_chapters || []).map((row) => row.id);
    if (!ids.length || exportBusy.value) {
        return;
    }

    exportBusy.value = true;
    try {
        const body = new FormData();
        ids.forEach((id) => body.append('ids[]', String(id)));
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (token) {
            body.append('_token', token);
        }

        const response = await fetch(route('admin.mentormaths-conversion.export-pack'), {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('Export failed');
        }

        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        const disposition = response.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^"]+)"?/i);
        anchor.href = url;
        anchor.download = match?.[1] || `mm-conversion-pack-${ids.length}.json`;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);
    } catch (error) {
        window.alert('Could not export the conversion pack. Try again or use the artisan command.');
    } finally {
        exportBusy.value = false;
    }
};

const onPackSelected = (event) => {
    const file = event.target.files?.[0] || null;
    importForm.pack = file;
};

const submitImportPack = () => {
    if (!importForm.pack) {
        return;
    }

    importForm.post(route('admin.mentormaths-conversion.import-pack'), {
        forceFormData: true,
        onFinish: () => {
            importForm.reset('pack');
            if (packFileInput.value) {
                packFileInput.value.value = '';
            }
        },
    });
};
</script>

<template>
    <Head title="MentorMaths conversion queue" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">MentorMaths conversion queue</h2>
                    <p class="text-sm text-gray-500">
                        Convert publisher chapters one by one — rebrand → transform (≥{{ min_fill_blank_ready }} fill-blanks) → publish.
                        Pending work is listed first; finished chapters are shown below.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link :href="route('admin.textbooks.index')" class="text-sm text-indigo-600 hover:underline self-center">
                        All textbook content
                    </Link>
                    <Link :href="route('admin.textbooks.create')">
                        <PrimaryButton>Upload chapter</PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-4 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div
                    v-if="pending_count > 0 && nextChapter"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border-2 border-teal-400 bg-teal-50 px-5 py-4 shadow-sm"
                >
                    <div>
                        <p class="text-sm font-semibold text-teal-950">
                            {{ pending_count }} chapters still pending
                        </p>
                        <p class="mt-1 text-sm text-teal-900">
                            Next up:
                            <span class="font-semibold">{{ nextChapter.book_name }}</span>
                            · {{ nextChapter.label || `Ch ${nextChapter.chapter_number} — ${nextChapter.title}` }}
                            <span class="text-teal-800"> ({{ stepLabel(nextChapter.queue_step) }})</span>
                        </p>
                    </div>
                    <Link
                        :href="route('admin.mentormaths-conversion.show', nextChapter.id)"
                        class="inline-flex items-center rounded-md bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800"
                    >
                        Continue next chapter →
                    </Link>
                </div>

                <div class="flex flex-wrap items-end gap-4 rounded-lg border border-teal-200 bg-teal-50/60 px-4 py-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-teal-800">Pending</p>
                        <p class="text-2xl font-bold text-teal-950">{{ pending_count }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Done</p>
                        <p class="text-2xl font-bold text-emerald-950">{{ done_count }}</p>
                    </div>
                    <div class="min-w-[12rem]">
                        <label for="grade_filter" class="block text-xs font-semibold uppercase tracking-wide text-gray-500">Class</label>
                        <select
                            id="grade_filter"
                            :value="selectedGradeId"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                            @change="onGradeChange"
                        >
                            <option value="">All classes</option>
                            <option v-for="g in grades" :key="g.id" :value="g.id">{{ g.name }}</option>
                        </select>
                    </div>
                    <div class="min-w-[16rem]">
                        <label for="book_filter" class="block text-xs font-semibold uppercase tracking-wide text-gray-500">Book</label>
                        <select
                            id="book_filter"
                            :value="selectedBookId"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                            @change="onBookChange"
                        >
                            <option value="">All conversion books</option>
                            <option v-for="book in books" :key="book.id" :value="book.id">
                                {{ book.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="border-b border-gray-100 bg-amber-50/70 px-4 py-2">
                        <h3 class="text-sm font-semibold text-amber-950">Pending · {{ pending_count }}</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Class / Book</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Chapter</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Next step</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Questions</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-600">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="!chapters.length">
                                <td colspan="5" class="px-4 py-10 text-center text-gray-500">
                                    No pending chapters for this filter.
                                </td>
                            </tr>
                            <tr v-for="row in chapters" :key="row.id">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ row.book_name }}</div>
                                    <div class="text-xs text-gray-500">
                                        {{ row.grade_name }} · {{ row.book_code }}
                                        <span v-if="row.source_ref"> · {{ row.source_ref }}</span>
                                    </div>
                                    <div v-if="row.is_mentormaths" class="mt-1 text-[11px] font-semibold text-teal-800">
                                        MentorMaths line
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ row.label || `Ch ${row.chapter_number} — ${row.title}` }}</div>
                                    <div class="text-xs text-gray-500">{{ row.status_label }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-900 ring-1 ring-amber-200">
                                        {{ stepLabel(row.queue_step) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600">
                                    <div>{{ row.items_count }} extracted</div>
                                    <div
                                        v-if="row.fill_blank_ready_count"
                                        :class="row.meets_publish_minimum ? 'font-semibold text-emerald-700' : 'font-semibold text-amber-800'"
                                    >
                                        {{ row.fill_blank_ready_count }} / {{ row.min_fill_blank_ready || min_fill_blank_ready }} fill-blank ready
                                        <span v-if="!row.meets_publish_minimum">
                                            (need {{ row.remaining_to_minimum }} more)
                                        </span>
                                    </div>
                                    <div v-else class="text-amber-800">
                                        0 / {{ row.min_fill_blank_ready || min_fill_blank_ready }} fill-blank ready
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        :href="route('admin.mentormaths-conversion.show', row.id)"
                                        class="inline-flex rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-teal-800"
                                    >
                                        {{ row.queue_step === 'publish' ? 'Publish' : 'Convert' }}
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-emerald-200">
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-emerald-100 bg-emerald-50 px-4 py-2">
                        <div>
                            <h3 class="text-sm font-semibold text-emerald-950">Done · {{ done_count }}</h3>
                            <p class="text-xs text-emerald-800">Fill-blank sets published — newest first.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                v-if="done_chapters.length"
                                type="button"
                                class="rounded-md border border-emerald-400 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-900 hover:bg-emerald-50 disabled:opacity-60"
                                :disabled="exportBusy"
                                @click="exportDonePack"
                            >
                                {{ exportBusy ? 'Preparing…' : `Download pack (${done_chapters.length})` }}
                            </button>
                        </div>
                    </div>

                    <div class="border-b border-emerald-100 bg-white px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Import pack on this server</p>
                        <p class="mt-1 text-xs text-gray-600">
                            Use on production after copying a pack JSON from local. Matching is by book code + chapter title/number.
                        </p>
                        <form class="mt-2 flex flex-wrap items-end gap-3" @submit.prevent="submitImportPack">
                            <div>
                                <input
                                    ref="packFileInput"
                                    type="file"
                                    accept=".json,application/json"
                                    class="block w-full text-xs text-gray-600"
                                    @change="onPackSelected"
                                />
                            </div>
                            <label class="flex items-center gap-2 text-xs text-gray-700">
                                <input v-model="importForm.publish" type="checkbox" class="rounded border-gray-300 text-emerald-700" />
                                Publish fill-blank worksheets after import
                            </label>
                            <PrimaryButton
                                type="submit"
                                class="!py-1.5 !text-xs"
                                :disabled="!importForm.pack || importForm.processing"
                            >
                                {{ importForm.processing ? 'Importing…' : 'Import pack' }}
                            </PrimaryButton>
                        </form>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Class / Book</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Chapter</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Published</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Sets</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-600">Open</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="!done_chapters.length">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                    No finished chapters for this filter yet.
                                </td>
                            </tr>
                            <tr v-for="row in done_chapters" :key="`done-${row.id}`">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ row.book_name }}</div>
                                    <div class="text-xs text-gray-500">
                                        {{ row.grade_name }} · {{ row.book_code }}
                                        <span v-if="row.source_ref"> · {{ row.source_ref }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ row.label || `Ch ${row.chapter_number} — ${row.title}` }}</div>
                                    <div class="text-xs text-gray-500">{{ row.status_label }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-900 ring-1 ring-emerald-200">
                                        Done
                                    </span>
                                    <div v-if="row.published_at" class="mt-1 text-xs text-gray-500">{{ row.published_at }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600">
                                    <div>{{ row.fill_blank_ready_count }} fill-blank ready</div>
                                    <div v-if="row.fill_blank_set_code" class="font-mono text-[11px] text-gray-500">
                                        {{ row.fill_blank_set_code }}
                                        <span v-if="row.fill_blank_worksheet_count > 1">
                                            +{{ row.fill_blank_worksheet_count - 1 }} more
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        :href="route('admin.mentormaths-conversion.show', row.id)"
                                        class="inline-flex rounded-md border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-900 hover:bg-emerald-50"
                                    >
                                        View
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
