<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    boards: { type: Array, default: () => [] },
    selectedBoardId: { type: [Number, null], default: null },
    activeYear: { type: Object, default: null },
    matrix: { type: Object, default: null },
});

const page = usePage();

const selectBoard = (boardId) => {
    router.get(route('admin.formula-bank.index'), { board_id: boardId }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const classHref = (gradeId) =>
    `${route('admin.formula-bank.classes.show', gradeId)}?board_id=${props.selectedBoardId}`;

const cellHref = (cell, gradeId) => {
    if (!cell?.chapter_id) {
        return classHref(gradeId);
    }

    return classHref(gradeId);
};

const totalFormulas = computed(() => {
    if (!props.matrix?.rows?.length) {
        return 0;
    }

    return props.matrix.rows.reduce((sum, row) => {
        return sum + Object.values(row.cells || {}).reduce((inner, cell) => inner + (cell.formulas_count || 0), 0);
    }, 0);
});
</script>

<template>
    <Head title="Formula bank" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Formula bank</h2>
                <p class="text-sm text-gray-500">
                    Formula / concept revision cards · class-wise
                    <span v-if="activeYear"> · {{ activeYear.name }}</span>
                </p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
                >
                    {{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash?.error"
                    class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
                >
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-600">
                        Build formula / concept MCQ sets by class and chapter (like practice sets).
                        Student daily revision (keep going until each card is correct) comes next once the bank is ready.
                    </p>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500">Board</span>
                        <button
                            v-for="board in boards"
                            :key="board.id"
                            type="button"
                            class="rounded-full px-3 py-1 text-sm font-semibold ring-1 ring-inset"
                            :class="board.id === selectedBoardId
                                ? 'bg-indigo-600 text-white ring-indigo-600'
                                : 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50'"
                            @click="selectBoard(board.id)"
                        >
                            {{ board.code }}
                        </button>
                        <span class="ml-auto text-sm text-gray-500">
                            {{ totalFormulas }} formula cards in bank
                        </span>
                    </div>
                </div>

                <div v-if="!matrix" class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
                    Select a board to see the class × chapter matrix.
                </div>

                <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="border-b border-gray-100 px-4 py-3">
                        <h3 class="font-semibold text-gray-900">
                            {{ matrix.board?.code }} · Class × chapter
                        </h3>
                        <p class="text-xs text-gray-500">Numbers = formula / concept cards. Click a class header to open topics and add sets.</p>
                    </div>

                    <div v-if="!matrix.rows?.length" class="p-6 text-sm text-gray-600">
                        No syllabus chapters found for this board in classes 7–10. Import syllabus first.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium text-gray-600">Chapter</th>
                                    <th
                                        v-for="grade in matrix.grades"
                                        :key="grade.id"
                                        class="px-4 py-3 text-center font-medium text-gray-600"
                                    >
                                        <Link :href="classHref(grade.id)" class="text-indigo-700 hover:underline">
                                            {{ grade.name.replace(/^Class\s+/i, '') }}
                                        </Link>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="row in matrix.rows" :key="row.chapter_name">
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ row.chapter_name }}</td>
                                    <td
                                        v-for="grade in matrix.grades"
                                        :key="`${row.chapter_name}-${grade.id}`"
                                        class="px-4 py-3 text-center"
                                    >
                                        <Link
                                            v-if="row.cells[grade.id]?.chapter_id"
                                            :href="cellHref(row.cells[grade.id], grade.id)"
                                            class="inline-flex min-w-[2.5rem] justify-center rounded-md px-2 py-1 text-sm font-semibold"
                                            :class="row.cells[grade.id].formulas_count > 0
                                                ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200'
                                                : 'bg-gray-50 text-gray-400 ring-1 ring-gray-200'"
                                        >
                                            {{ row.cells[grade.id].formulas_count || '—' }}
                                        </Link>
                                        <span v-else class="text-gray-300">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
