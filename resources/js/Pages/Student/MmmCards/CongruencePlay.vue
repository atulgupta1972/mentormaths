<script setup>
import SeriesFinished from '@/Pages/Student/MmmCards/SeriesFinished.vue';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    exercise: { type: Object, required: true },
    card: { type: Object, required: true },
    exercise_index: { type: Number, default: 0 },
    check_url: { type: String, required: true },
    nextPart: { type: Object, default: null },
});

const emit = defineEmits(['next']);

const play = computed(() => props.exercise?.congruence || {});
const steps = computed(() => play.value.steps || []);
const figures = computed(() => play.value.figures || []);
const givens = computed(() => play.value.givens || []);
const currentIndex = ref(0);
const currentStep = computed(() => steps.value[currentIndex.value] || null);
const letters = ref(['', '', '']);
const tool = ref('');
const placed = reactive({});
const vertexSlots = reactive({});
const rows = ref([]);
const caseChoice = ref('');
const message = ref('');
const stepOk = ref(false);
const submitting = ref(false);
const cellResults = ref({});
const allCorrect = ref(false);
const summary = ref(null);
const majorFigure = computed(() => {
    const named = (play.value.left_figure || currentStep.value?.left || '').toString();
    const match = figures.value.find((figure) => figure.name === named || figure.id === named);

    return match || figures.value[0] || null;
});
const isAlignStep = computed(() => ['order', 'align'].includes(currentStep.value?.type));
const allowedLetters = computed(() => (currentStep.value?.choices || []).map((letter) => String(letter).toUpperCase()));
const drawnHome = computed(() => {
    const custom = (play.value.draw_figures || []).find((figure) => figure.locked !== false && figure.label !== true);

    return custom || majorFigure.value;
});
const drawnCopy = computed(() => drawnHome.value);

const canGoNext = computed(() => {
    const total = Number(props.card?.exercises_total ?? 0);

    return total > 0 && Number(props.exercise_index ?? 0) + 1 < total;
});

watch(
    () => props.exercise?.id,
    () => {
        currentIndex.value = 0;
        resetStep();
        allCorrect.value = false;
        summary.value = null;
    },
    { immediate: true },
);

watch(currentStep, (step) => {
    if (step?.type === 'table') {
        rows.value = Array.from({ length: step.row_count || 3 }, () => ({ parts: '', reason: '', type: '' }));
    }
}, { immediate: true });

function resetStep() {
    letters.value = ['', '', ''];
    tool.value = '';
    Object.keys(placed).forEach((key) => {
        delete placed[key];
    });
    Object.keys(vertexSlots).forEach((key) => {
        delete vertexSlots[key];
    });
    rows.value = [];
    caseChoice.value = '';
    message.value = '';
    stepOk.value = false;
    cellResults.value = {};
    submitting.value = false;
}

const mappedLetters = () => {
    const figure = drawnHome.value;
    if (! figure?.vertices?.length) {
        return [...letters.value];
    }

    return figure.vertices.map((vertex) => vertexSlots[vertex.id] || '');
};

const typeVertex = (vertexId, raw) => {
    if (stepOk.value || allCorrect.value || ! isAlignStep.value) {
        return;
    }
    const letter = String(raw || '').replace(/[^a-zA-Z]/g, '').slice(-1).toUpperCase();
    if (! letter) {
        delete vertexSlots[vertexId];
        letters.value = mappedLetters();
        return;
    }
    if (allowedLetters.value.length && ! allowedLetters.value.includes(letter)) {
        message.value = `Type ${allowedLetters.value.join(', ')} only.`;
        vertexSlots[vertexId] = vertexSlots[vertexId] || '';
        letters.value = mappedLetters();
        return;
    }
    Object.keys(vertexSlots).forEach((key) => {
        if (key !== vertexId && vertexSlots[key] === letter) {
            delete vertexSlots[key];
        }
    });
    vertexSlots[vertexId] = letter;
    letters.value = mappedLetters();
    message.value = '';
};

const correspondingSide = (sideId) => {
    const first = vertexSlots[sideId?.[0]] || '';
    const second = vertexSlots[sideId?.[1]] || '';

    return first && second ? `${first}${second}` : '';
};

