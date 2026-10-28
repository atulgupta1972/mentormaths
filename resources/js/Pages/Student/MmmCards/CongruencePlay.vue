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
const rawFigures = computed(() => play.value.figures || []);
const figures = computed(() => rawFigures.value.map((figure) => alignedFigure(figure)));

const alignedFigure = (figure) => {
    const home = rawFigures.value[0];
    if (! figure?.rotated || ! home?.vertices?.length) {
        return figure;
    }
    const order = figure.rotated_order || (figure.vertices || []).map((vertex) => vertex.id);

    return {
        ...figure,
        vertices: order.map((id, index) => ({
            id,
            x: home.vertices[index]?.x,
            y: home.vertices[index]?.y,
        })),
    };
};
const givens = computed(() => play.value.givens || []);
const currentIndex = ref(0);
const currentStep = computed(() => steps.value[currentIndex.value] || null);
const letters = ref(['', '', '']);
const tool = ref('');
const placed = reactive({});
const vertexSlots = reactive({});
const sideSlots = reactive({});
const copyShaded = ref('');
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
const needsAngleShade = computed(() => Boolean(currentStep.value?.shade_angle));
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
        rows.value = Array.from({ length: step.row_count || 3 }, () => (
            step.type_choices?.length ? { parts: '', reason: '', type: '' } : { parts: '', reason: '' }
        ));
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
    Object.keys(sideSlots).forEach((key) => {
        delete sideSlots[key];
    });
    copyShaded.value = '';
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

const typeSide = (sideId, raw) => {
    if (stepOk.value || allCorrect.value || ! isAlignStep.value) {
        return;
    }
    const text = String(raw || '').replace(/[^\d.]/g, '').slice(0, 4);
    if (! text) {
        delete sideSlots[sideId];
    } else {
        sideSlots[sideId] = text;
    }
    message.value = '';
};

const filledSides = () => {
    const out = {};
    Object.keys(sideSlots).forEach((side) => {
        if (sideSlots[side]) {
            out[side] = sideSlots[side];
        }
    });

    return out;
};

const givenFigure = computed(() => figures.value[0] || drawnHome.value);

const modelCopy = computed(() => {
    const home = drawnHome.value;
    const order = summary.value?.copy_order || [];
    if (! home?.vertices?.length || order.length < home.vertices.length) {
        return null;
    }

    return {
        name: order.join(''),
        vertices: home.vertices.map((vertex, index) => ({
            id: order[index],
            x: vertex.x,
            y: vertex.y,
        })),
        sides: (home.sides || []).map((side) => {
            const first = home.vertices.findIndex((vertex) => vertex.id === side[0]);
            const second = home.vertices.findIndex((vertex) => vertex.id === side[1]);

            return `${order[first] || ''}${order[second] || ''}`;
        }),
    };
});

const tickCountOf = (sideId) => {
    const ticks = summary.value?.ticks || {};
    const reverse = `${sideId?.[1] || ''}${sideId?.[0] || ''}`;

    return Number(ticks[sideId] || ticks[reverse] || 0);
};

const modelTickLines = (figure) => {
    const lines = [];
    (figure?.sides || []).forEach((side) => {
        const count = tickCountOf(side);
        if (! count) {
            return;
        }
        const spread = (count - 1) * 6;
        for (let index = 0; index < count; index += 1) {
            const line = tickLine(figure, side, -spread / 2 + index * 6);
            if (line) {
                lines.push({ key: `${figure.name}-${side}-${index}`, ...line });
            }
        }
    });

    return lines;
};

const summaryAngles = (figure) => (summary.value?.angles || []).filter((id) => (
    (figure?.vertices || []).some((vertex) => vertex.id === id)
));

const slotStyle = (vertex) => ({
    left: `${(Number(vertex.x) / 200) * 100}%`,
    top: `${(Number(vertex.y) / 180) * 100}%`,
});

const sideSlotStyle = (figure, sideId) => {
    const mid = sideMid(figure, sideId);

    return {
        left: `${(Number(mid.x) / 200) * 100}%`,
        top: `${(Number(mid.y) / 180) * 100}%`,
    };
};

