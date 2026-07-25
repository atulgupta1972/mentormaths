<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    boards: { type: Array, default: () => [] },
    selectedBoardId: { type: [Number, null], default: null },
    selectedGradeId: { type: [Number, null], default: null },
    activeYear: { type: Object, default: null },
    matrix: { type: Object, default: null },
});

const page = usePage();

const selectedGradeId = ref(
    props.selectedGradeId
    || props.matrix?.grades?.find((g) => g.sort_order === 7)?.id
    || props.matrix?.grades?.[0]?.id
    || null,
);

watch(
    () => props.selectedGradeId,
    (value) => {
        if (value) {
            selectedGradeId.value = value;
        }
    },
);

watch(
    () => props.matrix?.grades,
    (grades) => {
        if (!grades?.length) {
            return;
        }
        if (!grades.some((g) => g.id === selectedGradeId.value)) {
            selectedGradeId.value = grades.find((g) => g.sort_order === 7)?.id || grades[0].id;
        }
    },
);

const syncFilters = (overrides = {}) => {
    router.get(route('admin.formula-bank.index'), {
        board_id: overrides.board_id ?? props.selectedBoardId,
        grade_id: overrides.grade_id ?? selectedGradeId.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const selectBoard = (boardId) => {
    syncFilters({ board_id: boardId });
};

const selectGrade = (gradeId) => {
    selectedGradeId.value = gradeId;
    syncFilters({ grade_id: gradeId });
};

const selectedGrade = computed(() =>
    (props.matrix?.grades || []).find((g) => g.id === selectedGradeId.value) || null,
);

const classRows = computed(() => {
    if (!props.matrix?.rows?.length || !selectedGradeId.value) {
        return [];
    }

    return props.matrix.rows
        .map((row) => {
            const cell = row.cells?.[selectedGradeId.value];

            return {
                chapter_name: row.chapter_name,
                chapter_id: cell?.chapter_id || null,
                formulas_count: cell?.formulas_count || 0,
                sets_count: cell?.sets_count || 0,
            };
        })
        .filter((row) => row.chapter_id)
        .sort((a, b) => a.chapter_name.localeCompare(b.chapter_name, undefined, { sensitivity: 'base' }));
});

const classHref = (gradeId) =>
    `${route('admin.formula-bank.classes.show', gradeId)}?board_id=${props.selectedBoardId}`;

const chapterHref = (row) => {
    if (!row.chapter_id) {
        return classHref(selectedGradeId.value);
    }

    const base = route('admin.formula-bank.chapters.show', row.chapter_id);

    return row.formulas_count > 0 ? `${base}#all-formulas` : base;
};

const classTotal = computed(() =>
    classRows.value.reduce((sum, row) => sum + (row.formulas_count || 0), 0),
);
</script>

<template>
    <Head title="Formula bank" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Formula bank</h2>
                <p class="text-sm text-gray-500">
                    Formula / concept revision · one class at a time
                    <span v-if="activeYear"> · {{ activeYear.name }}</span>
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-4 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900"
                >
                    {{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash?.error"
                    class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900"
                >
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg bg-white px-3 py-3 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Board</span>
                        <button
                            v-for="board in boards"
                            :key="board.id"
                            type="button"
                            class="rounded px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                            :class="board.id === selectedBoardId
                                ? 'bg-indigo-600 text-white ring-indigo-600'
                                : 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50'"
                            @click="selectBoard(board.id)"
                        >
                            {{ board.code }}
                        </button>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Class</span>
                        <button
                            v-for="grade in (matrix?.grades || [])"
                            :key="grade.id"
                            type="button"
                            class="rounded px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                            :class="grade.id === selectedGradeId
                                ? 'bg-amber-700 text-white ring-amber-700'
                                : 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50'"
                            @click="selectGrade(grade.id)"
                        >
                            {{ grade.name.replace(/^Class\s+/i, '') }}
                        </button>
                        <span class="ml-auto text-[11px] text-gray-500">
                            {{ classTotal }} cards
                        </span>
                    </div>
                </div>

                <div v-if="!matrix" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-900">
                    Select a board to see chapters.
                </div>

                <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-1.5">
                        <h3 class="text-sm font-semibold text-gray-900">
                            {{ matrix.board?.code }} · {{ selectedGrade?.name || 'Class' }}
                        </h3>
                        <Link
                            v-if="selectedGradeId"
                            :href="classHref(selectedGradeId)"
                            class="text-[11px] font-medium text-indigo-600 hover:underline"
                        >
                            Topics view
                        </Link>
                    </div>

                    <div v-if="!classRows.length" class="px-3 py-4 text-sm text-gray-600">
                        No syllabus chapters for this class / board.
                    </div>

                    <table v-else class="min-w-full text-xs leading-none">
                        <thead class="bg-gray-50 text-left text-[10px] uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-2 py-1 font-medium">Chapter</th>
                                <th class="w-16 px-2 py-1 text-right font-medium">Cards</th>
                                <th class="w-14 px-2 py-1 text-right font-medium">Sets</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in classRows"
                                :key="row.chapter_id"
                                class="border-t border-gray-100 hover:bg-amber-50/50"
                            >
                                <td class="px-2 py-0.5">
                                    <Link
                                        :href="chapterHref(row)"
                                        class="block truncate font-medium text-gray-900 hover:text-indigo-700"
                                        :title="row.chapter_name"
                                    >
                                        {{ row.chapter_name }}
                                    </Link>
                                </td>
                                <td class="px-2 py-0.5 text-right">
                                    <Link
                                        :href="chapterHref(row)"
                                        class="inline-flex min-w-[1.75rem] justify-center rounded px-1.5 py-0.5 text-[11px] font-semibold"
                                        :class="row.formulas_count > 0
                                            ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200'
                                            : 'text-gray-400'"
                                    >
                                        {{ row.formulas_count || '—' }}
                                    </Link>
                                </td>
                                <td class="px-2 py-0.5 text-right text-[11px] text-gray-500">
                                    {{ row.sets_count || '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
