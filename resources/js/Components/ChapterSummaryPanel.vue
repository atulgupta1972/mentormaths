<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    chapterSummary: {
        type: Object,
        default: () => ({ book_columns: [], chapters: [] }),
    },
});

const expandedChapterIds = ref(new Set());
const assigningWorksheetId = ref(null);

const bookColumns = computed(() => props.chapterSummary?.book_columns ?? []);
const chapters = computed(() => props.chapterSummary?.chapters ?? []);
const hasRows = computed(() => chapters.value.length > 0);

const toggleChapter = (chapterId) => {
    const next = new Set(expandedChapterIds.value);

    if (next.has(chapterId)) {
        next.delete(chapterId);
    } else {
        next.add(chapterId);
    }

    expandedChapterIds.value = next;
};

const isExpanded = (chapterId) => expandedChapterIds.value.has(chapterId);

const countOrDash = (value) => (value > 0 ? value : '—');

const questionSuffix = (item) => {
    const count = item.question_count ?? 0;

    return count > 0 ? ` (${count})` : '';
};

const formatDrillDown = (items) => {
    if (!items?.length) {
        return '';
    }

    return items
        .map((item) => `${item.short_label}${questionSuffix(item)} — ${item.status_label}`)
        .join(', ');
};

const itemHref = (item) => {
    if (!item.can_open || !item.assignment_id) {
        return null;
    }

    if (item.delivery_mode === 'written') {
        return route('student.written-assignments.show', item.assignment_id);
    }

    if (item.latest_attempt_id) {
        return route('student.attempts.result', item.latest_attempt_id);
    }

    return route('student.assignments.show', item.assignment_id);
};

const selfAssign = (item) => {
    if (!item.can_assign || assigningWorksheetId.value) {
        return;
    }

    assigningWorksheetId.value = item.worksheet_id;

    router.post(route('student.worksheets.self-assign', item.worksheet_id), {}, {
        preserveScroll: true,
        onFinish: () => {
            assigningWorksheetId.value = null;
        },
    });
};

const cellContent = (chapter, columnKey) => {
    if (!isExpanded(chapter.id)) {
        return countOrDash(chapter.counts?.[columnKey] ?? 0);
    }

    const items = chapter.items?.[columnKey] ?? [];

    return formatDrillDown(items);
};

const bookCellContent = (chapter, bookId) => {
    const key = String(bookId);

    if (!isExpanded(chapter.id)) {
        return countOrDash(chapter.counts?.books?.[key] ?? 0);
    }

    const items = chapter.items?.books?.[key] ?? [];

    return formatDrillDown(items);
};

const bookItems = (chapter, bookId) => chapter.items?.books?.[String(bookId)] ?? [];

const statusClass = (status) => {
    switch (status) {
    case 'done':
        return 'text-emerald-800 bg-emerald-50';
    case 'checking':
        return 'text-violet-800 bg-violet-50';
    case 'overdue':
        return 'text-rose-800 bg-rose-50';
    case 'in_progress':
        return 'text-amber-800 bg-amber-50';
    default:
        return 'text-slate-700 bg-slate-100';
    }
};

const rowBgClass = (index, expanded = false) => {
    if (expanded) {
        return index % 2 === 0 ? 'bg-sky-50' : 'bg-sky-100/70';
    }

    return index % 2 === 0 ? 'bg-white' : 'bg-slate-50';
};

const gridCell = 'border border-slate-300 px-2 py-1 align-middle';
const gridHead = 'border border-slate-400 bg-slate-200 px-2 py-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-800';
const indexCol = 'min-w-[3.25rem] w-[3.25rem] whitespace-nowrap';

const chapterShortLabel = (chapter) => {
    const raw = String(chapter.chapter_number ?? '').trim();

    if (!raw) {
        return '—';
    }

    if (/^ch\b/i.test(raw)) {
        return raw.replace(/\s+/g, ' ');
    }

    return `Ch ${raw}`;
};
</script>