const correspondenceRows = computed(() => {
    const figure = drawnHome.value;
    if (! figure?.sides) {
        return [];
    }

    return figure.sides.map((side) => {
        const other = correspondingSide(side);
        const length = figure.lengths?.[side] || '';

        return {
            side,
            other,
            length,
            label: other ? `${side} = ${other}` : `${side} = ?`,
        };
    });
});

const slotStyle = (vertex) => ({
    left: `${(Number(vertex.x) / 200) * 100}%`,
    top: `${(Number(vertex.y) / 180) * 100}%`,
});

const sideMid = (figure, sideId) => {
    const ends = sideEnds(figure, sideId);
    if (! ends) {
        return { x: 0, y: 0 };
    }
    const [a, b] = ends;
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const len = Math.hypot(dx, dy) || 1;

    return {
        x: (a.x + b.x) / 2 + (-dy / len) * 16,
        y: (a.y + b.y) / 2 + (dx / len) * 16,
    };
};

const vertexMap = (figure) => {
    const map = {};
    (figure.vertices || []).forEach((vertex) => {
        map[vertex.id] = vertex;
    });

    return map;
};

const sidePoint = (figure, sideId, end) => sideEnds(figure, sideId)?.[end] || { x: 0, y: 0 };

const sideTicks = (figure) => {
    const lines = [];
    (figure.sides || []).forEach((side) => {
        ticksFor(figure, side).forEach((line, index) => {
            lines.push({ key: `${side}-${index}`, ...line });
        });
    });

    return lines;
};

const sideEnds = (figure, sideId) => {
    const map = vertexMap(figure);
    const a = map[sideId[0]];
    const b = map[sideId[1]];
    if (! a || ! b) {
        return null;
    }

    return [a, b];
};

const tickLine = (figure, sideId, offset) => {
    const ends = sideEnds(figure, sideId);
    if (! ends) {
        return null;
    }
    const [a, b] = ends;
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const len = Math.hypot(dx, dy) || 1;
    const px = -dy / len;
    const py = dx / len;
    const mx = (a.x + b.x) / 2 + (dx / len) * offset;
    const my = (a.y + b.y) / 2 + (dy / len) * offset;

    return { x1: mx + px * 8, y1: my + py * 8, x2: mx - px * 8, y2: my - py * 8 };
};

const anglePath = (figure, vertexId) => {
    const map = vertexMap(figure);
    const vertex = map[vertexId];
    if (! vertex) {
        return '';
    }
    const others = (figure.vertices || []).filter((item) => item.id !== vertexId);
    if (others.length < 2) {
        return '';
    }
    const units = others.slice(0, 2).map((point) => {
        const dx = point.x - vertex.x;
        const dy = point.y - vertex.y;
        const len = Math.hypot(dx, dy) || 1;

        return [dx / len, dy / len];
    });
    const radius = 18;
    const start = [vertex.x + units[0][0] * radius, vertex.y + units[0][1] * radius];
    const end = [vertex.x + units[1][0] * radius, vertex.y + units[1][1] * radius];
    const bend = [
        vertex.x + (units[0][0] + units[1][0]) * radius * 0.72,
        vertex.y + (units[0][1] + units[1][1]) * radius * 0.72,
    ];

    return `M ${start[0]} ${start[1]} Q ${bend[0]} ${bend[1]} ${end[0]} ${end[1]}`;
};

const labelPos = (figure, vertex) => {
    const others = (figure.vertices || []).filter((item) => item.id !== vertex.id);
    const pullX = others.reduce((sum, item) => sum + item.x, 0) / (others.length || 1);
    const pullY = others.reduce((sum, item) => sum + item.y, 0) / (others.length || 1);
    const dx = vertex.x - pullX;
    const dy = vertex.y - pullY;
    const len = Math.hypot(dx, dy) || 1;

    return { x: vertex.x + (dx / len) * 14, y: vertex.y + (dy / len) * 14 };
};

const partMark = (partId) => placed[partId] || '';

