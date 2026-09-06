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
 * Standard Fig 5.14 numbering:
 * Top intersection on l: 1 NW, 2 NE, 3 SE, 4 SW
 * Bottom intersection on m: 5 NW, 6 NE, 7 SE, 8 SW
 *
 * Parallel lines l || m; transversal t meets them at 30° (not perpendicular).
 */
const ARC_R = 36;
const HIT_R = 56;
const LABEL_R = 24;
const TRANSVERSAL_DEG = 30;
const DEG = Math.PI / 180;

const yL = 130;
const yM = 270;
const topX = 100;
const tAngle = TRANSVERSAL_DEG * DEG;
// Screen coords: +x right, +y down. Transversal slopes down-right at 30° to horizontal.
const tdx = Math.cos(tAngle);
const tdy = Math.sin(tAngle);
const bottomX = topX + (yM - yL) * (tdx / tdy);

const intersections = {
    top: { x: topX, y: yL },
    bottom: { x: bottomX, y: yM },
};

// Ray angles via atan2(y, x). Increasing angle = clockwise in SVG y-down space.
const aEast = 0;
const aTUp = -tAngle; // up-right along t
const aWest = Math.PI;
const aTDown = Math.PI - tAngle; // down-left along t

/**
 * Wedge from start→end going clockwise (increasing atan2), matching angle regions:
 * 2 NE acute, 3 SE obtuse, 4 SW acute, 1 NW obtuse.
 */
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

/** Clockwise arc (SVG sweep=1) from a0 to a1. */
const arcPath = (cx, cy, r, a0, a1) => {
    const delta = normalizeDelta(a0, a1);
    const large = delta > Math.PI ? 1 : 0;
    const p0 = polar(cx, cy, r, a0);
    const p1 = polar(cx, cy, r, a1);

    return `M ${p0.x.toFixed(2)} ${p0.y.toFixed(2)} A ${r} ${r} 0 ${large} 1 ${p1.x.toFixed(2)} ${p1.y.toFixed(2)}`;
};

const sectorHitPath = (cx, cy, r, a0, a1) => {
    const delta = normalizeDelta(a0, a1);
    const large = delta > Math.PI ? 1 : 0;
    const p0 = polar(cx, cy, r, a0);
    const p1 = polar(cx, cy, r, a1);

    return `M ${cx.toFixed(2)} ${cy.toFixed(2)} L ${p0.x.toFixed(2)} ${p0.y.toFixed(2)} A ${r} ${r} 0 ${large} 1 ${p1.x.toFixed(2)} ${p1.y.toFixed(2)} Z`;
};

const labelPoint = (cx, cy, a0, a1) => {
    const mid = a0 + normalizeDelta(a0, a1) / 2;

    return polar(cx, cy, LABEL_R, mid);
};

const regions = angleDefs.map((def) => {
    const v = intersections[def.vertex];
    const { a0, a1 } = wedges[def.id];

    return {
        id: def.id,
        arc: arcPath(v.x, v.y, ARC_R, a0, a1),
        hit: sectorHitPath(v.x, v.y, HIT_R, a0, a1),
        label: labelPoint(v.x, v.y, a0, a1),
    };
});

const linePad = 70;
const tStart = {
    x: intersections.top.x - tdx * linePad,
    y: intersections.top.y - tdy * linePad,
};
const tEnd = {
    x: intersections.bottom.x + tdx * linePad,
    y: intersections.bottom.y + tdy * linePad,
};

const viewWidth = Math.max(420, Math.ceil(bottomX + 90));
const viewBox = `0 0 ${viewWidth} 400`;

const fillFor = (id) => {
    if (props.revealed && id === props.correct) {
        return 'rgba(187, 247, 208, 0.55)';
    }
    if (props.revealed && id === props.selected && id !== props.correct) {
        return 'rgba(254, 202, 202, 0.55)';
    }
    if (!props.revealed && id === props.selected) {
        return 'rgba(199, 210, 254, 0.45)';
    }
    if (id === props.highlight) {
        return 'rgba(253, 230, 138, 0.5)';
    }
    return 'transparent';
};

const strokeFor = (id) => {
    if (props.revealed && id === props.correct) {
        return '#059669';
    }
    if (props.revealed && id === props.selected && id !== props.correct) {
        return '#e11d48';
    }
    if (id === props.highlight) {
        return '#d97706';
    }
    if (!props.revealed && id === props.selected) {
        return '#4f46e5';
    }
    return '#0f172a';
};

const strokeWidthFor = (id) => {
    if (id === props.highlight || id === props.selected || (props.revealed && id === props.correct)) {
        return 3.5;
    }
    return 2;
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
            class="mx-auto h-auto w-full max-w-md select-none"
            role="img"
            aria-label="Two parallel lines cut by a transversal at 30 degrees with angles 1 to 8"
        >
            <!-- Line l -->
            <line x1="40" :y1="yL" :x2="viewWidth - 40" :y2="yL" stroke="#0f172a" stroke-width="3" />
            <!-- Line m -->
            <line x1="40" :y1="yM" :x2="viewWidth - 40" :y2="yM" stroke="#0f172a" stroke-width="3" />
            <!-- Transversal t at 30° -->
            <line
                :x1="tStart.x"
                :y1="tStart.y"
                :x2="tEnd.x"
                :y2="tEnd.y"
                stroke="#0f172a"
                stroke-width="3"
            />

            <text x="22" :y="yL - 8" font-size="14" font-weight="700" fill="#334155" font-family="Georgia, 'Times New Roman', serif">l</text>
            <text x="22" :y="yM - 8" font-size="14" font-weight="700" fill="#334155" font-family="Georgia, 'Times New Roman', serif">m</text>
            <text
                :x="tStart.x + 10"
                :y="tStart.y - 4"
                font-size="14"
                font-weight="700"
                fill="#334155"
                font-family="Georgia, 'Times New Roman', serif"
            >t</text>

            <path
                v-for="region in regions"
                :key="`fill-${region.id}`"
                :d="region.hit"
                :fill="fillFor(region.id)"
                stroke="none"
                class="pointer-events-none"
            />

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

            <text
                v-for="region in regions"
                :key="`label-${region.id}`"
                :x="region.label.x"
                :y="region.label.y"
                text-anchor="middle"
                dominant-baseline="middle"
                font-size="15"
                font-weight="700"
                fill="#0f172a"
                font-family="Georgia, 'Times New Roman', serif"
                class="pointer-events-none"
            >
                {{ region.id }}
            </text>
        </svg>
        <p class="mt-1 px-1 text-center text-[11px] text-slate-500">
            Angles 1–4 at line <strong>l</strong>, angles 5–8 at line <strong>m</strong>, transversal <strong>t</strong> at 30°.
        </p>
    </div>
</template>
