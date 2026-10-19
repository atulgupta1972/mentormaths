<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    card: { type: Object, required: true },
    exercise: { type: Object, default: null },
    exercise_index: { type: Number, default: 0 },
    has_next: { type: Boolean, default: false },
    all_done: { type: Boolean, default: false },
    progress: { type: Object, default: () => ({}) },
    check_url: { type: String, required: true },
    back_url: { type: String, required: true },
    is_staff_preview: { type: Boolean, default: false },
});

const schema = computed(() => props.card.table_schema || {});
const rows = computed(() => schema.value.rows || []);

const blankRows = () => {
    const out = {};
    for (const row of rows.value) {
        out[row.key] = { marks: '', count: '', total: '' };
    }
    return out;
};

const form = reactive({
    rows: blankRows(),
    grand_total: '',
});

const cellResults = ref({});
const message = ref('');
const submitting = ref(false);
const allCorrect = ref(false);

watch(
    () => props.exercise?.id,
    () => {
        form.rows = blankRows();
        form.grand_total = '';
        cellResults.value = {};
        message.value = '';
        allCorrect.value = false;
    },
);

const cellClass = (key) => {
    if (! (key in cellResults.value)) {
        return 'border-slate-300';
    }
    return cellResults.value[key]
        ? 'border-emerald-500 bg-emerald-50'
        : 'border-rose-500 bg-rose-50';
};

const submit = async () => {
    if (! props.exercise || submitting.value) {
        return;
    }
    submitting.value = true;
    message.value = '';
    try {
        const { data } = await axios.post(props.check_url, {
            exercise_id: props.exercise.id,
            rows: form.rows,
            grand_total: form.grand_total,
        });
        cellResults.value = data.cell_results || {};
        message.value = data.message || '';
        allCorrect.value = !! data.ok;
    } catch (e) {
        message.value = e?.response?.data?.message || 'Could not check the table. Try again.';
        allCorrect.value = false;
    } finally {
        submitting.value = false;
    }
};

const resetTable = () => {
    form.rows = blankRows();
    form.grand_total = '';
    cellResults.value = {};
    message.value = '';
    allCorrect.value = false;
};

const goNext = () => {
    const next = Number(props.exercise_index) + 2; // 1-based query param
    const url = props.is_staff_preview
        ? route('admin.mmm-cards.play', props.card.id)
        : route('student.mmm-cards.show', props.card.id);

    router.get(url, { exercise: next }, { preserveScroll: true });
};

const accentRing = computed(() => ({
    teal: 'ring-teal-300',
    amber: 'ring-amber-300',
    rose: 'ring-rose-300',
}[props.card.accent] || 'ring-teal-300'));

const accentBg = computed(() => ({
    teal: 'bg-teal-700',
    amber: 'bg-amber-700',
    rose: 'bg-rose-700',
}[props.card.accent] || 'bg-teal-700'));

// Prefer live count over server has_next (stale after submit / older builds).
const canGoNext = computed(() => {
    const total = Number(props.card?.exercises_total ?? 0);
    const index = Number(props.exercise_index ?? 0);

    return total > 0 && index + 1 < total;
});
</script>

