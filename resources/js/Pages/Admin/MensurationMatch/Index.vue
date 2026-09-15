<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    sheet: {
        type: Object,
        default: () => ({ class_numbers: [], class_columns: [], rows: [] }),
    },
    catalog_summary: { type: Object, default: () => ({}) },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const savingSheet = ref(false);
const measureFilter = ref('all');

const sheetRows = ref([]);

watch(
    () => props.sheet,
    (sheet) => {
        sheetRows.value = (sheet?.rows || []).map((row) => ({
            ...row,
            classes: { ...(row.classes || {}) },
        }));
    },
    { immediate: true, deep: true },
);

const classColumns = computed(() => props.sheet?.class_columns || []);
const classNumbers = computed(() =>
    classColumns.value.length
        ? classColumns.value.map((c) => c.class_number)
        : (props.sheet?.class_numbers || []),
);

const filteredSheetRows = computed(() => {
    if (measureFilter.value === 'all') {
        return sheetRows.value;
    }
    return sheetRows.value.filter((row) => row.measure === measureFilter.value);
});

function saveSheet() {
    savingSheet.value = true;
    const items = sheetRows.value.map((row) => ({
        key: row.key,
        classes: classNumbers.value.filter((n) => !!row.classes[String(n)]),
    }));

    router.put(
        route('admin.mensuration-match.sheet'),
        { items },
        {
            preserveScroll: true,
            onFinish: () => {
                savingSheet.value = false;
            },
        },
    );
}

function toggleClass(row, classNumber) {
    const key = String(classNumber);
    row.classes[key] = !row.classes[key];
}

function tryUrl(gradeLevelId, board) {
    return route('admin.mensuration-match.preview', {
        gradeLevel: gradeLevelId,
        board,
    });
}

/** Count ticked formulas for a class in the current (unsaved) sheet. */
function liveCount(classNumber, board) {
    return sheetRows.value.filter(
        (row) => row.board === board && !!row.classes[String(classNumber)],
    ).length;
}

function measureBadge(measure) {
    if (measure === 'perimeter') return 'bg-sky-100 text-sky-800';
    if (measure === 'area') return 'bg-emerald-100 text-emerald-800';
    if (measure === 'volume') return 'bg-violet-100 text-violet-800';
    return 'bg-slate-100 text-slate-700';
}
</script>

<template>
    <Head title="Mensuration Match — Formula sheet" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Mensuration Match</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <p v-if="flash.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ flash.success }}
                </p>
                <p v-if="flash.error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    {{ flash.error }}
                </p>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Formula sheet</h3>
                            <p class="mt-1 text-sm text-slate-600">
                                Tick which classes see each formula. Use <span class="font-medium text-slate-800">Try</span> above a class to check that class’s board.
                                Saving ticks also turns the class offer on/off automatically.
                                Catalog: {{ catalog_summary.perimeter || 0 }} perimeter ·
                                {{ catalog_summary.area || 0 }} area ·
                                {{ catalog_summary.volume || 0 }} volume.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                            :disabled="savingSheet"
                            @click="saveSheet"
                        >
                            {{ savingSheet ? 'Saving…' : 'Save formula ticks' }}
                        </button>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            v-for="opt in [
                                { key: 'all', label: 'All' },
                                { key: 'perimeter', label: 'Perimeter' },
                                { key: 'area', label: 'Area' },
                                { key: 'volume', label: 'Volume' },
                            ]"
                            :key="opt.key"
                            type="button"
                            class="rounded-full px-3 py-1 text-xs font-semibold"
                            :class="
                                measureFilter === opt.key
                                    ? 'bg-indigo-600 text-white'
                                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                            "
                            @click="measureFilter = opt.key"
                        >
                            {{ opt.label }}
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Measure</th>
                                <th class="px-3 py-3">Figure</th>
                                <th class="min-w-[16rem] px-3 py-3">Question</th>
                                <th class="px-3 py-3">Answer</th>
                                <th
                                    class="px-2 py-2 text-center"
                                    :colspan="classColumns.length || classNumbers.length"
                                >
                                    Class applicable
                                </th>
                            </tr>
                            <tr class="border-t border-slate-200 bg-slate-50/90">
                                <th colspan="4" class="px-3 py-2 text-right text-[10px] font-semibold normal-case tracking-normal text-slate-500">
                                    Try board
                                </th>
                                <th
                                    v-for="col in classColumns"
                                    :key="`try-${col.grade_level_id}`"
                                    class="px-1 py-2 text-center align-bottom"
                                >
                                    <div class="flex min-w-[4.5rem] flex-col items-center gap-1">
                                        <Link
                                            v-if="liveCount(col.class_number, 'perimeter_area') > 0"
                                            :href="tryUrl(col.grade_level_id, 'perimeter_area')"
                                            class="rounded border border-indigo-200 bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold normal-case tracking-normal text-indigo-800 hover:bg-indigo-100"
                                        >
                                            Try P&amp;A
                                        </Link>
                                        <span
                                            v-else
                                            class="px-1.5 py-0.5 text-[10px] font-medium normal-case tracking-normal text-slate-300"
                                        >
                                            —
                                        </span>
                                        <Link
                                            v-if="liveCount(col.class_number, 'volume') > 0"
                                            :href="tryUrl(col.grade_level_id, 'volume')"
                                            class="rounded border border-violet-200 bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold normal-case tracking-normal text-violet-800 hover:bg-violet-100"
                                        >
                                            Try vol
                                        </Link>
                                        <span
                                            v-else
                                            class="px-1.5 py-0.5 text-[10px] font-medium normal-case tracking-normal text-slate-300"
                                        >
                                            —
                                        </span>
                                    </div>
                                </th>
                            </tr>
                            <tr class="border-t border-slate-200 bg-slate-50/80">
                                <th colspan="4" />
                                <th
                                    v-for="col in classColumns"
                                    :key="`cls-${col.grade_level_id}`"
                                    class="px-2 py-2 text-center font-semibold text-slate-700"
                                    :title="col.grade_name"
                                >
                                    {{ col.class_number }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="row in filteredSheetRows"
                                :key="row.key"
                                class="align-top hover:bg-slate-50/70"
                            >
                                <td class="px-3 py-3">
                                    <span
                                        class="inline-block rounded px-2 py-0.5 text-xs font-semibold capitalize"
                                        :class="measureBadge(row.measure)"
                                    >
                                        {{ row.measure }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 font-medium capitalize text-slate-900">
                                    {{ row.figure }}
                                </td>
                                <td class="px-3 py-3 text-slate-700">
                                    {{ row.question }}
                                </td>
                                <td class="px-3 py-3 font-mono text-sm font-semibold text-slate-900">
                                    {{ row.answer }}
                                </td>
                                <td
                                    v-for="col in classColumns"
                                    :key="`${row.key}-${col.class_number}`"
                                    class="px-2 py-3 text-center"
                                >
                                    <input
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        :checked="!!row.classes[String(col.class_number)]"
                                        @change="toggleClass(row, col.class_number)"
                                    />
                                </td>
                            </tr>
                            <tr v-if="!filteredSheetRows.length">
                                <td colspan="20" class="px-4 py-8 text-center text-sm text-slate-500">
                                    No formulas in this filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end">
                    <button
                        type="button"
                        class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="savingSheet"
                        @click="saveSheet"
                    >
                        {{ savingSheet ? 'Saving…' : 'Save formula ticks' }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
