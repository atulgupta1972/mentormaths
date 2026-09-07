<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    start: { type: Number, default: 12 },
    selected: { type: Number, default: null },
    correct: { type: Number, default: null },
    revealed: { type: Boolean, default: false },
    interactive: { type: Boolean, default: true },
    /** Force a demo turn on the clock: '1/4' | '1/2' | '3/4' | '1' | 'full' */
    demoTurn: { type: String, default: null },
    /** When false, show Angle: ? (for fill-degrees questions). */
    showDegrees: { type: Boolean, default: true },
    /** When false, show Turn: ? (for angle→turn MCQs). */
    showTurnLabel: { type: Boolean, default: true },
});

const emit = defineEmits(['select']);

const cx = 160;
const cy = 160;
const r = 118;
const faceOrder = [12, 3, 6, 9];
const arcRadius = r - 42;

const faces = [
    { id: 12, label: '12', angle: -90 },
    { id: 3, label: '3', angle: 0 },
    { id: 6, label: '6', angle: 90 },
    { id: 9, label: '9', angle: 180 },
];

const dragging = ref(false);
const dragDeg = ref(null);
const svgEl = ref(null);

const toRad = (deg) => (deg * Math.PI) / 180;

const pointOn = (deg, radius) => ({
    x: cx + radius * Math.cos(toRad(deg)),
    y: cy + radius * Math.sin(toRad(deg)),
});

const faceAngle = (id) => (faces.find((f) => f.id === id) || faces[0]).angle;

const nearestFace = (deg) => {
    let d = ((deg + 180) % 360) - 180;
    if (d < -180) {
        d += 360;
    }
    let best = faces[0];
    let bestDiff = 999;
    for (const face of faces) {
        let diff = Math.abs(d - face.angle);
        if (diff > 180) {
            diff = 360 - diff;
        }
        if (diff < bestDiff) {
            bestDiff = diff;
            best = face;
        }
    }
    return best.id;
};

const quartersFromTurn = (turn) => {
    const key = String(turn || '').trim().toLowerCase().replace(/\s+/g, '');
    const map = {
        '1/4': 1,
        '¼': 1,
        quarter: 1,
        '1/2': 2,
        '½': 2,
        half: 2,
        '3/4': 3,
        '¾': 3,
        '1': 4,
        full: 4,
        'fullturn': 4,
    };
    return map[key] ?? null;
};

const clockwiseQuarters = (from, to, treatSameAsFull = false) => {
    const a = faceOrder.indexOf(from);
    const b = faceOrder.indexOf(to);
    if (a < 0 || b < 0) {
        return 0;
    }
    const steps = (b - a + 4) % 4;
    if (steps === 0 && treatSameAsFull && from === to) {
        return 4;
    }
    return steps;
};

const faceAfterQuarters = (from, quarters) => {
    const a = faceOrder.indexOf(from);
    if (a < 0) {
        return from;
    }
    return faceOrder[(a + (quarters % 4)) % 4];
};

const demoQuarters = computed(() => quartersFromTurn(props.demoTurn));
const isDemo = computed(() => demoQuarters.value != null && demoQuarters.value > 0);

const activeFace = computed(() => {
    if (isDemo.value) {
        return faceAfterQuarters(props.start ?? 12, demoQuarters.value);
    }
    if (props.revealed && props.correct) {
        return props.correct;
    }
    if (props.selected != null) {
        return props.selected;
    }
    return props.start ?? 12;
});

const handDeg = computed(() => {
    if (dragging.value && dragDeg.value != null) {
        return dragDeg.value;
    }
    if (isDemo.value && demoQuarters.value === 4) {
        // Full turn: hand back at start, arc still shows almost-full circle.
        return faceAngle(props.start ?? 12);
    }
    return faceAngle(activeFace.value);
});

const handTip = computed(() => pointOn(handDeg.value, r - 26));
const startTip = computed(() => pointOn(faceAngle(props.start ?? 12), r - 26));

const turnReadout = computed(() => {
    if (isDemo.value) {
        const map = {
            1: { turn: '1/4', degrees: 90 },
            2: { turn: '1/2', degrees: 180 },
            3: { turn: '3/4', degrees: 270 },
            4: { turn: '1', degrees: 360 },
        };
        return map[demoQuarters.value] || map[1];
    }
    const from = props.start ?? 12;
    const to = activeFace.value;
    const wantFull = props.correct === from;
    const q = clockwiseQuarters(from, to, wantFull && props.selected === from);
    const map = {
        0: { turn: '0', degrees: 0 },
        1: { turn: '1/4', degrees: 90 },
        2: { turn: '1/2', degrees: 180 },
        3: { turn: '3/4', degrees: 270 },
        4: { turn: '1', degrees: 360 },
    };
    return map[q] || map[0];
});