const tickCountFor = (mark) => {
    const fromPlay = play.value.tick_counts?.[mark];
    if (fromPlay) {
        return Number(fromPlay);
    }

    return { 5: 1, 6: 2, 7: 3 }[mark] || 0;
};

const ticksFor = (figure, sideId) => {
    const count = tickCountFor(partMark(sideId));
    if (! count) {
        return [];
    }
    const lines = [];
    const spread = (count - 1) * 6;
    for (let index = 0; index < count; index += 1) {
        const line = tickLine(figure, sideId, -spread / 2 + index * 6);
        if (line) {
            lines.push(line);
        }
    }

    return lines;
};

const sideLabel = (figure, sideId) => {
    const text = figure.lengths?.[sideId];
    const ends = sideEnds(figure, sideId);
    if (! text || ! ends) {
        return null;
    }
    const [a, b] = ends;
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const len = Math.hypot(dx, dy) || 1;

    return {
        x: (a.x + b.x) / 2 + (-dy / len) * 14,
        y: (a.y + b.y) / 2 + (dx / len) * 14,
        text,
    };
};

const tapPart = (partId, kind) => {
    if (stepOk.value || allCorrect.value || currentStep.value?.type !== 'marks') {
        return;
    }
    if (! tool.value) {
        message.value = 'Choose a mark first, then tap the part.';
        return;
    }
    const selected = (currentStep.value.tools || []).find((item) => item.id === tool.value);
    if (selected && selected.kind !== kind) {
        message.value = selected.kind === 'angle' ? 'The angle mark goes on a corner.' : 'A length mark goes on a side.';
        return;
    }
    if (placed[partId] === tool.value) {
        delete placed[partId];
    } else {
        placed[partId] = tool.value;
    }
    message.value = '';
    cellResults.value = {};
};

const submit = async () => {
    const step = currentStep.value;
    if (! step || submitting.value || stepOk.value) {
        return;
    }
    const values = {};
    if (step.type === 'order' || step.type === 'align') {
        const mapped = mappedLetters();
        if (mapped.some((letter) => ! letter)) {
            message.value = 'Put a letter in every vertex circle.';
            return;
        }
        values.letters = mapped;
    } else if (step.type === 'marks') {
        values.placed = { ...placed };
    } else if (step.type === 'table') {
        if (rows.value.some((row) => ! row.parts || ! row.reason || ! row.type)) {
            message.value = 'Fill every cell in the table.';
            return;
        }
        values.rows = rows.value.map((row) => ({ ...row }));
    } else if (step.type === 'case') {
        if (! caseChoice.value) {
            message.value = 'Choose the case.';
            return;
        }
        values.case = caseChoice.value;
    }
    submitting.value = true;
    try {
        const { data } = await axios.post(props.check_url, {
            exercise_id: props.exercise.id,
            step_key: step.key,
            values,
        }, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        });
        cellResults.value = data.cell_results || {};
        message.value = data.message || '';
        stepOk.value = !! data.ok;
        if (data.all_ok) {
            allCorrect.value = true;
            summary.value = data.summary || null;
        }
    } catch (error) {
        message.value = error?.response?.data?.message || 'Could not check. Try again.';
        stepOk.value = false;
    } finally {
        submitting.value = false;
    }
};

const continueStep = () => {
    if (! stepOk.value || allCorrect.value) {
        return;
    }
    if (currentIndex.value < steps.value.length - 1) {
        currentIndex.value += 1;
        message.value = '';
        stepOk.value = false;
        cellResults.value = {};
        caseChoice.value = '';
    }
};
</script>

