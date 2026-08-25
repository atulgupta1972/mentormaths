<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    coverage: {
        type: Object,
        default: () => ({ book_columns: [], chapters: [], context: {} }),
    },
    coverageFilters: {
        type: Object,
        default: () => ({
            grade_levels: [],
            boards_by_grade: {},
            selected_grade_level_id: null,
            selected_board_id: null,
        }),
    },
    browseOnly: { type: Boolean, default: false },
});

const expandedChapterIds = ref(new Set());
const selectedGradeId = ref(props.coverageFilters.selected_grade_level_id);
const selectedBoardId = ref(props.coverageFilters.selected_board_id);

const bookColumns = computed(() => props.coverage?.book_columns ?? []);
const chapters = computed(() => props.coverage?.chapters ?? []);
const summaryContext = computed(() => props.coverage?.context ?? {});
const showPanel = computed(() => (props.coverageFilters.grade_levels?.length ?? 0) > 0);
const hasRows = computed(() => chapters.value.length > 0);

const boardsForGrade = computed(() =>
    props.coverageFilters.boards_by_grade?.[selectedGradeId.value] ?? [],
);

const totals = computed(() => {
    const sums = {
        practice: 0,
        test: 0,
        written: 0,
        fill_blank: 0,
        formula: 0,
        books: {},
    };

    for (const chapter of chapters.value) {
        sums.practice += chapter.counts?.practice ?? 0;
        sums.test += chapter.counts?.test ?? 0;
        sums.written += chapter.counts?.written ?? 0;
        sums.fill_blank += chapter.counts?.fill_blank ?? 0;
        sums.formula += chapter.counts?.formula ?? 0;

        for (const [bookId, count] of Object.entries(chapter.counts?.books ?? {})) {
            sums.books[bookId] = (sums.books[bookId] ?? 0) + count;
        }
    }

    return sums;
});