/** Clockwise sweep degrees from start to current hand (or demo). */
const sweepDelta = computed(() => {
    if (isDemo.value) {
        return demoQuarters.value * 90;
    }
    const from = faceAngle(props.start ?? 12);
    let delta = handDeg.value - from;
    while (delta < 0) {
        delta += 360;
    }
    while (delta >= 360) {
        delta -= 360;
    }
    if (delta < 8 && turnReadout.value.degrees === 360) {
        return 359;
    }
    return delta;
});

const showSweep = computed(() => sweepDelta.value >= 8);

const sweepGeometry = computed(() => {
    const from = faceAngle(props.start ?? 12);
    const delta = sweepDelta.value;
    if (delta < 8) {
        return null;
    }
    const endDeg = from + delta;
    const start = pointOn(from, arcRadius);
    const end = pointOn(endDeg, arcRadius);
    const large = delta > 180 ? 1 : 0;
    const arcPath = `M ${start.x.toFixed(1)} ${start.y.toFixed(1)} A ${arcRadius} ${arcRadius} 0 ${large} 1 ${end.x.toFixed(1)} ${end.y.toFixed(1)}`;

    // Soft wedge so kids see the “slice” of the turn.
    const mid = pointOn(from + delta / 2, arcRadius * 0.55);
    const wedgePath = `M ${cx} ${cy} L ${start.x.toFixed(1)} ${start.y.toFixed(1)} A ${arcRadius} ${arcRadius} 0 ${large} 1 ${end.x.toFixed(1)} ${end.y.toFixed(1)} Z`;

    // Arrowhead pointing clockwise at arc end.
    const tip = end;
    const tangentDeg = endDeg + 90; // direction of travel for increasing angle
    const size = 16;
    const backX = tip.x - size * Math.cos(toRad(tangentDeg));
    const backY = tip.y - size * Math.sin(toRad(tangentDeg));
    const wing = size * 0.55;
    const left = {
        x: backX + wing * Math.cos(toRad(tangentDeg + 90)),
        y: backY + wing * Math.sin(toRad(tangentDeg + 90)),
    };
    const right = {
        x: backX + wing * Math.cos(toRad(tangentDeg - 90)),
        y: backY + wing * Math.sin(toRad(tangentDeg - 90)),
    };
    const arrowPath = `M ${tip.x.toFixed(1)} ${tip.y.toFixed(1)} L ${left.x.toFixed(1)} ${left.y.toFixed(1)} L ${right.x.toFixed(1)} ${right.y.toFixed(1)} Z`;

    return { arcPath, wedgePath, arrowPath, mid, endDeg };
});

const turnDisplay = computed(() => {
    if (! props.showTurnLabel) {
        return '?';
    }
    return turnReadout.value.turn === '1' ? 'full' : turnReadout.value.turn;
});

const degreesDisplay = computed(() => {
    if (! props.showDegrees) {
        return '?';
    }
    return `${turnReadout.value.degrees}`;
});

const fillFor = (id) => {
    if (isDemo.value && id === activeFace.value && demoQuarters.value < 4) {
        return '#bbf7d0';
    }
    if (props.revealed && id === props.correct) {
        return '#bbf7d0';
    }
    if (props.revealed && id === props.selected && id !== props.correct) {
        return '#fecaca';
    }
    if (! props.revealed && id === props.selected) {
        return '#c7d2fe';
    }
    if (id === props.start) {
        return '#fde68a';
    }
    return '#f8fafc';
};

const strokeFor = (id) => {
    if (isDemo.value && id === activeFace.value && demoQuarters.value < 4) {
        return '#059669';
    }
    if (props.revealed && id === props.correct) {
        return '#059669';
    }
    if (props.revealed && id === props.selected && id !== props.correct) {
        return '#e11d48';
    }
    if (id === props.start) {
        return '#d97706';
    }
    if (! props.revealed && id === props.selected) {
        return '#4f46e5';
    }
    return '#94a3b8';
};

const pointerAngle = (event) => {
    const svg = svgEl.value;
    if (! svg) {
        return 0;
    }
    const pt = svg.createSVGPoint();
    pt.x = event.clientX;
    pt.y = event.clientY;
    const ctm = svg.getScreenCTM();
    if (! ctm) {
        return 0;
    }
    const local = pt.matrixTransform(ctm.inverse());
    return (Math.atan2(local.y - cy, local.x - cx) * 180) / Math.PI;
};

const onSelect = (id) => {
    if (! props.interactive || props.revealed) {
        return;
    }
    emit('select', id);
};

const onPointerDown = (event) => {
    if (! props.interactive || props.revealed) {
        return;
    }
    dragging.value = true;
    dragDeg.value = pointerAngle(event);
    event.currentTarget.setPointerCapture?.(event.pointerId);
};

const onPointerMove = (event) => {
    if (! dragging.value) {
        return;
    }
    dragDeg.value = pointerAngle(event);
};

const onPointerUp = (event) => {
    if (! dragging.value) {
        return;
    }
    const deg = pointerAngle(event);
    const face = nearestFace(deg);
    dragging.value = false;
    dragDeg.value = null;
    emit('select', face);
};

const stopDrag = () => {
    dragging.value = false;
    dragDeg.value = null;
};

