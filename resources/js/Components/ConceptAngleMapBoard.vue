<script setup>
import { computed } from 'vue';

const props = defineProps({
    highlight: { type: Number, default: null },
    selected: { type: Number, default: null },
    correct: { type: Number, default: null },
    revealed: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);

/**
 * Parallel lines l || m; transversal t at 30° to them.
 * Acute angles (2,4,6,8) = 30°; obtuse (1,3,5,7) = 150°.
 * Numbers sit on white discs so they stay crisp over lines/arcs.
 */
const HIT_R = 64;
const TRANSVERSAL_DEG = 30;
const DEG = Math.PI / 180;
const ACUTE_IDS = new Set([2, 4, 6, 8]);

const yL = 120;
const yM = 260;
const topX = 95;
const tAngle = TRANSVERSAL_DEG * DEG;
const tdx = Math.cos(tAngle);
const tdy = Math.sin(tAngle);
const bottomX = topX + (yM - yL) * (tdx / tdy);

const intersections = {
    top: { x: topX, y: yL },
    bottom: { x: bottomX, y: yM },
};

const aEast = 0;
const aTUp = -tAngle;
const aWest = Math.PI;
const aTDown = Math.PI - tAngle;

const wedges = {
    2: { a0: aTUp, a1: aEast },
    3: { a0: aEast, a1: aTDown },
    4: { a0: aTDown, a1: aWest },
    1: { a0: aWest, a1: aTUp + 2 * Math.PI },
    6: { a0: aTUp, a1: aEast },
    7: { a0: aEast, a1: aTDown },
    8: { a0: aTDown, a1: aWest },
    5: { a0: aWest, a1: aTUp + 2 * Math.PI },
};

const angleDefs = [
    { id: 1, vertex: 'top' },
    { id: 2, vertex: 'top' },
    { id: 3, vertex: 'top' },
    { id: 4, vertex: 'top' },
    { id: 5, vertex: 'bottom' },
    { id: 6, vertex: 'bottom' },
    { id: 7, vertex: 'bottom' },
    { id: 8, vertex: 'bottom' },
];

const polar = (cx, cy, r, angle) => ({
    x: cx + r * Math.cos(angle),
    y: cy + r * Math.sin(angle),
});

const normalizeDelta = (a0, a1) => {
    let delta = a1 - a0;
    while (delta < 0) {
        delta += 2 * Math.PI;
    }
    while (delta >= 2 * Math.PI) {
        delta -= 2 * Math.PI;
    }

    return delta;
};

const arcPath = (cx, cy, r, a0, a1) => {
    const delta = normalizeDelta(a0, a1);
    const large = delta > Math.PI ? 1 : 0;
    const p0 = polar(cx, cy, r, a0);
    const p1 = polar(cx, cy, r, a1);

    return `M ${p0.x.toFixed(1)} ${p0.y.toFixed(1)} A ${r} ${r} 0 ${large} 1 ${p1.x.toFixed(1)} ${p1.y.toFixed(1)}`;
};

const sectorHitPath = (cx, cy, r, a0, a1) => {
    const delta = normalizeDelta(a0, a1);
    const large = delta > Math.PI ? 1 : 0;
    const p0 = polar(cx, cy, r, a0);
    const p1 = polar(cx, cy, r, a1);

    return `M ${cx.toFixed(1)} ${cy.toFixed(1)} L ${p0.x.toFixed(1)} ${p0.y.toFixed(1)} A ${r} ${r} 0 ${large} 1 ${p1.x.toFixed(1)} ${p1.y.toFixed(1)} Z`;
};

const arcRadiusFor = (id) => (ACUTE_IDS.has(id) ? 28 : 46);
/** Acute: number sits just outside the small arc. Obtuse: inside near the arc. */
const labelRadiusFor = (id) => (ACUTE_IDS.has(id) ? 48 : 30);

const regions = angleDefs.map((def) => {
    const v = intersections[def.vertex];
    const { a0, a1 } = wedges[def.id];
    const mid = a0 + normalizeDelta(a0, a1) / 2;
    const label = polar(v.x, v.y, labelRadiusFor(def.id), mid);

    return {
        id: def.id,
        acute: ACUTE_IDS.has(def.id),
        arc: arcPath(v.x, v.y, arcRadiusFor(def.id), a0, a1),
        hit: sectorHitPath(v.x, v.y, HIT_R, a0, a1),
        label,
    };
});

const linePad = 55;
const tStart = {
    x: intersections.top.x - tdx * linePad,
    y: intersections.top.y - tdy * linePad,
};
const tEnd = {
    x: intersections.bottom.x + tdx * linePad,
    y: intersections.bottom.y + tdy * linePad,
};

const viewWidth = Math.ceil(Math.max(bottomX + 80, 400));
const viewHeight = 360;
const viewBox = `0 0 ${viewWidth} ${viewHeight}`;

const fillFor = (id) => {
    if (props.revealed && id === props.correct) {
        return 'rgba(187, 247, 208, 0.55)';
    }
    if (props.revealed && id === props.selected && id !== props.correct) {
        return 'rgba(254, 202, 202, 0.55)';
    }
    if (!props.revealed && id === props.selected) {
        return 'rgba(199, 210, 254, 0.5)';
    }
    if (id === props.highlight) {
        return 'rgba(253, 230, 138, 0.55)';
    }
    return 'transparent';
};

const strokeFor = (id) => {
    if (props.revealed && id === props.correct) {
        return '#047857';
    }
    if (props.revealed && id === props.selected && id !== props.correct) {
        return '#be123c';
    }
    if (id === props.highlight) {
        return '#b45309';
    }
    if (!props.revealed && id === props.selected) {
        return '#4338ca';
    }
    return '#0f172a';
};

const strokeWidthFor = (id) => {
    if (id === props.highlight || id === props.selected || (props.revealed && id === props.correct)) {
        return 3.25;
    }
    return 2.25;
};

const badgeStrokeFor = (id) => {
    if (props.revealed && id === props.correct) {
        return '#047857';
    }
    if (props.revealed && id === props.selected && id !== props.correct) {
        return '#be123c';
    }
    if (id === props.highlight) {
        return '#b45309';
    }
    if (!props.revealed && id === props.selected) {
        return '#4338ca';
    }
    return '#334155';
};

const onSelect = (id) => {
    if (props.disabled || props.revealed) {
        return;
    }
    emit('select', id);
};

const hint = computed(() => {
    if (props.highlight) {
        return `Highlighted: ∠${props.highlight}. Tap the matching angle.`;
    }
    return 'Tap an angle on the figure.';
});
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-2">
        <p class="mb-1 px-1 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            {{ hint }}
        </p>
        <svg
            :viewBox="viewBox"
            class="mx-auto h-auto w-full max-w-lg select-none"
            role="img"
            aria-label="Two parallel lines cut by a transversal at 30 degrees with angles 1 to 8"
            text-rendering="geometricPrecision"
            shape-rendering="geometricPrecision"
        >
            <!-- Soft highlight wedges (behind lines) -->
            <path
                v-for="region in regions"
                :key="`fill-${region.id}`"
                :d="region.hit"
                :fill="fillFor(region.id)"
                stroke="none"
                class="pointer-events-none"
            />

            <!-- Parallel lines + transversal -->
            <line
                x1="36"
                :y1="yL"
                :x2="viewWidth - 36"
                :y2="yL"
                stroke="#0f172a"
                stroke-width="3"
                stroke-linecap="square"
            />
            <line
                x1="36"
                :y1="yM"
                :x2="viewWidth - 36"
                :y2="yM"
                stroke="#0f172a"
                stroke-width="3"
                stroke-linecap="square"
            />
            <line
                :x1="tStart.x"
                :y1="tStart.y"
                :x2="tEnd.x"
                :y2="tEnd.y"
                stroke="#0f172a"
                stroke-width="3"
                stroke-linecap="square"
            />

            <text
                x="20"
                :y="yL - 10"
                font-size="16"
                font-weight="700"
                fill="#0f172a"
                font-family="ui-sans-serif, system-ui, sans-serif"
            >l</text>
            <text
                x="20"
                :y="yM - 10"
                font-size="16"
                font-weight="700"
                fill="#0f172a"
                font-family="ui-sans-serif, system-ui, sans-serif"
            >m</text>
            <text
                :x="tStart.x + 8"
                :y="tStart.y - 6"
                font-size="16"
                font-weight="700"
                fill="#0f172a"
                font-family="ui-sans-serif, system-ui, sans-serif"
            >t</text>

            <!-- Angle arcs -->
            <path
                v-for="region in regions"
                :key="`arc-${region.id}`"
                :d="region.arc"
                fill="none"
                :stroke="strokeFor(region.id)"
                :stroke-width="strokeWidthFor(region.id)"
                stroke-linecap="round"
                class="pointer-events-none"
            />

            <!-- Tap targets -->
            <path
                v-for="region in regions"
                :key="`hit-${region.id}`"
                :d="region.hit"
                fill="transparent"
                stroke="transparent"
                class="cursor-pointer"
                :class="{ 'pointer-events-none': disabled || revealed }"
                @click="onSelect(region.id)"
            />

            <!-- Crisp number badges -->
            <g v-for="region in regions" :key="`label-${region.id}`" class="pointer-events-none">
                <circle
                    :cx="region.label.x"
                    :cy="region.label.y"
                    r="12"
                    fill="#ffffff"
                    :stroke="badgeStrokeFor(region.id)"
                    stroke-width="1.75"
                />
                <text
                    :x="region.label.x"
                    :y="region.label.y"
                    text-anchor="middle"
                    dominant-baseline="central"
                    font-size="15"
                    font-weight="800"
                    fill="#0f172a"
                    font-family="ui-sans-serif, system-ui, sans-serif"
                >
                    {{ region.id }}
                </text>
            </g>
        </svg>
        <p class="mt-1 px-1 text-center text-[11px] text-slate-500">
            Angles 1–4 at line <strong>l</strong>, angles 5–8 at line <strong>m</strong>, transversal <strong>t</strong> at 30°.
        </p>
    </div>
</template>
