<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';

const props = defineProps({
    version: Object,
    rows: Array,
    academicYears: Array,
});

const search = ref('');

const form = useForm({
    rows: props.rows.length
        ? props.rows.map((row) => ({ ...row }))
        : [emptyRow()],
});

const carryForm = useForm({
    academic_year_id: '',
});

function emptyRow() {
    return {
        id: null,
        chapter_id: null,
        chapter_number: '',
        chapter_name: '',
        topic_name: '',
        learning_outcomes: '',
        difficulty: '',
        planned_periods: '',
        remarks: '',
    };
}

const addRow = () => {
    const last = form.rows[form.rows.length - 1];
    form.rows.push({
        ...emptyRow(),
        chapter_number: last?.chapter_number || '',
        chapter_name: last?.chapter_name || '',
        chapter_id: last?.chapter_id || null,
    });
};

const removeRow = (index) => {
    form.rows.splice(index, 1);
};

const rowMatchesSearch = (row, query) => {
    const fields = [
        row.chapter_number,
        row.chapter_name,
        row.topic_name,
        row.learning_outcomes,
        row.difficulty,
        row.planned_periods,
        row.remarks,
    ];

    return fields.some((field) => String(field ?? '').toLowerCase().includes(query));
};

const filteredRows = computed(() => {
    const query = search.value.trim().toLowerCase();

    return form.rows
        .map((row, index) => ({ row, index }))
        .filter(({ row }) => query === '' || rowMatchesSearch(row, query));
});

const clearSearch = () => {
    search.value = '';
};

const autoResize = (event) => {
    const el = event?.target ?? event;
    if (!el) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
};

const resizeAllFields = () => {
    nextTick(() => {
        document.querySelectorAll('.syllabus-field').forEach((el) => autoResize(el));
    });
};

onMounted(resizeAllFields);

const saveRows = () => {
    form.put(route('admin.syllabus.rows.update', props.version.id));
};

const submitCarryForward = () => {
    carryForm.post(route('admin.syllabus.carry-forward', props.version.id));
};
</script>

<template>
    <Head :title="version.board.code + ' ' + version.grade_level.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">
                    {{ version.board.code }} {{ version.grade_level.name }} — {{ version.subject.name }}
                </h2>
                <Link :href="route('admin.syllabus.index')" class="text-sm text-indigo-600">Back</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="$page.props.flash?.success"
                    class="rounded-md bg-green-50 p-4 text-sm text-green-800"
                >
                    {{ $page.props.flash.success }}
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm">
                    <p class="text-sm text-gray-600">
                        Academic year: <strong>{{ version.academic_year.name }}</strong>
                        · Status: <strong class="capitalize">{{ version.status }}</strong>
                        · Rows: <strong>{{ form.rows.length }}</strong>
                    </p>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                        <h3 class="font-medium text-gray-900">Syllabus table</h3>
                        <div class="flex gap-2">
                            <SecondaryButton type="button" @click="addRow">Add row</SecondaryButton>
                            <PrimaryButton type="button" :disabled="form.processing" @click="saveRows">
                                Save syllabus
                            </PrimaryButton>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-b bg-gray-50 px-4 py-3">
                        <div class="relative min-w-[220px] flex-1">
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Search chapter, topic, concepts, difficulty..."
                                class="w-full rounded-md border-gray-300 py-2 pl-3 pr-9 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <button
                                v-if="search"
                                type="button"
                                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                aria-label="Clear search"
                                @click="clearSearch"
                            >
                                ✕
                            </button>
                        </div>
                        <p class="text-sm text-gray-500">
                            <template v-if="search.trim()">
                                Showing <strong>{{ filteredRows.length }}</strong> of {{ form.rows.length }} rows
                            </template>
                            <template v-else>
                                {{ form.rows.length }} rows
                            </template>
                        </p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Ch No.</th>
                                    <th class="min-w-[160px] px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Main Topic</th>
                                    <th class="min-w-[180px] px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Sub-Topic</th>
                                    <th class="min-w-[260px] px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Key Concepts</th>
                                    <th class="px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Difficulty</th>
                                    <th class="px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Periods</th>
                                    <th class="min-w-[140px] px-2 py-3 text-left text-xs font-medium uppercase text-gray-500">Remarks</th>
                                    <th class="px-2 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-for="{ row, index } in filteredRows" :key="index">
                                    <td class="align-top px-2 py-2">
                                        <input
                                            v-model="row.chapter_number"
                                            type="text"
                                            class="w-16 rounded-md border-gray-300 text-sm"
                                            placeholder="Ch 1"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <textarea
                                            v-model="row.chapter_name"
                                            rows="2"
                                            class="syllabus-field w-full min-w-[150px] rounded-md border-gray-300 text-sm"
                                            placeholder="Integers"
                                            @input="autoResize"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <textarea
                                            v-model="row.topic_name"
                                            rows="2"
                                            class="syllabus-field w-full min-w-[170px] rounded-md border-gray-300 text-sm"
                                            placeholder="Sub-topic"
                                            @input="autoResize"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <textarea
                                            v-model="row.learning_outcomes"
                                            rows="3"
                                            class="syllabus-field w-full min-w-[250px] rounded-md border-gray-300 text-sm"
                                            @input="autoResize"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <select v-model="row.difficulty" class="rounded-md border-gray-300 text-sm">
                                            <option value="">—</option>
                                            <option value="Easy">Easy</option>
                                            <option value="Medium">Medium</option>
                                            <option value="Hard">Hard</option>
                                        </select>
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <input
                                            v-model="row.planned_periods"
                                            type="number"
                                            min="0"
                                            class="w-16 rounded-md border-gray-300 text-sm"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <textarea
                                            v-model="row.remarks"
                                            rows="2"
                                            class="syllabus-field w-full min-w-[130px] rounded-md border-gray-300 text-sm"
                                            @input="autoResize"
                                        />
                                    </td>
                                    <td class="align-top px-2 py-2">
                                        <DangerButton type="button" class="!px-2 !py-1 text-xs" @click="removeRow(index)">
                                            Remove
                                        </DangerButton>
                                    </td>
                                </tr>
                                <tr v-if="form.rows.length === 0">
                                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                        No rows yet. Click "Add row" or re-import from Excel.
                                    </td>
                                </tr>
                                <tr v-else-if="filteredRows.length === 0">
                                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                        No rows match "{{ search }}". <button type="button" class="text-indigo-600 hover:underline" @click="clearSearch">Clear search</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="font-medium">Carry forward to next year</h3>
                    <p class="mt-1 text-sm text-gray-600">Clone this syllabus as a draft for a new academic year, then edit changes.</p>
                    <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="submitCarryForward">
                        <div>
                            <InputLabel value="Target academic year" />
                            <select v-model="carryForm.academic_year_id" class="mt-1 rounded-md border-gray-300" required>
                                <option value="" disabled>Select year</option>
                                <option
                                    v-for="year in academicYears"
                                    :key="year.id"
                                    :value="year.id"
                                    :disabled="year.id === version.academic_year_id"
                                >
                                    {{ year.name }}
                                </option>
                            </select>
                        </div>
                        <PrimaryButton :disabled="carryForm.processing">Carry forward</PrimaryButton>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.syllabus-field {
    resize: vertical;
    min-height: 3.5rem;
    line-height: 1.4;
    overflow: hidden;
}
</style>
