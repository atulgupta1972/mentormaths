<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    defaults: { type: Object, required: true },
});

const expandedId = ref(null);

const forms = Object.fromEntries(
    props.rows.map((row) => [
        row.grade_level_id,
        useForm({
            tables_enabled: row.settings.tables_enabled,
            squares_enabled: row.settings.squares_enabled,
            cubes_enabled: row.settings.cubes_enabled,
            table_from: row.settings.table_from,
            table_to: row.settings.table_to,
            multiplier_from: row.settings.multiplier_from,
            multiplier_to: row.settings.multiplier_to,
            square_from: row.settings.square_from,
            square_to: row.settings.square_to,
            cube_from: row.settings.cube_from,
            cube_to: row.settings.cube_to,
            squares_per_day: row.settings.squares_per_day,
            cubes_per_day: row.settings.cubes_per_day,
            seconds_per_blank: row.settings.seconds_per_blank,
        }),
    ]),
);

const save = (row) => {
    forms[row.grade_level_id].put(route('admin.basics-drill.update', row.grade_level_id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Basics drill settings" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Tables, squares & cubes</h2>
                <p class="text-sm text-gray-500">Class-wise daily memorisation drill (after formula).</p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-4 px-4 sm:px-6">
                <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                    Defaults: tables {{ defaults.table_from }}–{{ defaults.table_to }} (×{{ defaults.multiplier_from }}–{{ defaults.multiplier_to }}),
                    squares {{ defaults.square_from }}–{{ defaults.square_to }} ({{ defaults.squares_per_day }}/day),
                    cubes {{ defaults.cube_from }}–{{ defaults.cube_to }} ({{ defaults.cubes_per_day }}/day),
                    {{ defaults.seconds_per_blank }}s per blank.
                </div>

                <div
                    v-for="row in rows"
                    :key="row.grade_level_id"
                    class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-4 py-3 text-left"
                        @click="expandedId = expandedId === row.grade_level_id ? null : row.grade_level_id"
                    >
                        <div>
                            <p class="font-semibold text-gray-900">{{ row.grade_name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ row.has_custom_settings ? 'Custom settings' : 'Using defaults' }}
                            </p>
                        </div>
                        <span class="text-gray-400">{{ expandedId === row.grade_level_id ? '▲' : '▼' }}</span>
                    </button>

                    <form
                        v-if="expandedId === row.grade_level_id"
                        class="border-t border-gray-100 px-4 py-4"
                        @submit.prevent="save(row)"
                    >
                        <div class="flex flex-wrap gap-4 text-sm">
                            <label class="flex items-center gap-2">
                                <input v-model="forms[row.grade_level_id].tables_enabled" type="checkbox" class="rounded border-gray-300">
                                Tables
                            </label>
                            <label class="flex items-center gap-2">
                                <input v-model="forms[row.grade_level_id].squares_enabled" type="checkbox" class="rounded border-gray-300">
                                Squares
                            </label>
                            <label class="flex items-center gap-2">
                                <input v-model="forms[row.grade_level_id].cubes_enabled" type="checkbox" class="rounded border-gray-300">
                                Cubes
                            </label>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div>
                                <InputLabel value="Tables from–to" />
                                <div class="mt-1 flex gap-2">
                                    <input v-model.number="forms[row.grade_level_id].table_from" type="number" min="2" max="30" class="w-full rounded-md border-gray-300 text-sm">
                                    <input v-model.number="forms[row.grade_level_id].table_to" type="number" min="2" max="30" class="w-full rounded-md border-gray-300 text-sm">
                                </div>
                            </div>
                            <div>
                                <InputLabel value="Multipliers from–to" />
                                <div class="mt-1 flex gap-2">
                                    <input v-model.number="forms[row.grade_level_id].multiplier_from" type="number" min="2" max="9" class="w-full rounded-md border-gray-300 text-sm">
                                    <input v-model.number="forms[row.grade_level_id].multiplier_to" type="number" min="2" max="9" class="w-full rounded-md border-gray-300 text-sm">
                                </div>
                            </div>
                            <div>
                                <InputLabel value="Seconds per blank" />
                                <input v-model.number="forms[row.grade_level_id].seconds_per_blank" type="number" min="3" max="15" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            </div>
                            <div>
                                <InputLabel value="Squares from–to" />
                                <div class="mt-1 flex gap-2">
                                    <input v-model.number="forms[row.grade_level_id].square_from" type="number" min="2" max="30" class="w-full rounded-md border-gray-300 text-sm">
                                    <input v-model.number="forms[row.grade_level_id].square_to" type="number" min="2" max="30" class="w-full rounded-md border-gray-300 text-sm">
                                </div>
                            </div>
                            <div>
                                <InputLabel value="Squares per day" />
                                <input v-model.number="forms[row.grade_level_id].squares_per_day" type="number" min="1" max="10" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            </div>
                            <div>
                                <InputLabel value="Cubes from–to" />
                                <div class="mt-1 flex gap-2">
                                    <input v-model.number="forms[row.grade_level_id].cube_from" type="number" min="2" max="20" class="w-full rounded-md border-gray-300 text-sm">
                                    <input v-model.number="forms[row.grade_level_id].cube_to" type="number" min="2" max="20" class="w-full rounded-md border-gray-300 text-sm">
                                </div>
                            </div>
                            <div>
                                <InputLabel value="Cubes per day" />
                                <input v-model.number="forms[row.grade_level_id].cubes_per_day" type="number" min="1" max="5" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            </div>
                        </div>

                        <PrimaryButton type="submit" class="mt-4" :disabled="forms[row.grade_level_id].processing">
                            Save {{ row.grade_name }}
                        </PrimaryButton>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
