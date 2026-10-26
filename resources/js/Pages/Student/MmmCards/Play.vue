<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import BodmasPlay from '@/Pages/Student/MmmCards/BodmasPlay.vue';
import CongruencePlay from '@/Pages/Student/MmmCards/CongruencePlay.vue';
import EquationBalancePlay from '@/Pages/Student/MmmCards/EquationBalancePlay.vue';
import EquationFillPagePlay from '@/Pages/Student/MmmCards/EquationFillPagePlay.vue';
import SeriesFinished from '@/Pages/Student/MmmCards/SeriesFinished.vue';
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
    next_part: { type: Object, default: null },
});

const schema = computed(() => props.card.table_schema || {});
const rows = computed(() => schema.value.rows || []);
const columns = computed(() => schema.value.columns || []);

const hasFixedLabelColumn = computed(() => columns.value.some(
    (col) => col.key === 'label' && col.fixed,
));

const editableFields = computed(() => {
    const fields = columns.value
        .filter((col) => col.key && col.key !== 'label' && ! col.fixed)
        .map((col) => col.key);

    return fields.length ? fields : ['marks', 'count', 'total'];
});

const fillsDenomination = computed(() => editableFields.value.includes('denomination'));

/** Columns before the value columns — used for footer colspan. */
const leadingColCount = computed(() => {
    const keys = new Set(editableFields.value);
    let n = 0;
    for (const col of columns.value) {
        if (keys.has(col.key)) {
            break;
        }
        n += 1;
    }

    return Math.max(n, 1);
});

const blankRows = () => {
    const out = {};
    for (const row of rows.value) {
        const cells = {};
        const fixedValues = row.fixed_values || {};
        for (const field of editableFields.value) {
            cells[field] = Object.prototype.hasOwnProperty.call(fixedValues, field)
                ? String(fixedValues[field])
                : '';
        }
        out[row.key] = cells;
    }
    return out;
};

const form = reactive({
    rows: blankRows(),
    grand_total: '',
    grand_count: '',
    equation: '',
    x_value: '',
});

const cellResults = ref({});
const message = ref('');
const submitting = ref(false);
const allCorrect = ref(false);

const usesVariable = computed(() => !! props.exercise?.uses_variable);
const usesValueX = computed(() => columns.value.some((col) => col.key === 'value_x'));
const isBalance = computed(() => props.exercise?.play_kind === 'balance');
const isBodmas = computed(() => props.exercise?.play_kind === 'bodmas');
const isCongruence = computed(() => props.exercise?.play_kind === 'congruence');
const isFillPage = computed(() => props.exercise?.play_kind === 'fill_page');
const fillCount = computed(() => props.exercise?.fill_page?.items?.length || 0);
const fillPageBlurb = computed(() => props.exercise?.fill_page?.page_blurb || props.card?.method_blurb);

const formatEqPart = (value) => {
    const text = String(value ?? '').trim();
    if (! text) {
        return '…';
    }
    if (text.startsWith('-')) {
        return `(${text})`;
    }

    return text;
};

/** Built from the Total column — student does not type this. */
const autoEquation = computed(() => {
    const totalKey = editableFields.value.includes('total') ? 'total' : null;
    if (! totalKey || ! rows.value.length) {
        return '';
    }
    const parts = rows.value.map((row) => formatEqPart(form.rows?.[row.key]?.[totalKey]));
    const score = form.grand_total;
    if (parts.every((p) => p === '…') && ! String(score || '').trim()) {
        return '';
    }

    return `${parts.join(' + ')} = ${String(score || '').trim() || '…'}`;
});

const isFixedCell = (row, field) => {
    const fixedValues = row.fixed_values || {};

    return Object.prototype.hasOwnProperty.call(fixedValues, field);
};

