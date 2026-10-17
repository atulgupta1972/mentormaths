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
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const yearId = ref(props.filters.year || '');
const boardId = ref(props.filters.board || '');
const branchKey = ref(props.filters.branch || '');
const compare = ref(props.filters.compare !== false);

const applyFilters = () => {
    router.get(route('admin.concept-overview.index'), {
        year: yearId.value || undefined,
        board: boardId.value || undefined,
        branch: branchKey.value || undefined,
        compare: compare.value ? 1 : 0,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const classColumns = computed(() => props.classes || []);

const statusClass = (status) => ({
    ready: 'bg-teal-100 text-teal-900 border-teal-300',
    draft: 'bg-amber-100 text-amber-950 border-amber-300',
    practice_only: 'bg-sky-100 text-sky-950 border-sky-300',
    to_upload: 'bg-rose-50 text-rose-900 border-rose-200',
    empty: 'bg-slate-50 text-slate-400 border-slate-200',
}[status] || 'bg-slate-50 text-slate-400 border-slate-200');

const shortLabel = (status) => ({
    ready: 'Ready',
    draft: 'Draft',
    practice_only: 'Practice',
    to_upload: 'Upload',
    empty: '—',
}[status] || '—');

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
                        Grouped by main branch — Algebra, Geometry, Mensuration, Trigonometry, and more.
                        Drill into a chapter head to see Class 6 → 7 → 8 progression and CBSE vs ICSE.
                    </p>
                </div>
                <Link
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
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Board filter</label>
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
                        <span class="rounded-full border border-teal-200 bg-teal-50 px-3 py-1 text-teal-900">{{ summary.ready || 0 }} ready</span>
                        <span class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-amber-950">{{ summary.draft || 0 }} draft</span>
                        <span class="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-rose-900">{{ summary.to_upload || 0 }} to upload</span>
                        <span class="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-sky-950">{{ summary.practice_only || 0 }} practice only</span>
                    </div>
                </div>

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
                    No chapter heads in this view yet. Tag syllabus chapters with a
                    <Link :href="route('admin.chapter-heads.index')" class="font-semibold underline">chapter head + branch</Link>,
                    then this map fills in.
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
                                    class="border-b border-slate-100 hover:bg-slate-50/80"
                                >
                                    <td class="sticky left-0 z-10 bg-white px-4 py-3">
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
                                        class="px-1.5 py-2 align-middle"
                                    >
                                        <div
                                            v-if="row.cells[String(cls.number)]"
                                            class="flex min-w-[5.5rem] flex-col gap-1"
                                        >
                                            <template v-if="compare && boardCodes.length > 1">
                                                <div
                                                    v-for="code in boardCodes"
                                                    :key="code"
                                                    class="rounded-md border px-1.5 py-1 text-center text-[10px] font-semibold leading-tight"
                                                    :class="statusClass(row.cells[String(cls.number)].boards[code]?.status || 'empty')"
                                                >
                                                    <span class="opacity-70">{{ code }}</span>
                                                    <span class="ml-1">{{ shortLabel(row.cells[String(cls.number)].boards[code]?.status || 'empty') }}</span>
                                                </div>
                                            </template>
                                            <div
                                                v-else
                                                class="rounded-md border px-2 py-2 text-center text-[11px] font-semibold"
                                                :class="statusClass(row.cells[String(cls.number)].best_status)"
                                            >
                                                {{ shortLabel(row.cells[String(cls.number)].best_status) }}
                                            </div>
                                        </div>
                                        <div
                                            v-else
                                            class="rounded-md border border-slate-100 bg-slate-50 px-2 py-2 text-center text-[11px] text-slate-300"
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