<template>
    <div class="mt-3 space-y-4">
        <ol class="flex flex-wrap gap-2">
            <li
                v-for="(step, index) in steps"
                :key="step.key"
                class="rounded-full px-2.5 py-1 text-xs font-bold"
                :class="index < currentIndex || allCorrect
                    ? 'bg-emerald-100 text-emerald-900'
                    : index === currentIndex
                        ? 'bg-indigo-700 text-white'
                        : 'bg-slate-100 text-slate-500'"
            >
                {{ index + 1 }}. {{ step.title }}
            </li>
        </ol>

        <p v-if="givens.length" class="text-sm text-slate-700">
            <span class="font-semibold text-slate-900">Given. </span>{{ givens.join(' · ') }}
        </p>

        <div v-if="figures.length" class="grid gap-3 sm:grid-cols-2">
            <div
                v-for="figure in figures"
                :key="figure.id"
                class="rounded-lg border border-slate-200 bg-slate-50 p-2"
            >
                <p class="text-center text-xs font-bold uppercase tracking-wide text-slate-500">△ {{ figure.name }}</p>
                <svg viewBox="0 0 200 180" class="mx-auto h-44 w-full max-w-xs">
                    <line
                        v-for="side in figure.sides"
                        :key="side"
                        :x1="sidePoint(figure, side, 0).x"
                        :y1="sidePoint(figure, side, 0).y"
                        :x2="sidePoint(figure, side, 1).x"
                        :y2="sidePoint(figure, side, 1).y"
                        stroke="#0f172a"
                        stroke-width="2.5"
                        stroke-linecap="round"
                    />
                    <line
                        v-for="side in figure.sides"
                        :key="`${side}-hit`"
                        :x1="sidePoint(figure, side, 0).x"
                        :y1="sidePoint(figure, side, 0).y"
                        :x2="sidePoint(figure, side, 1).x"
                        :y2="sidePoint(figure, side, 1).y"
                        stroke="transparent"
                        stroke-width="16"
                        class="cursor-pointer"
                        @click="tapPart(side, 'side')"
                    />
                    <line
                        v-for="tick in sideTicks(figure)"
                        :key="tick.key"
                        :x1="tick.x1"
                        :y1="tick.y1"
                        :x2="tick.x2"
                        :y2="tick.y2"
                        stroke="#0f766e"
                        stroke-width="2"
                    />
                    <path
                        v-for="vertex in figure.vertices"
                        :key="`${vertex.id}-arc`"
                        :d="partMark(vertex.id) === 'angle' ? anglePath(figure, vertex.id) : ''"
                        fill="none"
                        stroke="#b45309"
                        stroke-width="2"
                    />
                    <circle
                        v-for="vertex in figure.vertices"
                        :key="`${vertex.id}-hit`"
                        :cx="vertex.x"
                        :cy="vertex.y"
                        r="14"
                        fill="transparent"
                        class="cursor-pointer"
                        @click="tapPart(vertex.id, 'angle')"
                    />
                    <text
                        v-for="side in figure.sides.filter((id) => sideLabel(figure, id))"
                        :key="`${side}-len`"
                        :x="sideLabel(figure, side).x"
                        :y="sideLabel(figure, side).y"
                        text-anchor="middle"
                        class="fill-teal-800 text-[11px] font-bold"
                    >
                        {{ sideLabel(figure, side).text }}
                    </text>
                    <text
                        v-for="vertex in figure.vertices"
                        :key="vertex.id"
                        :x="labelPos(figure, vertex).x"
                        :y="labelPos(figure, vertex).y"
                        text-anchor="middle"
                        dominant-baseline="middle"
                        class="fill-slate-900 text-[13px] font-bold"
                    >
                        {{ vertex.id }}
                    </text>
                </svg>
            </div>
        </div>

        <div v-if="currentStep" class="rounded-xl border border-slate-200 bg-white p-3">
            <p class="text-sm font-semibold text-slate-900">{{ currentStep.prompt }}</p>

            <div v-if="currentStep.type === 'order' || currentStep.type === 'align'" class="mt-3 space-y-3">
                <p class="text-sm font-bold text-slate-900">
                    △ {{ currentStep.left || drawnHome?.name }} ≅ △
                    <span class="font-mono tracking-widest">{{ mappedLetters().join('') || '???' }}</span>
                </p>

                <div v-if="drawnHome" class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-2">
                        <p class="text-center text-xs font-bold uppercase tracking-wide text-slate-500">
                            Draw △ {{ drawnHome.name }} as given
                        </p>
                        <div class="relative mx-auto h-52 w-full max-w-sm">
                            <svg viewBox="0 0 200 180" class="h-full w-full">
                                <line
                                    v-for="side in drawnHome.sides"
                                    :key="`home-${side}`"
                                    :x1="sidePoint(drawnHome, side, 0).x"
                                    :y1="sidePoint(drawnHome, side, 0).y"
                                    :x2="sidePoint(drawnHome, side, 1).x"
                                    :y2="sidePoint(drawnHome, side, 1).y"
                                    stroke="#0f172a"
                                    stroke-width="2.5"
                                    stroke-linecap="round"
                                />
                                <text
                                    v-for="side in drawnHome.sides.filter((id) => sideLabel(drawnHome, id))"
                                    :key="`home-len-${side}`"
                                    :x="sideLabel(drawnHome, side).x"
                                    :y="sideLabel(drawnHome, side).y"
                                    text-anchor="middle"
                                    class="fill-teal-800 text-[11px] font-bold"
                                >
                                    {{ sideLabel(drawnHome, side).text }}
                                </text>
                            </svg>
                            <span
                                v-for="vertex in drawnHome.vertices"
                                :key="`home-${vertex.id}`"
                                class="absolute flex h-8 w-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-slate-700 bg-white text-sm font-extrabold text-slate-900"
                                :style="slotStyle(vertex)"
                            >
                                {{ vertex.id }}
                            </span>
                        </div>
                    </div>

                    <div class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-2">
                        <p class="text-center text-xs font-bold uppercase tracking-wide text-indigo-800">
                            Draw the second triangle — type {{ allowedLetters.join(', ') || 'the letters' }}
                        </p>
                        <div class="relative mx-auto h-52 w-full max-w-sm">
                            <svg viewBox="0 0 200 180" class="h-full w-full">
                                <line
                                    v-for="side in drawnCopy.sides"
                                    :key="`copy-${side}`"
                                    :x1="sidePoint(drawnCopy, side, 0).x"
                                    :y1="sidePoint(drawnCopy, side, 0).y"
                                    :x2="sidePoint(drawnCopy, side, 1).x"
                                    :y2="sidePoint(drawnCopy, side, 1).y"
                                    stroke="#312e81"
                                    stroke-width="2.5"
                                    stroke-linecap="round"
                                />
                                <text
                                    v-for="row in correspondenceRows"
                                    :key="`copy-side-${row.side}`"
                                    :x="sideMid(drawnCopy, row.side).x"
                                    :y="sideMid(drawnCopy, row.side).y"
                                    text-anchor="middle"
                                    class="fill-indigo-800 text-[11px] font-bold"
                                >
                                    {{ row.other || '' }}
                                </text>
                            </svg>
                            <input
                                v-for="vertex in drawnCopy.vertices"
                                :key="`copy-${vertex.id}`"
                                :value="vertexSlots[vertex.id] || ''"
                                maxlength="1"
                                autocomplete="off"
                                class="absolute h-8 w-8 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-indigo-600 bg-white text-center text-sm font-extrabold uppercase text-indigo-900"
                                :style="slotStyle(vertex)"
                                :disabled="stepOk"
                                :aria-label="`Letter matching ${vertex.id}`"
                                @input="(event) => { typeVertex(vertex.id, event.target.value); event.target.value = vertexSlots[vertex.id] || ''; }"
                            >
                        </div>
                    </div>
                </div>

                <div v-if="correspondenceRows.length" class="overflow-x-auto rounded-md border border-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-2 py-1">On △ {{ drawnHome?.name }}</th>
                                <th class="px-2 py-1">Matching side</th>
                                <th class="px-2 py-1">Length</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in correspondenceRows" :key="`corr-${row.side}`" class="border-t border-slate-100">
                                <td class="px-2 py-1 font-semibold">{{ row.side }}</td>
                                <td class="px-2 py-1 font-mono">{{ row.other || '?' }}</td>
                                <td class="px-2 py-1 text-teal-800">{{ row.length || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-else-if="currentStep.type === 'marks'" class="mt-3 flex flex-wrap gap-2">
                <button
                    v-for="item in currentStep.tools"
                    :key="item.id"
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm font-semibold"
                    :class="tool === item.id ? 'border-indigo-700 bg-indigo-700 text-white' : 'border-slate-300 text-slate-800'"
                    :disabled="stepOk"
                    @click="tool = item.id"
                >
                    {{ item.label }}
                </button>
                <p class="w-full text-xs text-slate-500">Choose a mark, then tap the side or corner. Tap again to remove it.</p>
            </div>

            <div v-else-if="currentStep.type === 'table'" class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-1 py-1">No</th>
                            <th class="px-1 py-1">Congruent parts</th>
                            <th class="px-1 py-1">Reason</th>
                            <th class="px-1 py-1">Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in rows" :key="index">
                            <td class="px-1 py-1 font-semibold">{{ index + 1 }}</td>
                            <td class="px-1 py-1">
                                <select v-model="row.parts" class="w-full rounded border-slate-300 text-sm" :disabled="stepOk">
                                    <option value="">Choose</option>
                                    <option v-for="choice in currentStep.part_choices" :key="choice" :value="choice">{{ choice }}</option>
                                </select>
                            </td>
                            <td class="px-1 py-1">
                                <select v-model="row.reason" class="w-full rounded border-slate-300 text-sm" :disabled="stepOk">
                                    <option value="">Choose</option>
                                    <option v-for="choice in currentStep.reason_choices" :key="choice" :value="choice">{{ choice }}</option>
                                </select>
                            </td>
                            <td class="px-1 py-1">
                                <select v-model="row.type" class="w-full rounded border-slate-300 text-sm" :disabled="stepOk">
                                    <option value="">Choose</option>
                                    <option v-for="choice in currentStep.type_choices" :key="choice" :value="choice">{{ choice }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else-if="currentStep.type === 'case'" class="mt-3 flex flex-wrap gap-2">
                <button
                    v-for="choice in currentStep.choices"
                    :key="choice"
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm font-bold"
                    :class="caseChoice === choice ? 'border-indigo-700 bg-indigo-700 text-white' : 'border-slate-300 text-slate-800'"
                    :disabled="stepOk"
                    @click="caseChoice = choice"
                >
                    {{ choice }}
                </button>
            </div>

            <p
                v-if="message"
                class="mt-3 text-sm"
                :class="stepOk ? 'text-emerald-800' : 'text-rose-800'"
            >
                {{ message }}
            </p>

            <section
                v-if="summary"
                class="mt-4 rounded-xl border-2 border-emerald-400 bg-emerald-50 p-3"
            >
                <h3 class="text-sm font-extrabold uppercase tracking-wide text-emerald-900">How it is proved</h3>
                <p class="mt-2 text-lg font-bold text-slate-900">{{ summary.statement }}</p>
                <p class="mt-1 text-sm leading-relaxed text-slate-800">{{ summary.because }}</p>
                <table class="mt-3 min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-emerald-900">
                        <tr>
                            <th class="px-2 py-1">No</th>
                            <th class="px-2 py-1">Congruent parts</th>
                            <th class="px-2 py-1">Reason</th>
                            <th class="px-2 py-1">Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in summary.rows" :key="index" class="border-t border-emerald-200">
                            <td class="px-2 py-1 font-semibold">{{ index + 1 }}</td>
                            <td class="px-2 py-1">{{ row.parts }}</td>
                            <td class="px-2 py-1">{{ row.reason }}</td>
                            <td class="px-2 py-1">{{ row.type }}</td>
                        </tr>
                        <tr class="border-t border-emerald-300 bg-white/70">
                            <td class="px-2 py-1 font-semibold" colspan="3">Conclusion</td>
                            <td class="px-2 py-1 font-bold text-emerald-900">{{ summary.case }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    v-if="!stepOk"
                    type="button"
                    class="rounded-md bg-indigo-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-800 disabled:opacity-50"
                    :disabled="submitting"
                    @click="submit"
                >
                    Check
                </button>
                <button
                    v-else-if="!allCorrect"
                    type="button"
                    class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800"
                    @click="continueStep"
                >
                    Continue
                </button>
                <button
                    v-if="allCorrect && canGoNext"
                    type="button"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-800"
                    @click="emit('next')"
                >
                    Next figure
                </button>
                <SeriesFinished v-else-if="allCorrect" :next-part="nextPart" :series-label="card.series_label" />
            </div>
        </div>
    </div>
</template>
