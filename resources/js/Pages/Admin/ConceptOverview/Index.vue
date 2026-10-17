<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    year: { type: Object, default: null },
    years: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    boards: { type: Array, default: () => [] },
    all_boards: { type: Array, default: () => [] },
    branch_options: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    class_cards: { type: Array, default: () => [] },
    class_card_groups: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    untagged_chapters: { type: Array, default: () => [] },
    orphan_tagged_chapters: { type: Array, default: () => [] },
    can_manage: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({}) },
});

const yearId = ref(props.filters.year || '');
const boardId = ref(props.filters.board || '');
const classNumber = ref(props.filters.class || '');
const branchKey = ref(props.filters.branch || '');
const compare = ref(props.filters.compare !== false);

const applyFilters = () => {
    router.get(route('admin.concept-overview.index'), {
        year: yearId.value || undefined,
        board: boardId.value || undefined,
        class: classNumber.value || undefined,
        branch: branchKey.value || undefined,
        compare: compare.value ? 1 : 0,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const classColumns = computed(() => {
    if (classNumber.value) {
        return (props.classes || []).filter((c) => String(c.number) === String(classNumber.value));
    }
    return props.classes || [];
});

const statusClass = (status) => ({
    // Concept available — dark blue card, white letters
    ready: 'bg-[#0b2a5b] text-white border-[#0b2a5b] shadow-sm',
    draft: 'bg-amber-100 text-amber-950 border-amber-300',
    practice_only: 'bg-sky-100 text-sky-950 border-sky-300',
    to_upload: 'bg-rose-50 text-rose-900 border-rose-200',
    empty: 'bg-slate-50 text-slate-400 border-slate-200',
}[status] || 'bg-slate-50 text-slate-400 border-slate-200');

const shortLabel = (status) => ({
    ready: 'PLAY CONCEPT',
    draft: 'Draft',
    practice_only: 'Practice',
    to_upload: 'Upload',
    empty: '—',
}[status] || '—');

/** Matrix cell on the overview dashboard — ready cells run concepts directly. */
const matrixCellClass = (status) => {
    if (status === 'ready') {
        return 'bg-[#0b2a5b] text-white border-[#0b2a5b] shadow-sm hover:bg-[#0a2350]';
    }
    return statusClass(status);
};

const boardCodes = computed(() => {
    if (!compare.value && boardId.value) {
        const one = props.all_boards.find((b) => String(b.id) === String(boardId.value));
        return one ? [one.code] : (props.boards || []).map((b) => b.code);
    }
    return (props.boards || []).map((b) => b.code);
});

const selectBranch = (key) => {
    branchKey.value = key || '';
    applyFilters();
};

const tallyScopeLabel = computed(() => {
    const parts = [];
    if (classNumber.value) parts.push(`Class ${classNumber.value}`);
    if (boardId.value) {
        const b = props.all_boards.find((x) => String(x.id) === String(boardId.value));
        parts.push(b?.code || 'Board');
    }
    return parts.length ? parts.join(' · ') : 'Class 4–10';
});

const boardEntries = (classCell, code) => {
    if (!classCell) return [];
    if (code) {
        const board = classCell.boards?.[code];
        if (!board) return [];
        if (Array.isArray(board.entries) && board.entries.length) return board.entries;
        return board.present ? [board] : [];
    }
    const boards = classCell.boards || {};
    return Object.values(boards).flatMap((board) => {
        if (!board) return [];
        if (Array.isArray(board.entries) && board.entries.length) return board.entries;
        return board.present ? [board] : [];
    });
};
</script>

<template>
    <Head title="Concept overview" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">USP · Progressive learning map</p>
                    <h2 class="mt-1 text-xl font-semibold text-gray-900">Concept overview</h2>
                    <p class="mt-1 max-w-2xl text-sm text-gray-600">
                        Pick a class and board to see concept cards. Ready cards can be run by anyone signed in.
                        <span v-if="can_manage">Admins can still open create/edit tools from here.</span>
                    </p>
                </div>
                <Link
                    v-if="can_manage"
                    :href="route('admin.chapter-heads.index')"
                    class="text-sm font-medium text-teal-700 hover:text-teal-900"
                >
                    Assign branches →
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-[1400px] space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-end gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Year</label>
                        <select v-model="yearId" class="mt-1 block rounded-lg border-slate-300 text-sm" @change="applyFilters">
                            <option v-for="y in years" :key="y.id" :value="y.id">
                                {{ y.name }}{{ y.is_active ? ' · active' : '' }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Class</label>
                        <select v-model="classNumber" class="mt-1 block rounded-lg border-slate-300 text-sm" @change="applyFilters">
                            <option value="">All classes</option>
                            <option v-for="c in classes" :key="c.id" :value="c.number">Class {{ c.number }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Board</label>
                        <select v-model="boardId" class="mt-1 block rounded-lg border-slate-300 text-sm" @change="applyFilters">
                            <option value="">All boards (compare)</option>
                            <option v-for="b in all_boards" :key="b.id" :value="b.id">{{ b.code }} — {{ b.name }}</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 pb-2 text-sm text-slate-700">
                        <input v-model="compare" type="checkbox" class="rounded border-slate-300 text-teal-700" @change="applyFilters">
                        Side-by-side board cells
                    </label>
                    <div class="ml-auto flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full border border-[#0b2a5b] bg-[#0b2a5b] px-3 py-1 text-white">{{ summary.ready || 0 }} play concept</span>
                        <span class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-amber-950">{{ summary.draft || 0 }} draft</span>
                        <span class="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-rose-900">{{ summary.to_upload || 0 }} to upload</span>
                        <span class="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-sky-950">{{ summary.practice_only || 0 }} practice only</span>
                    </div>
                </div>

                <!-- Chapter vs map tally -->
                <div
                    class="rounded-2xl border px-5 py-4 text-sm"
                    :class="summary.matched
                        ? 'border-teal-300 bg-teal-50 text-teal-950'
                        : 'border-amber-300 bg-amber-50 text-amber-950'"
                >
                    <p class="font-semibold">
                        Chapter tally · {{ tallyScopeLabel }}
                        <span v-if="summary.matched" class="ml-2 font-normal">· matched</span>
                        <span v-else class="ml-2 font-normal">· gaps remain</span>
                    </p>
                    <p class="mt-1">
                        Syllabus chapters: <strong>{{ summary.chapters_total || 0 }}</strong>
                        · tagged with chapter head: <strong>{{ summary.chapters_tagged || 0 }}</strong>
                        · on this map: <strong>{{ summary.chapters_on_map || 0 }}</strong>
                        · missing head: <strong>{{ summary.chapters_untagged || 0 }}</strong>
                        <template v-if="class_cards.length">
                            · concept cards: <strong>{{ class_cards.length }}</strong>
                        </template>
                    </p>
                    <p class="mt-1 text-xs opacity-80">
                        Uses the draft syllabus when both draft and published exist (same as the syllabus editor).
                        Filter Class 7 + CBSE — tally and cards should both show 15.
                    </p>
                </div>

                <div
                    v-if="can_manage && untagged_chapters.length"
                    class="rounded-2xl border border-rose-200 bg-rose-50/80 px-5 py-4 text-sm text-rose-950"
                >
                    <p class="font-semibold">{{ untagged_chapters.length }} chapter{{ untagged_chapters.length === 1 ? '' : 's' }} without a chapter head</p>
                    <p class="mt-1 text-xs">Set the Chapter head dropdown in the syllabus editor — then they appear here under Geometry / Algebra / etc.</p>
                    <ul class="mt-3 max-h-48 space-y-1 overflow-y-auto text-xs">
                        <li v-for="ch in untagged_chapters" :key="ch.id" class="flex flex-wrap gap-x-2">
                            <span class="font-medium">{{ ch.board_code }} {{ ch.grade_name }}</span>
                            <span>Ch {{ ch.chapter_number }} · {{ ch.name }}</span>
                            <a v-if="ch.syllabus_url" :href="ch.syllabus_url" class="underline">Open syllabus</a>
                        </li>
                    </ul>
                </div>

                <div
                    v-if="can_manage && orphan_tagged_chapters.length"
                    class="rounded-2xl border border-amber-200 bg-amber-50/80 px-5 py-4 text-sm text-amber-950"
                >
                    <p class="font-semibold">{{ orphan_tagged_chapters.length }} tagged chapter{{ orphan_tagged_chapters.length === 1 ? '' : 's' }} not landing on the map</p>
                    <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto text-xs">
                        <li v-for="ch in orphan_tagged_chapters" :key="ch.id">
                            {{ ch.board_code }} {{ ch.grade_name }} · {{ ch.chapter_head }} · Ch {{ ch.chapter_number }} {{ ch.name }}
                            <span class="opacity-70">({{ ch.status }})</span>
                        </li>
                    </ul>
                </div>

                <!-- Class concept cards grouped by chapter head -->
                <section
                    v-if="class_card_groups.length"
                    class="space-y-5"
                >
                    <div class="rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                        <h3 class="text-base font-semibold text-slate-900">
                            Concept cards · Class {{ classNumber }}
                            <span v-if="boardId" class="font-normal text-slate-500">
                                · {{ all_boards.find((b) => String(b.id) === String(boardId))?.code }}
                            </span>
                        </h3>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ class_cards.length }} cards in {{ class_card_groups.length }} chapter head{{ class_card_groups.length === 1 ? '' : 's' }}.
                            Ready cards show when a concept path is linked (including sibling draft/published syllabi).
                        </p>
                    </div>

                    <div
                        v-for="group in class_card_groups"
                        :key="group.head_id || group.head_name"
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 pb-3">
                            <div>
                                <h4 class="text-sm font-semibold text-slate-900">{{ group.head_name }}</h4>
                                <p class="text-xs text-slate-500">
                                    <span v-if="group.branch_label">{{ group.branch_label }} · </span>
                                    {{ group.cards.length }} chapter{{ group.cards.length === 1 ? '' : 's' }}
                                </p>
                            </div>
                            <Link
                                v-if="group.head_id"
                                :href="route('admin.concept-overview.show', group.head_id)"
                                class="text-xs font-semibold text-teal-700 hover:underline"
                            >
                                Progressive ladder →
                            </Link>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <article
                                v-for="card in group.cards"
                                :key="card.id"
                                class="rounded-xl border p-4"
                                :class="statusClass(card.status)"
                            >
                                <p class="text-[10px] font-bold uppercase tracking-wide opacity-70">
                                    {{ card.board_code }} · Ch {{ card.chapter_number }}
                                </p>
                                <p class="mt-1 text-sm font-semibold leading-snug">
                                    {{ card.chapter_name }}
                                </p>
                                <p class="mt-2 text-xs font-medium">{{ card.status_label }}</p>
                                <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                                    <a
                                        v-if="card.play_url"
                                        :href="card.play_url"
                                        class="rounded-md bg-white/20 px-2 py-1 underline opacity-90 hover:opacity-100"
                                    >
                                        Run concepts
                                    </a>
                                    <a
                                        v-if="can_manage && card.syllabus_url"
                                        :href="card.syllabus_url"
                                        class="underline opacity-90 hover:opacity-100"
                                    >
                                        Syllabus
                                    </a>
                                    <a
                                        v-if="can_manage && card.concept_url"
                                        :href="card.concept_url"
                                        class="underline opacity-90 hover:opacity-100"
                                    >
                                        Concept path
                                    </a>
                                </div>
                            </article>
                        </div>
                    </div>
                </section>

                <p
                    v-else-if="classNumber"
                    class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                >
                    No syllabus chapters for Class {{ classNumber }}
                    <span v-if="boardId"> with this board</span>
                    in the preferred (draft) syllabus. Check the syllabus editor.
                </p>

                <!-- Branch chips -->
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-full border px-3 py-1.5 text-xs font-semibold transition"
                        :class="!branchKey
                            ? 'border-teal-700 bg-teal-700 text-white'
                            : 'border-slate-200 bg-white text-slate-700 hover:border-teal-400'"
                        @click="selectBranch('')"
                    >
                        All branches
                    </button>
                    <button
                        v-for="opt in branch_options"
                        :key="opt.key"
                        type="button"
                        class="rounded-full border px-3 py-1.5 text-xs font-semibold transition"
                        :class="branchKey === opt.key
                            ? 'border-teal-700 bg-teal-700 text-white'
                            : 'border-slate-200 bg-white text-slate-700 hover:border-teal-400'"
                        @click="selectBranch(opt.key)"
                    >
                        {{ opt.label }}
                    </button>
                </div>

                <p v-if="!branches.length" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    <template v-if="can_manage">
                        No chapter heads in this view yet. Tag syllabus chapters with a
                        <Link :href="route('admin.chapter-heads.index')" class="font-semibold underline">chapter head + branch</Link>,
                        then this map fills in.
                    </template>
                    <template v-else>
                        No concepts are mapped yet. Ask your mentor when the concept map is ready.
                    </template>
                </p>

                <!-- One matrix table per branch -->
                <section
                    v-for="branch in branches"
                    :key="branch.key"
                    class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">{{ branch.label }}</h3>
                            <p class="text-xs text-slate-500">{{ branch.rows.length }} concept{{ branch.rows.length === 1 ? '' : 's' }}</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 bg-white">
                                    <th class="sticky left-0 z-10 bg-white px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        Chapter head
                                    </th>
                                    <th
                                        v-for="cls in classColumns"
                                        :key="cls.id"
                                        class="px-2 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-slate-600"
                                    >
                                        {{ cls.number }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in branch.rows"
                                    :key="row.id"
                                    class="border-b-2 border-slate-300/90 hover:bg-slate-50/60"
                                >
                                    <td class="sticky left-0 z-10 border-r border-slate-200 bg-white px-4 py-3.5">
                                        <Link
                                            :href="row.url"
                                            class="font-semibold text-slate-900 hover:text-teal-800"
                                        >
                                            {{ row.name }}
                                        </Link>
                                        <p class="text-xs text-slate-500">Progressive ladder →</p>
                                    </td>
                                    <td
                                        v-for="cls in classColumns"
                                        :key="`${row.id}-${cls.number}`"
                                        class="px-1.5 py-2.5 align-middle"
                                    >
                                        <div
                                            v-if="row.cells[String(cls.number)]"
                                            class="flex min-w-[6.5rem] flex-col gap-1"
                                        >
                                            <template v-if="compare && boardCodes.length > 1">
                                                <template v-for="code in boardCodes" :key="code">
                                                    <component
                                                        v-for="(entry, idx) in (boardEntries(row.cells[String(cls.number)], code).length
                                                            ? boardEntries(row.cells[String(cls.number)], code)
                                                            : [{ status: 'empty', chapter_name: null, play_url: null }])"
                                                        :key="`${code}-${entry.chapter_id || idx}`"
                                                        :is="entry.play_url && entry.status === 'ready' ? 'a' : 'div'"
                                                        :href="entry.play_url && entry.status === 'ready' ? entry.play_url : undefined"
                                                        class="block rounded-md border px-1.5 py-1.5 text-center text-[10px] font-semibold leading-tight"
                                                        :class="[
                                                            matrixCellClass(entry.status || 'empty'),
                                                            entry.play_url && entry.status === 'ready' ? 'cursor-pointer' : '',
                                                        ]"
                                                    >
                                                        <span class="opacity-80">{{ code }}</span>
                                                        <span class="ml-1 tracking-wide">{{ shortLabel(entry.status || 'empty') }}</span>
                                                        <p
                                                            v-if="entry.chapter_name"
                                                            class="mt-0.5 line-clamp-2 text-[9px] font-normal opacity-80"
                                                        >
                                                            {{ entry.chapter_name }}
                                                        </p>
                                                    </component>
                                                </template>
                                            </template>
                                            <template v-else>
                                                <component
                                                    v-for="(entry, idx) in boardEntries(row.cells[String(cls.number)])"
                                                    :key="entry.chapter_id || idx"
                                                    :is="entry.play_url && entry.status === 'ready' ? 'a' : 'div'"
                                                    :href="entry.play_url && entry.status === 'ready' ? entry.play_url : undefined"
                                                    class="block rounded-md border px-2 py-2 text-center text-[11px] font-semibold tracking-wide"
                                                    :class="[
                                                        matrixCellClass(entry.status),
                                                        entry.play_url && entry.status === 'ready' ? 'cursor-pointer' : '',
                                                    ]"
                                                >
                                                    {{ shortLabel(entry.status) }}
                                                    <p
                                                        v-if="entry.chapter_name"
                                                        class="mt-0.5 line-clamp-2 text-[9px] font-normal tracking-normal opacity-80"
                                                    >
                                                        {{ entry.chapter_name }}
                                                    </p>
                                                </component>
                                                <div
                                                    v-if="!boardEntries(row.cells[String(cls.number)]).length"
                                                    class="rounded-md border px-2 py-2 text-center text-[11px] font-semibold"
                                                    :class="matrixCellClass('empty')"
                                                >
                                                    —
                                                </div>
                                            </template>
                                        </div>
                                        <div
                                            v-else
                                            class="rounded-md border border-slate-200 bg-slate-50 px-2 py-2 text-center text-[11px] text-slate-300"
                                        >
                                            —
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