<template>
    <section
        v-if="hasRows"
        class="rounded-xl border-2 border-slate-300 bg-white p-3 shadow-sm"
    >
        <div class="mb-2">
            <h3 class="text-sm font-bold uppercase tracking-wide text-slate-900">
                Chapter overview — what is available
            </h3>
            <p class="mt-0.5 text-[11px] font-medium text-slate-600">
                Click a chapter row to expand · <span class="font-bold text-slate-800">Assign me</span> to add work ·
                <span class="font-bold text-slate-800">Open / Redo</span> to continue
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full border-collapse text-[11px] leading-tight">
                <thead>
                    <tr>
                        <th :class="[gridHead, indexCol, 'text-center']">#</th>
                        <th :class="[gridHead, 'min-w-[9rem] text-left']">Chapter</th>
                        <th :class="[gridHead, 'min-w-[5rem] text-center']">Practice set</th>
                        <th :class="[gridHead, 'w-12 text-center']">Test</th>
                        <th :class="[gridHead, 'w-14 text-center']">Written</th>
                        <th :class="[gridHead, 'min-w-[5rem] text-center']">Fill in blank</th>
                        <th
                            v-if="bookColumns.length"
                            :colspan="bookColumns.length"
                            :class="[gridHead, 'border-l-2 border-slate-500 text-center']"
                        >
                            Books
                        </th>
                    </tr>
                    <tr v-if="bookColumns.length">
                        <th :colspan="6" :class="[gridHead, 'bg-slate-100']" />
                        <th
                            v-for="book in bookColumns"
                            :key="`book-head-${book.id}`"
                            :class="[gridHead, 'min-w-[5rem] border-l-2 border-slate-400 text-center']"
                        >
                            {{ book.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="(chapter, index) in chapters" :key="chapter.id">
                        <tr
                            :class="[rowBgClass(index), 'cursor-pointer font-semibold hover:brightness-95']"
                            @click="toggleChapter(chapter.id)"
                        >
                            <td :class="[gridCell, indexCol, 'text-center font-bold text-slate-700']">
                                {{ chapterShortLabel(chapter) }}
                            </td>
                            <td :class="[gridCell, 'font-bold text-slate-900']">
                                <span class="mr-1 text-sky-700">{{ isExpanded(chapter.id) ? '▾' : '▸' }}</span>
                                {{ chapter.name }}
                            </td>
                            <td :class="[gridCell, 'text-center font-bold text-slate-800']">
                                <span v-if="!isExpanded(chapter.id)">{{ cellContent(chapter, 'practice') }}</span>
                                <span v-else class="block text-left text-[10px] font-semibold normal-case leading-snug text-slate-700">
                                    {{ cellContent(chapter, 'practice') }}
                                </span>
                            </td>
                            <td :class="[gridCell, 'text-center font-bold text-slate-800']">
                                {{ cellContent(chapter, 'test') }}
                            </td>
                            <td :class="[gridCell, 'text-center font-bold text-slate-800']">
                                {{ cellContent(chapter, 'written') }}
                            </td>
                            <td :class="[gridCell, 'text-center font-bold text-slate-800']">
                                <span v-if="!isExpanded(chapter.id)">{{ cellContent(chapter, 'fill_blank') }}</span>
                                <span v-else class="block text-left text-[10px] font-semibold normal-case leading-snug text-slate-700">
                                    {{ cellContent(chapter, 'fill_blank') }}
                                </span>
                            </td>
                            <td
                                v-for="book in bookColumns"
                                :key="`book-count-${chapter.id}-${book.id}`"
                                :class="[gridCell, 'border-l-2 border-slate-400 text-center font-bold text-slate-800']"
                            >
                                {{ bookCellContent(chapter, book.id) }}
                            </td>
                        </tr>

                        <tr
                            v-if="isExpanded(chapter.id)"
                            :class="rowBgClass(index, true)"
                        >
                            <td :class="gridCell" />
                            <td :colspan="5" :class="[gridCell, 'py-1.5']">
                                <div class="space-y-1.5">
                                    <div v-for="bucket in ['practice', 'test', 'written', 'fill_blank']" :key="`${chapter.id}-${bucket}`">
                                        <template v-if="chapter.items?.[bucket]?.length">
                                            <p class="text-[10px] font-bold uppercase tracking-wide text-slate-700">
                                                {{ bucket.replace('_', ' ') }}
                                            </p>
                                            <div class="mt-0.5 flex flex-wrap gap-1">
                                                <div
                                                    v-for="item in chapter.items[bucket]"
                                                    :key="`${bucket}-${item.worksheet_id}`"
                                                    class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-1.5 py-0.5 shadow-sm"
                                                >
                                                    <span class="font-mono text-[11px] font-bold text-slate-900">
                                                        {{ item.short_label }}<span class="font-semibold text-slate-500">{{ questionSuffix(item) }}</span>
                                                    </span>
                                                    <span
                                                        class="rounded px-1 py-px text-[9px] font-bold uppercase"
                                                        :class="statusClass(item.status)"
                                                    >
                                                        {{ item.status_label }}
                                                    </span>
                                                    <button
                                                        v-if="item.can_assign"
                                                        type="button"
                                                        class="rounded bg-sky-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-sky-800 disabled:opacity-50"
                                                        :disabled="assigningWorksheetId === item.worksheet_id"
                                                        @click.stop="selfAssign(item)"
                                                    >
                                                        {{ item.status === 'done' ? 'Redo' : 'Assign me' }}
                                                    </button>
                                                    <Link
                                                        v-else-if="itemHref(item)"
                                                        :href="itemHref(item)"
                                                        class="rounded bg-emerald-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-emerald-800"
                                                        @click.stop
                                                    >
                                                        Open
                                                    </Link>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </td>
                            <td
                                v-for="book in bookColumns"
                                :key="`book-detail-${chapter.id}-${book.id}`"
                                :class="[gridCell, 'border-l-2 border-slate-400 align-top py-1.5']"
                            >
                                <div v-if="bookItems(chapter, book.id).length" class="space-y-1">
                                    <div
                                        v-for="item in bookItems(chapter, book.id)"
                                        :key="`book-item-${item.worksheet_id}`"
                                        class="rounded border border-slate-300 bg-white px-1.5 py-1 shadow-sm"
                                    >
                                        <div class="font-mono text-[10px] font-bold text-slate-900">
                                            {{ item.set_code }}<span class="font-semibold text-slate-500">{{ questionSuffix(item) }}</span>
                                        </div>
                                        <div
                                            class="mt-0.5 inline-block rounded px-1 py-px text-[9px] font-bold uppercase"
                                            :class="statusClass(item.status)"
                                        >
                                            {{ item.status_label }}
                                        </div>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            <button
                                                v-if="item.can_assign"
                                                type="button"
                                                class="rounded bg-sky-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-sky-800 disabled:opacity-50"
                                                :disabled="assigningWorksheetId === item.worksheet_id"
                                                @click.stop="selfAssign(item)"
                                            >
                                                {{ item.status === 'done' ? 'Redo' : 'Assign me' }}
                                            </button>
                                            <Link
                                                v-else-if="itemHref(item)"
                                                :href="itemHref(item)"
                                                class="rounded bg-emerald-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-emerald-800"
                                                @click.stop
                                            >
                                                Open
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                                <span v-else class="font-bold text-slate-400">—</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </section>
</template>