const applyFilters = () => {
    router.get(route('admin.questions.coverage'), {
        grade_level_id: selectedGradeId.value,
        board_id: selectedBoardId.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const onGradeChange = () => {
    const boards = boardsForGrade.value;

    if (boards.length && !boards.some((board) => board.id === selectedBoardId.value)) {
        selectedBoardId.value = boards[0].id;
    }

    applyFilters();
};

watch(
    () => props.coverageFilters,
    (filters) => {
        selectedGradeId.value = filters.selected_grade_level_id;
        selectedBoardId.value = filters.selected_board_id;
    },
    { deep: true },
);

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

const TIER_BLOCKS = [
    { tier: 'starter', label: 'Learner', color: 'sky' },
    { tier: 'builder', label: 'Achiever', color: 'amber' },
    { tier: 'champion', label: 'Expert', color: 'emerald' },
];

const TYPE_ROWS = [
    { key: 'practice', label: 'MCQ' },
    { key: 'fill_blank', label: 'Fill in blank' },
    { key: 'written', label: 'Written' },
    { key: 'test', label: 'Test' },
];

const displayTier = (item) => {
    const tier = item?.tier || 'starter';

    return ['starter', 'builder', 'champion'].includes(tier) ? tier : 'starter';
};

const itemsForBlockRow = (chapter, tier, key) =>
    (chapter.items?.[key] || []).filter((item) => displayTier(item) === tier);

const blockItemCount = (chapter, tier) =>
    TYPE_ROWS.reduce((sum, row) => sum + itemsForBlockRow(chapter, tier, row.key).length, 0);

const blockShellClass = (color) => ({
    sky: 'border-sky-300 bg-sky-50/70',
    amber: 'border-amber-300 bg-amber-50/70',
    emerald: 'border-emerald-300 bg-emerald-50/70',
}[color] ?? 'border-slate-300 bg-white');

const blockTitleClass = (color) => ({
    sky: 'bg-sky-600 text-white',
    amber: 'bg-amber-600 text-white',
    emerald: 'bg-emerald-700 text-white',
}[color] ?? 'bg-slate-700 text-white');

const formatDrillDown = (items) => {
    if (!items?.length) {
        return '';
    }

    return items
        .map((item) => `${item.short_label}${questionSuffix(item)} — ${item.status_label}`)
        .join(', ');
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

const bookParts = (chapter, bookId) => {
    const groups = new Map();

    bookItems(chapter, bookId).forEach((item) => {
        const part = Number(item.part || 1);
        if (!groups.has(part)) {
            groups.set(part, []);
        }
        groups.get(part).push(item);
    });

    return [...groups.entries()]
        .sort((left, right) => left[0] - right[0])
        .map(([part, items]) => ({ part, items }));
};

const statusClass = (status) => {
    if (status === 'published') {
        return 'text-emerald-800 bg-emerald-50';
    }

    return 'text-amber-800 bg-amber-50';
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

const chapterHubUrl = (chapterId) => route('admin.questions.chapters.show', chapterId);
</script>

<template>
    <section
        v-if="showPanel"
        class="rounded-xl border-2 border-slate-300 bg-white p-3 shadow-sm"
    >
        <div class="mb-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wide text-slate-900">
                    Chapter × content — what is in the bank
                </h3>
                <p class="mt-0.5 text-[11px] font-medium text-slate-600">
                    Click a chapter row to expand · counts include published and draft sets
                    <span v-if="summaryContext.selected_grade_name">
                        · {{ summaryContext.selected_grade_name }} · {{ summaryContext.selected_board_name }}
                    </span>
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-2">
                <label class="text-[10px] font-bold uppercase tracking-wide text-slate-600">
                    Class
                    <select
                        v-model.number="selectedGradeId"
                        class="mt-0.5 block rounded border border-slate-400 bg-white px-2 py-1 text-[11px] font-semibold text-slate-900"
                        @change="onGradeChange"
                    >
                        <option
                            v-for="grade in coverageFilters.grade_levels"
                            :key="`grade-${grade.id}`"
                            :value="grade.id"
                        >
                            {{ grade.name }}
                        </option>
                    </select>
                </label>
                <label class="text-[10px] font-bold uppercase tracking-wide text-slate-600">
                    Board
                    <select
                        v-model.number="selectedBoardId"
                        class="mt-0.5 block rounded border border-slate-400 bg-white px-2 py-1 text-[11px] font-semibold text-slate-900"
                        @change="applyFilters"
                    >
                        <option
                            v-for="board in boardsForGrade"
                            :key="`board-${board.id}`"
                            :value="board.id"
                        >
                            {{ board.name }}
                        </option>
                    </select>
                </label>
            </div>
        </div>

        <p
            v-if="!hasRows"
            class="mb-2 rounded border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-[11px] font-medium text-slate-600"
        >
            No syllabus chapters found for this class and board.
        </p>

        <div v-if="hasRows" class="overflow-x-auto">
            <table class="min-w-full border-collapse text-[11px] leading-tight">
                <thead>
                    <tr>
                        <th :class="[gridHead, indexCol, 'text-center']">#</th>
                        <th :class="[gridHead, 'min-w-[9rem] text-left']">Chapter</th>
                        <th :class="[gridHead, 'min-w-[5rem] text-center']">Practice set</th>
                        <th :class="[gridHead, 'w-12 text-center']">Test</th>
                        <th :class="[gridHead, 'w-14 text-center']">Written</th>
                        <th :class="[gridHead, 'min-w-[5rem] text-center']">Fill in blank</th>
                        <th :class="[gridHead, 'w-14 text-center']">Formula</th>
                        <th
                            v-if="bookColumns.length"
                            :colspan="bookColumns.length"
                            :class="[gridHead, 'border-l-2 border-slate-500 text-center']"
                        >
                            Books
                        </th>
                    </tr>
                    <tr v-if="bookColumns.length">
                        <th :colspan="7" :class="[gridHead, 'bg-slate-100']" />
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
                            <td :class="[gridCell, 'text-center font-bold text-slate-800']">
                                <span v-if="!isExpanded(chapter.id)">{{ cellContent(chapter, 'formula') }}</span>
                                <span v-else class="block text-left text-[10px] font-semibold normal-case leading-snug text-slate-700">
                                    {{ cellContent(chapter, 'formula') }}
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
                            <td :colspan="6" :class="[gridCell, 'py-1.5']">
                                <div class="space-y-2">
                                    <Link
                                        v-if="!browseOnly"
                                        :href="chapterHubUrl(chapter.id)"
                                        class="inline-block text-[10px] font-bold uppercase tracking-wide text-indigo-700 hover:underline"
                                        @click.stop
                                    >
                                        Open chapter hub →
                                    </Link>
                                    <p v-else class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                                        Browse only — set codes listed below (no test taking)
                                    </p>

                                    <div class="grid gap-2 lg:grid-cols-3">
                                        <div
                                            v-for="block in TIER_BLOCKS"
                                            :key="`${chapter.id}-${block.tier}`"
                                            class="overflow-hidden rounded-lg border"
                                            :class="blockShellClass(block.color)"
                                        >
                                            <div
                                                class="px-2 py-1 text-center text-[10px] font-bold uppercase tracking-wide"
                                                :class="blockTitleClass(block.color)"
                                            >
                                                {{ block.label }} ({{ blockItemCount(chapter, block.tier) }})
                                            </div>
                                            <div class="space-y-1.5 p-2">
                                                <div
                                                    v-for="row in TYPE_ROWS"
                                                    :key="`${block.tier}-${row.key}`"
                                                >
                                                    <p class="text-[9px] font-bold uppercase tracking-wide text-slate-500">{{ row.label }}</p>
                                                    <div
                                                        v-if="itemsForBlockRow(chapter, block.tier, row.key).length"
                                                        class="mt-0.5 flex flex-wrap gap-1"
                                                    >
                                                        <component
                                                            :is="browseOnly ? 'span' : Link"
                                                            v-for="item in itemsForBlockRow(chapter, block.tier, row.key)"
                                                            :key="`${row.key}-${item.worksheet_id}`"
                                                            v-bind="browseOnly ? {} : { href: item.admin_url }"
                                                            class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-1.5 py-0.5 shadow-sm"
                                                            :class="browseOnly ? 'cursor-default' : 'hover:border-indigo-400'"
                                                            @click.stop
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
                                                        </component>
                                                    </div>
                                                    <p v-else class="mt-0.5 text-[10px] text-slate-400">—</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div v-if="chapter.items?.formula?.length" class="rounded-lg border border-violet-300 bg-violet-50/70 p-2">
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-violet-900">Formula</p>
                                        <div class="mt-0.5 flex flex-wrap gap-1">
                                            <component
                                                :is="browseOnly ? 'span' : Link"
                                                v-for="item in chapter.items.formula"
                                                :key="`formula-${item.worksheet_id}`"
                                                v-bind="browseOnly ? {} : { href: item.admin_url }"
                                                class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-1.5 py-0.5 shadow-sm"
                                                :class="browseOnly ? 'cursor-default' : 'hover:border-indigo-400'"
                                                @click.stop
                                            >
                                                <span class="font-mono text-[11px] font-bold text-slate-900">
                                                    {{ item.short_label }}<span class="font-semibold text-slate-500">{{ questionSuffix(item) }}</span>
                                                </span>
                                            </component>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td
                                v-for="book in bookColumns"
                                :key="`book-detail-${chapter.id}-${book.id}`"
                                :class="[gridCell, 'border-l-2 border-slate-400 align-top py-1.5']"
                            >
                                <div v-if="bookParts(chapter, book.id).length" class="space-y-2">
                                    <div
                                        v-for="part in bookParts(chapter, book.id)"
                                        :key="`book-part-${chapter.id}-${book.id}-${part.part}`"
                                        class="space-y-1"
                                    >
                                        <p class="text-[9px] font-bold uppercase tracking-wide text-slate-500">Part {{ part.part }}</p>
                                        <component
                                            :is="browseOnly ? 'span' : Link"
                                            v-for="item in part.items"
                                            :key="`book-item-${item.worksheet_id}`"
                                            v-bind="browseOnly ? {} : { href: item.admin_url }"
                                            class="block rounded border border-slate-300 bg-white px-1.5 py-1 shadow-sm"
                                            :class="browseOnly ? 'cursor-default' : 'hover:border-indigo-400'"
                                            @click.stop
                                        >
                                            <div class="font-mono text-[10px] font-bold text-slate-900">
                                                {{ item.set_code }}<span class="font-semibold text-slate-500">{{ questionSuffix(item) }}</span>
                                            </div>
                                            <div class="text-[9px] font-semibold uppercase text-slate-500">{{ item.kind_label || 'Book' }}</div>
                                            <div
                                                class="mt-0.5 inline-block rounded px-1 py-px text-[9px] font-bold uppercase"
                                                :class="statusClass(item.status)"
                                            >
                                                {{ item.status_label }}
                                            </div>
                                        </component>
                                    </div>
                                </div>
                                <span v-else class="font-bold text-slate-400">—</span>
                            </td>
                        </tr>
                    </template>

                    <tr class="bg-slate-200 font-bold">
                        <td :class="[gridCell, 'text-center']" colspan="2">Total</td>
                        <td :class="[gridCell, 'text-center']">{{ countOrDash(totals.practice) }}</td>
                        <td :class="[gridCell, 'text-center']">{{ countOrDash(totals.test) }}</td>
                        <td :class="[gridCell, 'text-center']">{{ countOrDash(totals.written) }}</td>
                        <td :class="[gridCell, 'text-center']">{{ countOrDash(totals.fill_blank) }}</td>
                        <td :class="[gridCell, 'text-center']">{{ countOrDash(totals.formula) }}</td>
                        <td
                            v-for="book in bookColumns"
                            :key="`book-total-${book.id}`"
                            :class="[gridCell, 'border-l-2 border-slate-400 text-center']"
                        >
                            {{ countOrDash(totals.books[String(book.id)] ?? 0) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