watch(
    () => [props.exercise?.id, JSON.stringify(schema.value)],
    () => {
        form.rows = blankRows();
        form.grand_total = '';
        form.grand_count = '';
        form.equation = '';
        form.x_value = '';
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
        const cleanMath = (value) => String(value ?? '')
            .replace(/[−–—﹣－]/g, '-')
            .replace(/[＝﹦]/g, '=')
            .replace(/[＋﹢]/g, '+')
            // 50×2x is multiply; 2×+ is the variable x.
            .replace(/(\d)[×⋅](\d)/g, '$1*$2')
            .replace(/(\d)[×⋅]/g, '$1x')
            .replace(/[×⋅](?=[+=\-)]|$)/g, 'x')
            .replace(/[×⋅]/g, '*')
            .replace(/\s+/g, '');

        const equationClean = cleanMath(form.equation);
        // Encode + / = so some hosts/WAFs do not strip or alter the equation field.
        const equationSafe = equationClean
            .replace(/\+/g, '__P__')
            .replace(/=/g, '__E__')
            .replace(/\//g, '__D__');

        const payload = {
            exercise_id: props.exercise.id,
            rows: Object.fromEntries(
                Object.entries(form.rows).map(([rowKey, cells]) => [
                    rowKey,
                    Object.fromEntries(
                        Object.entries(cells).map(([field, value]) => [field, cleanMath(value)]),
                    ),
                ]),
            ),
            grand_total: cleanMath(form.grand_total),
            grand_count: cleanMath(form.grand_count),
            equation: equationClean,
            coin_equation: equationSafe,
            x_value: cleanMath(form.x_value),
        };

        const { data } = await axios.post(props.check_url, payload, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
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
    form.grand_count = '';
    form.equation = '';
    form.x_value = '';
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

const canGoNext = computed(() => {
    const total = Number(props.card?.exercises_total ?? 0);
    const index = Number(props.exercise_index ?? 0);

    return total > 0 && index + 1 < total;
});

/** Method sketch stays for the first three sums; from sum 4 students fill without the reminder. */
const showMethodSketch = computed(() => Number(props.exercise_index ?? 0) < 3);

const footerColspan = computed(() => {
    const cols = columns.value.length;
    if (schema.value.show_grand_count && schema.value.show_grand_total) {
        return Math.max(cols - 2, 1);
    }
    if (schema.value.show_grand_total || schema.value.show_grand_count) {
        return Math.max(cols - 1, 1);
    }

    return leadingColCount.value;
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

        <div class="mx-auto space-y-4 p-4 sm:p-6" :class="isFillPage || isCongruence ? 'max-w-4xl' : 'max-w-3xl'">
            <p v-if="is_staff_preview" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950">
                Staff preview — checking does not save student progress.
            </p>

            <section
                v-if="isFillPage"
                class="rounded-xl bg-white p-4 shadow-sm ring-2"
                :class="accentRing"
            >
                <h3 class="text-sm font-extrabold uppercase tracking-wide text-rose-900">
                    Solve in your copy
                </h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">
                    {{ fillPageBlurb }}
                </p>
            </section>

            <section
                v-else-if="showMethodSketch && isBalance"
                class="rounded-xl bg-white p-4 shadow-sm ring-2"
                :class="accentRing"
            >
                <h3 class="text-sm font-extrabold uppercase tracking-wide text-rose-900">
                    How to balance
                </h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">
                    {{ card.method_blurb }}
                </p>
            </section>

            <section
                v-else-if="showMethodSketch && isCongruence"
                class="rounded-xl bg-white p-4 shadow-sm ring-2"
                :class="accentRing"
            >
                <h3 class="text-sm font-extrabold uppercase tracking-wide text-indigo-900">
                    Order, mark, table, case
                </h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">
                    {{ card.method_blurb }}
                </p>
            </section>

            <section
                v-else-if="showMethodSketch && isBodmas"
                class="rounded-xl bg-white p-4 shadow-sm ring-2"
                :class="accentRing"
            >
                <h3 class="text-sm font-extrabold uppercase tracking-wide text-amber-900">
                    Brackets first
                </h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">
                    {{ card.method_blurb }}
                </p>
            </section>

            <section
                v-else-if="showMethodSketch"
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
                                    v-for="col in columns"
                                    :key="col.key"
                                    class="border border-teal-200 px-2 py-1.5 text-left text-xs font-bold uppercase text-teal-950"
                                >
                                    {{ col.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="`sketch-${row.key}`">
                                <td
                                    v-if="hasFixedLabelColumn"
                                    class="border border-slate-200 bg-slate-50 px-2 py-1.5 font-semibold"
                                >
                                    {{ row.label }}
                                </td>
                                <td
                                    v-for="field in editableFields"
                                    :key="`sketch-${row.key}-${field}`"
                                    class="border border-slate-200 px-2 py-1.5 text-slate-400"
                                >
                                    …
                                </td>
                            </tr>
                            <tr v-if="schema.show_grand_count">
                                <td
                                    :colspan="footerColspan"
                                    class="border border-slate-200 bg-slate-50 px-2 py-1.5 text-right font-bold"
                                >
                                    {{ schema.grand_count_label || 'Total coins' }}
                                </td>
                                <td class="border border-slate-200 px-2 py-1.5 text-slate-400">…</td>
                                <td
                                    v-if="schema.show_grand_total"
                                    class="border border-slate-200 px-2 py-1.5 text-slate-400"
                                >
                                    …
                                </td>
                            </tr>
                            <tr v-else-if="schema.show_grand_total">
                                <td
                                    :colspan="footerColspan"
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
                        <template v-if="isFillPage">{{ fillCount }} sums on this page</template>
                        <template v-else>Sum {{ exercise_index + 1 }} / {{ card.exercises_total }}</template>
                    </span>
                </div>
                <p class="mt-3 text-base font-medium leading-relaxed text-slate-900">
                    {{ exercise.question_text }}
                </p>
                <EquationFillPagePlay
                    v-if="isFillPage"
                    :key="exercise.id"
                    :exercise="exercise"
                    :check_url="check_url"
                    :can-go-next="canGoNext"
                    :next-part="next_part"
                    :series-label="card.series_label"
                    @next="goNext"
                />
                <EquationBalancePlay
                    v-else-if="isBalance"
                    :key="exercise.id"
                    :exercise="exercise"
                    :card="card"
                    :exercise_index="exercise_index"
                    :check_url="check_url"
                    :next-part="next_part"
                    @next="goNext"
                />
                <BodmasPlay
                    v-else-if="isBodmas"
                    :key="exercise.id"
                    :exercise="exercise"
                    :card="card"
                    :exercise_index="exercise_index"
                    :check_url="check_url"
                    :next-part="next_part"
                    @next="goNext"
                />
                <CongruencePlay
                    v-else-if="isCongruence"
                    :key="exercise.id"
                    :exercise="exercise"
                    :card="card"
                    :exercise_index="exercise_index"
                    :check_url="check_url"
                    :next-part="next_part"
                    @next="goNext"
                />
                <p
                    v-else-if="exercise.variable_tip"
                    class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm leading-relaxed text-amber-950"
                >
                    {{ exercise.variable_tip }}
                </p>
                <template v-if="!isBalance && !isBodmas && !isFillPage && !isCongruence">
                <p v-if="fillsDenomination" class="mt-2 text-xs text-slate-500">
                    Write each denomination in ₹ (number only, e.g. 2, 5, 10), then counts and totals. Wrong cells will be highlighted.
                </p>
                <p v-else class="mt-2 text-xs text-slate-500">
                    Fill numbers from the question. Row headings are given. Then submit the table.
                </p>
                <p v-if="usesVariable" class="mt-2 text-xs text-slate-500">
                    You may type numbers or expressions with <span class="font-mono font-semibold">x</span>
                    (example: <span class="font-mono">20-x</span>, <span class="font-mono">2x</span>, <span class="font-mono">-20+x</span>).
                </p>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-100">
                                <th
                                    v-for="col in columns"
                                    :key="col.key"
                                    class="border border-slate-300 px-2 py-1.5 text-left text-xs font-bold uppercase text-slate-800"
                                >
                                    {{ col.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.key">
                                <td
                                    v-if="hasFixedLabelColumn"
                                    class="border border-slate-300 bg-slate-50 px-2 py-1.5 font-semibold text-slate-900"
                                >
                                    {{ row.label }}
                                </td>
                                <td
                                    v-for="field in editableFields"
                                    :key="`${row.key}-${field}`"
                                    class="border p-1"
                                    :class="cellClass(`${row.key}.${field}`)"
                                >
                                    <span
                                        v-if="isFixedCell(row, field)"
                                        class="block w-full min-w-[4.5rem] px-1 py-1 text-center font-mono text-sm font-semibold text-slate-800"
                                    >
                                        {{ form.rows[row.key][field] }}
                                    </span>
                                    <input
                                        v-else
                                        v-model="form.rows[row.key][field]"
                                        type="text"
                                        :inputmode="usesVariable ? 'text' : 'numeric'"
                                        class="w-full min-w-[4.5rem] rounded border-0 bg-transparent px-1 py-1 text-center font-mono text-sm font-semibold focus:ring-0"
                                        autocomplete="off"
                                    >
                                </td>
                            </tr>
                            <tr v-if="schema.show_grand_count && schema.show_grand_total">
                                <td
                                    :colspan="footerColspan"
                                    class="border border-slate-300 bg-slate-50 px-2 py-1.5 text-right font-bold"
                                >
                                    {{ schema.grand_count_label || 'Total coins' }}
                                    /
                                    {{ schema.grand_total_label || 'Total money' }}
                                </td>
                                <td
                                    class="border p-1"
                                    :class="cellClass('grand_count')"
                                >
                                    <input
                                        v-model="form.grand_count"
                                        type="text"
                                        inputmode="numeric"
                                        class="w-full min-w-[4.5rem] rounded border-0 bg-transparent px-1 py-1 text-center font-mono text-sm font-extrabold focus:ring-0"
                                        autocomplete="off"
                                        :aria-label="schema.grand_count_label || 'Total coins'"
                                    >
                                </td>
                                <td
                                    class="border p-1"
                                    :class="cellClass('grand_total')"
                                >
                                    <input
                                        v-model="form.grand_total"
                                        type="text"
                                        inputmode="numeric"
                                        class="w-full min-w-[4.5rem] rounded border-0 bg-transparent px-1 py-1 text-center font-mono text-sm font-extrabold focus:ring-0"
                                        autocomplete="off"
                                        :aria-label="schema.grand_total_label || 'Total money'"
                                    >
                                </td>
                            </tr>
                            <tr v-else-if="schema.show_grand_count">
                                <td
                                    :colspan="footerColspan"
                                    class="border border-slate-300 bg-slate-50 px-2 py-1.5 text-right font-bold"
                                >
                                    {{ schema.grand_count_label || 'Total coins' }}
                                </td>
                                <td
                                    class="border p-1"
                                    :class="cellClass('grand_count')"
                                >
                                    <input
                                        v-model="form.grand_count"
                                        type="text"
                                        inputmode="numeric"
                                        class="w-full min-w-[4.5rem] rounded border-0 bg-transparent px-1 py-1 text-center font-mono text-sm font-extrabold focus:ring-0"
                                        autocomplete="off"
                                    >
                                </td>
                            </tr>
                            <tr v-else-if="schema.show_grand_total">
                                <td
                                    :colspan="footerColspan"
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
                                        class="w-full min-w-[4.5rem] rounded border-0 bg-transparent px-1 py-1 text-center font-mono text-sm font-extrabold focus:ring-0"
                                        autocomplete="off"
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="exercise.show_auto_equation || exercise.ask_equation || exercise.ask_x_value"
                    class="mt-4 space-y-3"
                >
                    <div v-if="exercise.show_auto_equation">
                        <label class="block text-xs font-bold uppercase tracking-wide text-slate-700">
                            Equation from the table
                        </label>
                        <p class="mt-0.5 text-[11px] text-slate-500">
                            Built automatically by adding the Total column. Fill the totals correctly — you do not need to type the equation.
                        </p>
                        <div class="mt-1 w-full max-w-xl rounded-md border border-teal-200 bg-teal-50 px-3 py-2 font-mono text-sm font-semibold text-teal-950">
                            {{ autoEquation || '… + … = …' }}
                        </div>
                    </div>
                    <div v-if="exercise.ask_equation">
                        <label class="block text-xs font-bold uppercase tracking-wide text-slate-700">
                            {{ exercise.equation_prompt || 'Write the equation' }}
                        </label>
                        <p class="mt-0.5 text-[11px] text-slate-500">
                            <template v-if="exercise.equation_help">{{ exercise.equation_help }}</template>
                            <template v-else>
                                Add the
                                <span class="font-semibold">{{ usesValueX ? 'Value (in x)' : 'Coins (in x)' }}</span>
                                column and set it equal to
                                {{ usesValueX ? 'total money' : 'total coins' }}.
                                Write the equation yourself — the system will check it.
                            </template>
                        </p>
                        <input
                            v-model="form.equation"
                            type="text"
                            inputmode="text"
                            class="mt-1 w-full max-w-xl rounded-md border px-3 py-2 font-mono text-sm font-semibold"
                            :class="cellClass('equation')"
                            placeholder=""
                            autocomplete="off"
                        >
                    </div>
                    <div v-if="exercise.ask_x_value">
                        <label class="block text-xs font-bold uppercase tracking-wide text-slate-700">
                            {{ exercise.x_prompt || 'Value of x' }}
                        </label>
                        <input
                            v-model="form.x_value"
                            type="text"
                            inputmode="numeric"
                            class="mt-1 w-32 rounded-md border px-3 py-2 text-center font-mono text-sm font-extrabold"
                            :class="cellClass('x_value')"
                            autocomplete="off"
                        >
                    </div>
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
                <div v-else-if="allCorrect && !canGoNext" class="mt-3">
                    <SeriesFinished :next-part="next_part" :series-label="card.series_label" />
                </div>
                </template>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