<template>
    <Head :title="card.heading" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-teal-800">
                        MentorMaths method
                    </p>
                    <h2 class="text-xl font-semibold text-gray-800">{{ card.heading }}</h2>
                </div>
                <Link :href="back_url" class="text-sm text-gray-600 hover:underline">Back</Link>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
            <p v-if="is_staff_preview" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950">
                Staff preview — checking the table does not save student progress.
            </p>

            <section
                class="rounded-xl bg-white p-4 shadow-sm ring-2"
                :class="accentRing"
            >
                <h3 class="text-sm font-extrabold uppercase tracking-wide text-teal-900">
                    How to make the table
                </h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">
                    {{ card.method_blurb }}
                </p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-teal-50">
                                <th
                                    v-for="col in schema.columns"
                                    :key="col.key"
                                    class="border border-teal-200 px-2 py-1.5 text-left text-xs font-bold uppercase text-teal-950"
                                >
                                    {{ col.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="`sketch-${row.key}`">
                                <td class="border border-slate-200 bg-slate-50 px-2 py-1.5 font-semibold">
                                    {{ row.label }}
                                </td>
                                <td
                                    v-for="field in ['marks', 'count', 'total']"
                                    :key="`sketch-${row.key}-${field}`"
                                    class="border border-slate-200 px-2 py-1.5 text-slate-400"
                                >
                                    …
                                </td>
                            </tr>
                            <tr v-if="schema.show_grand_total">
                                <td
                                    colspan="3"
                                    class="border border-slate-200 bg-slate-50 px-2 py-1.5 text-right font-bold"
                                >
                                    {{ schema.grand_total_label || 'Total' }}
                                </td>
                                <td class="border border-slate-200 px-2 py-1.5 text-slate-400">…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section
                v-if="exercise"
                class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="rounded px-2 py-0.5 text-[10px] font-extrabold uppercase text-white"
                        :class="accentBg"
                    >
                        {{ exercise.is_demo ? 'Demo' : exercise.difficulty }}
                    </span>
                    <span class="text-xs font-semibold text-slate-500">
                        Sum {{ exercise_index + 1 }} / {{ card.exercises_total }}
                    </span>
                </div>
                <p class="mt-3 text-base font-medium leading-relaxed text-slate-900">
                    {{ exercise.question_text }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    Fill numbers from the question. Row headings are given. Then submit the table.
                </p>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-100">
                                <th
                                    v-for="col in schema.columns"
                                    :key="col.key"
                                    class="border border-slate-300 px-2 py-1.5 text-left text-xs font-bold uppercase text-slate-800"
                                >
                                    {{ col.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.key">
                                <td class="border border-slate-300 bg-slate-50 px-2 py-1.5 font-semibold text-slate-900">
                                    {{ row.label }}
                                </td>
                                <td
                                    v-for="field in ['marks', 'count', 'total']"
                                    :key="`${row.key}-${field}`"
                                    class="border p-1"
                                    :class="cellClass(`${row.key}.${field}`)"
                                >
                                    <input
                                        v-model="form.rows[row.key][field]"
                                        type="text"
                                        inputmode="numeric"
                                        class="w-full min-w-[4rem] rounded border-0 bg-transparent px-1 py-1 text-center text-sm font-semibold focus:ring-0"
                                        autocomplete="off"
                                    >
                                </td>
                            </tr>
                            <tr v-if="schema.show_grand_total">
                                <td
                                    colspan="3"
                                    class="border border-slate-300 bg-slate-50 px-2 py-1.5 text-right font-bold"
                                >
                                    {{ schema.grand_total_label || 'Total' }}
                                </td>
                                <td
                                    class="border p-1"
                                    :class="cellClass('grand_total')"
                                >
                                    <input
                                        v-model="form.grand_total"
                                        type="text"
                                        inputmode="numeric"
                                        class="w-full min-w-[4rem] rounded border-0 bg-transparent px-1 py-1 text-center text-sm font-extrabold focus:ring-0"
                                        autocomplete="off"
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <PrimaryButton type="button" :disabled="submitting" @click="submit">
                        {{ submitting ? 'Checking…' : 'Submit table' }}
                    </PrimaryButton>
                    <SecondaryButton type="button" @click="resetTable">Clear</SecondaryButton>
                </div>

                <p
                    v-if="message"
                    class="mt-3 rounded-md px-3 py-2 text-sm font-medium"
                    :class="allCorrect ? 'bg-emerald-50 text-emerald-900' : 'bg-rose-50 text-rose-900'"
                >
                    {{ message }}
                </p>

                <div
                    v-if="allCorrect && canGoNext"
                    class="mt-3 flex flex-wrap items-center gap-3 rounded-lg border-2 border-teal-400 bg-teal-50 px-4 py-3"
                >
                    <p class="text-sm font-semibold text-teal-950">
                        Correct — continue to sum {{ exercise_index + 2 }} of {{ card.exercises_total }}.
                    </p>
                    <button
                        type="button"
                        class="rounded-md bg-teal-700 px-4 py-2 text-sm font-extrabold uppercase tracking-wide text-white hover:bg-teal-800"
                        @click="goNext"
                    >
                        Next sum →
                    </button>
                </div>
                <p
                    v-else-if="allCorrect && !canGoNext"
                    class="mt-2 text-sm font-semibold text-teal-900"
                >
                    You finished all sums on this method card.
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