watch(() => props.start, () => {
    stopDrag();
});

onBeforeUnmount(() => {
    stopDrag();
});
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-2">
        <p class="mb-1 px-1 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            <span v-if="interactive">Drag the hand (or tap 12 · 3 · 6 · 9). Yellow = start. Curved arrow = turn.</span>
            <span v-else>Curved arrow shows the turn on the clock.</span>
        </p>

        <div class="mb-2 flex flex-wrap items-center justify-center gap-3 text-center text-sm">
            <span class="rounded-md bg-teal-50 px-2.5 py-1 font-semibold text-teal-900 ring-1 ring-teal-200">
                Turn: <span class="font-serif">{{ turnDisplay }}</span>
            </span>
            <span class="rounded-md bg-indigo-50 px-2.5 py-1 font-semibold text-indigo-900 ring-1 ring-indigo-200">
                Angle: {{ degreesDisplay }}<template v-if="showDegrees">°</template>
            </span>
        </div>

        <svg
            ref="svgEl"
            viewBox="0 0 320 340"
            class="mx-auto h-auto w-full max-w-sm select-none touch-none"
            role="img"
            aria-label="Clock showing a turn with a curved arrow"
            @pointerup="onPointerUp"
            @pointercancel="stopDrag"
        >
            <circle :cx="cx" :cy="cy" :r="r" fill="#fff" stroke="#0f172a" stroke-width="3" />

            <g v-if="showSweep && sweepGeometry" class="pointer-events-none">
                <path :d="sweepGeometry.wedgePath" fill="#99f6e4" opacity="0.45" />
                <path
                    :d="sweepGeometry.arcPath"
                    fill="none"
                    stroke="#0d9488"
                    stroke-width="5"
                    stroke-linecap="round"
                />
                <path :d="sweepGeometry.arrowPath" fill="#0f766e" stroke="#0f766e" stroke-width="1" />
            </g>

            <!-- ticks -->
            <g v-for="n in 12" :key="`tick-${n}`">
                <line
                    :x1="pointOn((n - 3) * 30, n % 3 === 0 ? r - 14 : r - 8).x"
                    :y1="pointOn((n - 3) * 30, n % 3 === 0 ? r - 14 : r - 8).y"
                    :x2="pointOn((n - 3) * 30, r).x"
                    :y2="pointOn((n - 3) * 30, r).y"
                    :stroke="n % 3 === 0 ? '#0f172a' : '#64748b'"
                    :stroke-width="n % 3 === 0 ? 3 : 2"
                />
            </g>

            <!-- face targets -->
            <g v-for="face in faces" :key="face.id">
                <circle
                    :cx="pointOn(face.angle, r - 36).x"
                    :cy="pointOn(face.angle, r - 36).y"
                    r="20"
                    :fill="fillFor(face.id)"
                    :stroke="strokeFor(face.id)"
                    stroke-width="2.5"
                    :class="interactive && !revealed ? 'cursor-pointer' : ''"
                    @click.stop="onSelect(face.id)"
                />
                <text
                    :x="pointOn(face.angle, r - 36).x"
                    :y="pointOn(face.angle, r - 36).y + 5"
                    text-anchor="middle"
                    font-size="15"
                    font-weight="700"
                    fill="#0f172a"
                    class="pointer-events-none"
                    font-family="Georgia, 'Times New Roman', serif"
                >
                    {{ face.label }}
                </text>
            </g>

            <!-- start ghost -->
            <line
                v-if="showSweep || selected != null || revealed || dragging || isDemo"
                :x1="cx"
                :y1="cy"
                :x2="startTip.x"
                :y2="startTip.y"
                stroke="#f59e0b"
                stroke-width="3"
                stroke-dasharray="5 4"
                opacity="0.85"
                class="pointer-events-none"
            />

            <!-- rotatable / demo hand -->
            <g
                :class="interactive && !revealed ? 'cursor-grab' : ''"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
            >
                <line
                    :x1="cx"
                    :y1="cy"
                    :x2="handTip.x"
                    :y2="handTip.y"
                    stroke="#4f46e5"
                    stroke-width="6"
                    stroke-linecap="round"
                />
                <circle :cx="cx" :cy="cy" r="10" fill="#4f46e5" />
                <circle :cx="handTip.x" :cy="handTip.y" r="8" fill="#312e81" />
                <line
                    v-if="interactive"
                    :x1="cx"
                    :y1="cy"
                    :x2="handTip.x"
                    :y2="handTip.y"
                    stroke="transparent"
                    stroke-width="28"
                    stroke-linecap="round"
                />
            </g>

            <text v-if="showDegrees" :x="cx" y="318" text-anchor="middle" font-size="12" fill="#64748b">
                ¼ → 90° · ½ → 180° · ¾ → 270° · full → 360°
            </text>
            <text v-else :x="cx" y="318" text-anchor="middle" font-size="12" fill="#64748b">
                Follow the curved arrow — how many degrees is this turn?
            </text>
        </svg>
    </div>
</template>
