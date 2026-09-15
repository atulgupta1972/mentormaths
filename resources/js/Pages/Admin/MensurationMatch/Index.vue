<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    catalog_summary: { type: Object, default: () => ({}) },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const savingId = ref(null);

function saveRow(row) {
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

function onToggle(row) {
    saveRow(row);
}

function tryUrl(row, board) {
    return route('admin.mensuration-match.preview', {
        gradeLevel: row.grade_level_id,
        board,
    });
}
</script>

<template>
    <Head title="Mensuration Match — Classes" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Mensuration Match</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <p v-if="flash.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ flash.success }}
                </p>
                <p v-if="flash.error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    {{ flash.error }}
                </p>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-semibold text-slate-900">Class mapping</h3>
                    <p class="mt-1 text-sm text-slate-600">
                        Tick <span class="font-medium text-slate-800">Offer match</span> to show Mensuration Match to that class.
                        Board ticks (Perimeter &amp; area / Volume) can be set anytime — changes save as soon as you click.
                        Use <span class="font-medium text-slate-800">Try</span> to run a board yourself (not saved to any student).
                        Catalog: {{ catalog_summary.perimeter_area || 0 }} perimeter/area cards ·
                        {{ catalog_summary.volume || 0 }} volume cards.
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
                                        @change="onToggle(row)"
                                    />
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <input
                                        v-model="row.perimeter_area_enabled"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        @change="onToggle(row)"
                                    />
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <input
                                        v-model="row.volume_enabled"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        @change="onToggle(row)"
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
                                        <span
                                            v-if="!row.perimeter_area_item_count && !row.volume_item_count"
                                            class="text-xs text-slate-400"
                                        >
                                            —
                                        </span>
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
