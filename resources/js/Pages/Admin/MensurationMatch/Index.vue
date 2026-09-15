<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    sheet: {
        type: Object,
        default: () => ({ class_numbers: [], rows: [] }),
    },
    catalog_summary: { type: Object, default: () => ({}) },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const savingId = ref(null);
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

const classNumbers = computed(() => props.sheet?.class_numbers || []);

const filteredSheetRows = computed(() => {
    if (measureFilter.value === 'all') {
        return sheetRows.value;
    }
    return sheetRows.value.filter((row) => row.measure === measureFilter.value);
});

function saveOffer(row) {
    savingId.value = row.grade_level_id;
    router.put(
        route('admin.mensuration-match.update', row.grade_level_id),
        {
            enabled: !!row.enabled,
            perimeter_area_enabled: !!row.perimeter_area_enabled,
            volume_enabled: !!row.volume_enabled,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                savingId.value = null;
            },
        },
    );
}

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

function tryUrl(row, board) {
    return route('admin.mensuration-match.preview', {
        gradeLevel: row.grade_level_id,
        board,
    });
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

                <!-- Formula sheet -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Formula sheet</h3>
                            <p class="mt-1 text-sm text-slate-600">
                                Tick which classes see each formula. Filter by perimeter / area / volume.
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
                                    :colspan="classNumbers.length"
                                >
                                    Class applicable
                                </th>
                            </tr>
                            <tr class="border-t border-slate-200 bg-slate-50/80">
                                <th colspan="4" />
                                <th
                                    v-for="n in classNumbers"
                                    :key="n"
                                    class="px-2 py-2 text-center font-semibold text-slate-700"
                                >
                                    {{ n }}
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
                                    v-for="n in classNumbers"
                                    :key="`${row.key}-${n}`"
                                    class="px-2 py-3 text-center"
                                >
                                    <input
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        :checked="!!row.classes[String(n)]"
                                        @change="toggleClass(row, n)"
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

                <!-- Offer / try -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-semibold text-slate-900">Offer to class &amp; try</h3>
                    <p class="mt-1 text-sm text-slate-600">
                        Turn on Mensuration Match for a class, choose boards, then Try to check the student view.
                    </p>
                </div>

                <div class="overflow-x-auto overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Class</th>
                                <th class="px-4 py-3 text-center">Offer match</th>
                                <th class="px-4 py-3 text-center">Perimeter &amp; area</th>
                                <th class="px-4 py-3 text-center">Volume</th>
                                <th class="px-4 py-3">Try board</th>
                                <th class="px-4 py-3 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in rows" :key="row.grade_level_id" class="hover:bg-slate-50/80">
                                <td class="px-4 py-3 font-medium text-slate-900">{{ row.grade_name }}</td>
                                <td class="px-4 py-3 text-center">
                                    <input
                                        v-model="row.enabled"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        @change="saveOffer(row)"
                                    />
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <input
                                        v-model="row.perimeter_area_enabled"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        @change="saveOffer(row)"
                                    />
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <input
                                        v-model="row.volume_enabled"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        @change="saveOffer(row)"
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        <Link
                                            v-if="row.perimeter_area_item_count > 0"
                                            :href="tryUrl(row, 'perimeter_area')"
                                            class="rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-800 hover:bg-indigo-100"
                                        >
                                            Try P&amp;A
                                        </Link>
                                        <Link
                                            v-if="row.volume_item_count > 0"
                                            :href="tryUrl(row, 'volume')"
                                            class="rounded-md border border-violet-200 bg-violet-50 px-2 py-1 text-xs font-semibold text-violet-800 hover:bg-violet-100"
                                        >
                                            Try volume
                                        </Link>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right text-xs text-slate-500">
                                    {{ savingId === row.grade_level_id ? 'Saving…' : '' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
