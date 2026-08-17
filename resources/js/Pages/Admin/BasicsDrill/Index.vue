<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    defaults: { type: Object, required: true },
    excludedTables: { type: Array, default: () => [] },
    coverage: {
        type: Object,
        default: () => ({ totals: { students: 0, with_mistakes: 0, never_started: 0 }, classes: [] }),
    },
});

const expandedId = ref(null);
const mistakesOnly = ref(false);

const excludeForm = useForm({
    excluded_tables_text: (props.excludedTables || []).join(', '),
});

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

const saveExcluded = () => {
    excludeForm.put(route('admin.basics-drill.globals.update'), {
        preserveScroll: true,
    });
};

const joinBits = (bits) => bits.filter(Boolean).join(' · ') || '—';

const lastLabel = (student) => {
    const bits = [];
    if (student.last_table) {
        bits.push(`T${student.last_table}`);
    }
    if (student.last_squares) {
        bits.push(student.last_squares);
    }
    if (student.last_cubes) {
        bits.push(student.last_cubes);
    }

    return joinBits(bits);
};

const nextLabel = (student) => {
    const bits = [];
    if (student.next_table) {
        bits.push(`T${student.next_table}`);
    }
    if (student.next_squares) {
        bits.push(student.next_squares);
    }
    if (student.next_cubes) {
        bits.push(student.next_cubes);
    }

    return joinBits(bits);
};

const formulaCovered = (student) => {
    if (!student.formula_pool) {
        return '—';
    }

    return `${student.formula_seen}/${student.formula_pool}`;
};

const drillWhen = (student) => {
    if (student.last_status === 'in_progress' || student.formula_status === 'in_progress') {
        return 'Tonight';
    }
    if (student.last_date || student.formula_date) {
        return student.last_date || student.formula_date;
    }

    return 'Not started';
};

const missChipClass = (miss) => {
    if (miss.fact_type === 'formula') {
        return miss.needs_review ? 'bg-indigo-100 text-indigo-900' : 'bg-indigo-50 text-indigo-800';
    }

    return miss.needs_review ? 'bg-amber-100 text-amber-900' : 'bg-rose-50 text-rose-800';
};

const visibleStudents = (grade) => {
    const students = grade.students || [];
    if (!mistakesOnly.value) {
        return students;
    }

    return students.filter((student) => student.miss_count > 0);
};

const coverageClasses = computed(() => props.coverage?.classes || []);
const totals = computed(() => props.coverage?.totals || { students: 0, with_mistakes: 0, never_started: 0 });
</script>

<template>
    <Head title="Basics drill settings" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Nightly drills</h2>
                <p class="text-sm text-gray-500">Formulas, then tables, squares & cubes — class-wise coverage.</p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-4 px-4 sm:px-6">
                <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                    Defaults: tables {{ defaults.table_from }}–{{ defaults.table_to }} (×{{ defaults.multiplier_from }}–{{ defaults.multiplier_to }}),
                    squares {{ defaults.square_from }}–{{ defaults.square_to }} ({{ defaults.squares_per_day }}/day),
                    cubes {{ defaults.cube_from }}–{{ defaults.cube_to }} ({{ defaults.cubes_per_day }}/day),
                    {{ defaults.seconds_per_blank }}s per blank.
                </div>

                <form
                    class="rounded-lg bg-white px-4 py-4 shadow-sm ring-1 ring-gray-200"
                    @submit.prevent="saveExcluded"
                >
                    <p class="font-semibold text-gray-900">Exclude tables (all classes)</p>
                    <p class="mt-1 text-sm text-gray-500">
                        These numbers are skipped in the daily rotation for every class. Example: <span class="font-mono">10, 11</span>
                    </p>
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <div class="min-w-[16rem] flex-1">
                            <InputLabel value="Skip tables" />
                            <input
                                v-model="excludeForm.excluded_tables_text"
                                type="text"
                                placeholder="10, 11"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            >
                        </div>
                        <PrimaryButton type="submit" :disabled="excludeForm.processing">
                            Save exclusions
                        </PrimaryButton>
                    </div>
                    <p v-if="excludedTables.length" class="mt-2 text-xs text-gray-500">
                        Currently skipping: {{ excludedTables.join(', ') }}
                    </p>
                    <p v-else class="mt-2 text-xs text-gray-500">No tables excluded.</p>
                </form>

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

                <section class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-4 py-3">
                        <div>
                            <h3 class="font-semibold text-gray-900">Who has covered what</h3>
                            <p class="mt-0.5 text-sm text-gray-500">
                                {{ totals.students }} students
                                · {{ totals.with_mistakes }} with mistakes
                                · {{ totals.never_started }} not started
                            </p>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="mistakesOnly" type="checkbox" class="rounded border-gray-300">
                            Mistakes only
                        </label>
                    </div>

                    <p v-if="coverageClasses.length === 0" class="px-4 py-6 text-sm text-gray-500">
                        No active students this year. Progress appears here once they start the nightly drill.
                    </p>

                    <div
                        v-for="grade in coverageClasses"
                        :key="grade.grade_level_id"
                        class="border-t border-gray-100 first:border-t-0"
                    >
                        <div class="flex items-baseline justify-between px-4 py-2">
                            <p class="text-sm font-semibold text-gray-900">{{ grade.grade_name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ grade.student_count }} · {{ grade.mistake_count }} with misses
                            </p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-4 py-2 font-medium">Student</th>
                                        <th class="px-4 py-2 font-medium">Tables last</th>
                                        <th class="px-4 py-2 font-medium">Tables next</th>
                                        <th class="px-4 py-2 font-medium">Formulas</th>
                                        <th class="px-4 py-2 font-medium">Formulas next</th>
                                        <th class="px-4 py-2 font-medium">Misses</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="student in visibleStudents(grade)"
                                        :key="student.student_id"
                                        class="border-t border-gray-100"
                                    >
                                        <td class="whitespace-nowrap px-4 py-2">
                                            <Link :href="student.student_url" class="font-medium text-indigo-700 hover:underline">
                                                {{ student.student_name }}
                                            </Link>
                                            <p class="text-xs text-gray-400">
                                                {{ drillWhen(student) }}
                                            </p>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 font-mono text-xs text-gray-800">
                                            {{ lastLabel(student) }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 font-mono text-xs text-gray-800">
                                            {{ nextLabel(student) }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-xs text-gray-800">
                                            <p class="font-mono">{{ formulaCovered(student) }}</p>
                                            <p v-if="student.formula_last_score" class="text-gray-400">
                                                last {{ student.formula_last_score }}
                                            </p>
                                        </td>
                                        <td class="px-4 py-2 text-xs text-gray-700">
                                            {{ student.formula_next || '—' }}
                                        </td>
                                        <td class="px-4 py-2">
                                            <div v-if="student.misses.length" class="flex flex-wrap gap-1">
                                                <span
                                                    v-for="miss in student.misses"
                                                    :key="miss.fact_key"
                                                    class="rounded-full px-2 py-0.5 text-xs"
                                                    :class="missChipClass(miss)"
                                                >
                                                    {{ miss.label }}
                                                    <span class="text-[10px] opacity-70">×{{ miss.times_failed }}</span>
                                                </span>
                                            </div>
                                            <span v-else class="text-xs text-gray-400">—</span>
                                        </td>
                                    </tr>
                                    <tr v-if="visibleStudents(grade).length === 0">
                                        <td colspan="6" class="px-4 py-3 text-xs text-gray-400">
                                            No students with mistakes in this class.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