const inwardSlot = (figure, vertex) => {
    const others = (figure?.vertices || []).filter((item) => item.id !== vertex.id);
    const cx = others.reduce((sum, item) => sum + Number(item.x), 0) / (others.length || 1);
    const cy = others.reduce((sum, item) => sum + Number(item.y), 0) / (others.length || 1);

    return {
        left: `${((Number(vertex.x) * 0.58 + cx * 0.42) / 200) * 100}%`,
        top: `${((Number(vertex.y) * 0.58 + cy * 0.42) / 180) * 100}%`,
    };
};

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

const angleNeighbors = (figure, vertexId) => {
    const map = vertexMap(figure);
    const connected = (figure.sides || [])
        .filter((side) => side.includes(vertexId))
        .map((side) => (side[0] === vertexId ? side[1] : side[0]))
        .map((id) => map[id])
        .filter(Boolean);
    if (connected.length >= 2) {
        return connected.slice(0, 2);
    }

    return (figure.vertices || []).filter((item) => item.id !== vertexId).slice(0, 2);
};

const angleWedge = (figure, vertexId, radius = 26) => {
    const map = vertexMap(figure);
    const vertex = map[vertexId];
    const neighbors = angleNeighbors(figure, vertexId);
    if (! vertex || neighbors.length < 2) {
        return '';
    }
    const units = neighbors.map((point) => {
        const dx = point.x - vertex.x;
        const dy = point.y - vertex.y;
        const len = Math.hypot(dx, dy) || 1;

        return [dx / len, dy / len];
    });
    const start = [vertex.x + units[0][0] * radius, vertex.y + units[0][1] * radius];
    const end = [vertex.x + units[1][0] * radius, vertex.y + units[1][1] * radius];
    const sweep = units[0][0] * units[1][1] - units[0][1] * units[1][0] < 0 ? 0 : 1;

    return `M ${vertex.x} ${vertex.y} L ${start[0]} ${start[1]} A ${radius} ${radius} 0 0 ${sweep} ${end[0]} ${end[1]} Z`;
};

const givenAngles = (figure) => (figure?.shaded_angles || figure?.given_angles || []).map((id) => String(id));

const figureHasAngle = (figure, vertexId, extra = '') => (
    givenAngles(figure).includes(vertexId)
    || extra === vertexId
    || partMark(vertexId) === 'angle'
);

const shadeCopyAngle = (vertexId) => {
    if (stepOk.value || allCorrect.value || ! isAlignStep.value) {
        return;
    }
    copyShaded.value = copyShaded.value === vertexId ? '' : vertexId;
    message.value = '';
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
        values.sides = filledSides();
        const needSides = (drawnHome.value?.sides || []).filter((side) => drawnHome.value?.lengths?.[side]);
        if (needSides.some((side) => ! sideSlots[side])) {
            message.value = 'Write the side lengths on the second triangle.';
            return;
        }
        if (step.shade_angle) {
            if (! copyShaded.value) {
                message.value = 'Shade the equal angle on the second triangle.';
                return;
            }
            values.angle = copyShaded.value;
        }
    } else if (step.type === 'marks') {
        values.placed = { ...placed };
    } else if (step.type === 'table') {
        const needType = Boolean(step.type_choices?.length);
        if (rows.value.some((row) => ! row.parts || ! row.reason || (needType && ! row.type))) {
            message.value = 'Fill every cell in the table.';
            return;
        }
        if (step.case_choices?.length && ! caseChoice.value) {
            message.value = 'Choose the case.';
            return;
        }
        values.rows = rows.value.map((row) => ({ ...row }));
        if (caseChoice.value) {
            values.case = caseChoice.value;
        }
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

        <div v-if="givenFigure && !summary" class="max-w-sm rounded-lg border border-slate-200 bg-slate-50 p-2">
            <p class="text-center text-xs font-bold uppercase tracking-wide text-slate-500">① Diagram · △ {{ givenFigure.name }}</p>
            <svg viewBox="0 0 200 180" class="mx-auto h-36 w-full max-w-xs">
                <path
                    v-for="vertex in givenFigure.vertices.filter((item) => givenAngles(givenFigure).includes(item.id))"
                    :key="`${vertex.id}-given-wedge`"
                    :d="angleWedge(givenFigure, vertex.id)"
                    fill="#f59e0b"
                    fill-opacity="0.45"
                    stroke="#b45309"
                    stroke-width="1.5"
                />
                <line
                    v-for="side in givenFigure.sides"
                    :key="side"
                    :x1="sidePoint(givenFigure, side, 0).x"
                    :y1="sidePoint(givenFigure, side, 0).y"
                    :x2="sidePoint(givenFigure, side, 1).x"
                    :y2="sidePoint(givenFigure, side, 1).y"
                    stroke="#0f172a"
                    stroke-width="2.5"
                    stroke-linecap="round"
                />
                <text
                    v-for="side in givenFigure.sides.filter((id) => sideLabel(givenFigure, id))"
                    :key="`${side}-len`"
                    :x="sideLabel(givenFigure, side).x"
                    :y="sideLabel(givenFigure, side).y"
                    text-anchor="middle"
                    class="fill-teal-800 text-[11px] font-bold"
                >
                    {{ sideLabel(givenFigure, side).text }}
                </text>
                <text
                    v-for="vertex in givenFigure.vertices"
                    :key="vertex.id"
                    :x="labelPos(givenFigure, vertex).x"
                    :y="labelPos(givenFigure, vertex).y"
                    text-anchor="middle"
                    dominant-baseline="middle"
                    class="fill-slate-900 text-[13px] font-bold"
                >
                    {{ vertex.id }}
                </text>
            </svg>
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
                            Sketch △ {{ drawnHome.name }} as given
                        </p>
                        <div class="mx-auto w-full max-w-sm overflow-visible px-8 py-8">
                            <div class="relative h-56 overflow-visible">
                            <svg viewBox="0 0 200 180" class="h-full w-full overflow-visible">
                                <path
                                    v-for="vertex in drawnHome.vertices.filter((item) => givenAngles(drawnHome).includes(item.id))"
                                    :key="`home-wedge-${vertex.id}`"
                                    :d="angleWedge(drawnHome, vertex.id)"
                                    fill="#f59e0b"
                                    fill-opacity="0.5"
                                    stroke="#b45309"
                                    stroke-width="1.5"
                                />
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
                                class="absolute flex h-11 w-11 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-slate-700 bg-white text-lg font-extrabold leading-none text-slate-900"
                                :style="slotStyle(vertex)"
                            >
                                {{ vertex.id }}
                            </span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-2">
                        <p class="text-center text-xs font-bold uppercase tracking-wide text-indigo-800">
                            Second figure — write {{ allowedLetters.join(', ') || 'the letters' }}, side lengths{{ needsAngleShade ? ', shade the angle' : '' }}
                        </p>
                        <div class="mx-auto w-full max-w-sm overflow-visible px-8 py-8">
                            <div class="relative h-56 overflow-visible">
                            <svg viewBox="0 0 200 180" class="h-full w-full overflow-visible">
                                <path
                                    v-for="vertex in drawnCopy.vertices.filter((item) => copyShaded === item.id)"
                                    :key="`copy-wedge-${vertex.id}`"
                                    :d="angleWedge(drawnCopy, vertex.id, 34)"
                                    fill="#f59e0b"
                                    fill-opacity="0.55"
                                    stroke="#b45309"
                                    stroke-width="1.5"
                                />
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
                            </svg>
                            <label
                                v-for="side in drawnCopy.sides.filter((id) => drawnHome.lengths?.[id])"
                                :key="`copy-len-${side}`"
                                class="absolute z-10 flex h-8 w-14 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-md border-2 border-teal-600 bg-white"
                                :style="sideSlotStyle(drawnCopy, side)"
                            >
                                <input
                                    :value="sideSlots[side] || ''"
                                    inputmode="decimal"
                                    maxlength="4"
                                    placeholder="cm"
                                    class="h-full w-full rounded-md border-0 bg-transparent p-0 text-center text-sm font-bold text-teal-900 outline-none focus:ring-2 focus:ring-teal-400"
                                    :disabled="stepOk"
                                    :aria-label="`Length on ${side}`"
                                    @input="typeSide(side, $event.target.value)"
                                >
                            </label>
                            <button
                                v-for="vertex in drawnCopy.vertices"
                                v-show="needsAngleShade"
                                :key="`copy-shade-${vertex.id}`"
                                type="button"
                                class="absolute z-10 h-8 w-8 -translate-x-1/2 -translate-y-1/2 rounded-full border text-[10px] font-bold leading-none"
                                :class="copyShaded === vertex.id
                                    ? 'border-amber-700 bg-amber-400 text-amber-950'
                                    : 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100'"
                                :style="inwardSlot(drawnCopy, vertex)"
                                :disabled="stepOk"
                                :aria-label="`Shade the angle at ${vertex.id}`"
                                @click="shadeCopyAngle(vertex.id)"
                            >
                                ∠
                            </button>
                            <label
                                v-for="vertex in drawnCopy.vertices"
                                :key="`copy-${vertex.id}`"
                                class="absolute z-20 flex h-11 w-11 -translate-x-1/2 -translate-y-1/2 items-center justify-center overflow-visible rounded-full border-2 border-indigo-600 bg-white"
                                :style="slotStyle(vertex)"
                            >
                                <span
                                    class="pointer-events-none text-lg font-extrabold leading-none text-indigo-900"
                                    aria-hidden="true"
                                >
                                    {{ vertexSlots[vertex.id] || '' }}
                                </span>
                                <input
                                    :value="vertexSlots[vertex.id] || ''"
                                    maxlength="1"
                                    autocomplete="off"
                                    class="absolute inset-0 h-full w-full cursor-text rounded-full border-0 bg-transparent p-0 text-center text-lg font-extrabold uppercase leading-none text-transparent caret-indigo-900 outline-none focus:ring-2 focus:ring-indigo-400"
                                    :disabled="stepOk"
                                    :aria-label="`Letter matching ${vertex.id}`"
                                    @input="(event) => { typeVertex(vertex.id, event.target.value); event.target.value = vertexSlots[vertex.id] || ''; }"
                                >
                            </label>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div v-else-if="currentStep.type === 'table'" class="mt-3 space-y-3">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-1 py-1">No</th>
                                <th class="px-1 py-1">Equal parts</th>
                                <th class="px-1 py-1">Reason</th>
                                <th v-if="currentStep.type_choices?.length" class="px-1 py-1">Type</th>
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
                                <td v-if="currentStep.type_choices?.length" class="px-1 py-1">
                                    <select v-model="row.type" class="w-full rounded border-slate-300 text-sm" :disabled="stepOk">
                                        <option value="">Choose</option>
                                        <option v-for="choice in currentStep.type_choices" :key="choice" :value="choice">{{ choice }}</option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="currentStep.case_choices?.length" class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-slate-800">△ {{ currentStep.left || drawnHome?.name }} ≅ △ {{ mappedLetters().join('') || '???' }} by</span>
                    <button
                        v-for="choice in currentStep.case_choices"
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
                <h3 class="text-sm font-extrabold uppercase tracking-wide text-emerald-900">Write this in your copy</h3>
                <p class="mt-2 text-lg font-bold text-slate-900">{{ summary.statement }} by {{ summary.case }}</p>
                <p v-if="summary.advice" class="mt-1 text-sm font-medium text-amber-950">{{ summary.advice }}</p>
                <p class="mt-1 text-sm leading-relaxed text-slate-800">{{ summary.because }}</p>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div v-if="drawnHome" class="rounded-lg border border-emerald-200 bg-white p-2">
                        <p class="text-center text-xs font-bold uppercase tracking-wide text-emerald-800">△ {{ drawnHome.name }}</p>
                        <svg viewBox="0 0 200 180" class="mx-auto h-40 w-full">
                            <path
                                v-for="id in summaryAngles(drawnHome)"
                                :key="`sum-home-${id}`"
                                :d="angleWedge(drawnHome, id)"
                                fill="#f59e0b"
                                fill-opacity="0.45"
                                stroke="#b45309"
                                stroke-width="1.5"
                            />
                            <line
                                v-for="side in drawnHome.sides"
                                :key="`sum-home-${side}`"
                                :x1="sidePoint(drawnHome, side, 0).x"
                                :y1="sidePoint(drawnHome, side, 0).y"
                                :x2="sidePoint(drawnHome, side, 1).x"
                                :y2="sidePoint(drawnHome, side, 1).y"
                                stroke="#0f172a"
                                stroke-width="2.5"
                                stroke-linecap="round"
                            />
                            <line
                                v-for="tick in modelTickLines(drawnHome)"
                                :key="tick.key"
                                :x1="tick.x1"
                                :y1="tick.y1"
                                :x2="tick.x2"
                                :y2="tick.y2"
                                stroke="#0f766e"
                                stroke-width="2"
                            />
                            <text
                                v-for="vertex in drawnHome.vertices"
                                :key="`sum-home-l-${vertex.id}`"
                                :x="labelPos(drawnHome, vertex).x"
                                :y="labelPos(drawnHome, vertex).y"
                                text-anchor="middle"
                                dominant-baseline="middle"
                                class="fill-slate-900 text-[13px] font-bold"
                            >
                                {{ vertex.id }}
                            </text>
                        </svg>
                    </div>
                    <div v-if="modelCopy" class="rounded-lg border border-emerald-200 bg-white p-2">
                        <p class="text-center text-xs font-bold uppercase tracking-wide text-emerald-800">△ {{ modelCopy.name }}</p>
                        <svg viewBox="0 0 200 180" class="mx-auto h-40 w-full">
                            <path
                                v-for="id in summaryAngles(modelCopy)"
                                :key="`sum-copy-${id}`"
                                :d="angleWedge(modelCopy, id)"
                                fill="#f59e0b"
                                fill-opacity="0.45"
                                stroke="#b45309"
                                stroke-width="1.5"
                            />
                            <line
                                v-for="side in modelCopy.sides"
                                :key="`sum-copy-${side}`"
                                :x1="sidePoint(modelCopy, side, 0).x"
                                :y1="sidePoint(modelCopy, side, 0).y"
                                :x2="sidePoint(modelCopy, side, 1).x"
                                :y2="sidePoint(modelCopy, side, 1).y"
                                stroke="#0f172a"
                                stroke-width="2.5"
                                stroke-linecap="round"
                            />
                            <line
                                v-for="tick in modelTickLines(modelCopy)"
                                :key="tick.key"
                                :x1="tick.x1"
                                :y1="tick.y1"
                                :x2="tick.x2"
                                :y2="tick.y2"
                                stroke="#0f766e"
                                stroke-width="2"
                            />
                            <text
                                v-for="vertex in modelCopy.vertices"
                                :key="`sum-copy-l-${vertex.id}`"
                                :x="labelPos(modelCopy, vertex).x"
                                :y="labelPos(modelCopy, vertex).y"
                                text-anchor="middle"
                                dominant-baseline="middle"
                                class="fill-slate-900 text-[13px] font-bold"
                            >
                                {{ vertex.id }}
                            </text>
                        </svg>
                    </div>
                </div>

                <table class="mt-3 min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-emerald-900">
                        <tr>
                            <th class="px-2 py-1">No</th>
                            <th class="px-2 py-1">Equal parts</th>
                            <th class="px-2 py-1">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in summary.rows" :key="index" class="border-t border-emerald-200">
                            <td class="px-2 py-1 font-semibold">{{ index + 1 }}</td>
                            <td class="px-2 py-1">{{ row.parts }}</td>
                            <td class="px-2 py-1">{{ row.reason }}</td>
                        </tr>
                        <tr class="border-t border-emerald-300 bg-white/70">
                            <td class="px-2 py-1 font-semibold" colspan="2">△ {{ drawnHome?.name }} ≅ △ {{ summary.copy_order?.join('') }} by</td>
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
