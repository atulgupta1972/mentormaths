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
 * Textbook style (NCERT Fig. 5.27):
 * - parallel l, m with arrowheads
 * - transversal t as / at 45° to the horizontals
 * - small circular arcs only (no big discs / triangle wedges)
 * - crisp serif numbers beside the arcs
 *
 * Numbering (Fig 5.14): 1 NW, 2 NE, 3 SE, 4 SW on l; 5–8 same on m.
 */
const ARC_R = 22;
const HIGHLIGHT_R = 26;
const HIT_R = 58;
const LABEL_R = 34;
const TRANSVERSAL_DEG = 45;
const DEG = Math.PI / 180;

const yL = 115;
const yM = 265;
const topX = 250;
const tAngle = TRANSVERSAL_DEG * DEG;

// / transversal: down-left / up-right. From top → bottom: x decreases.
const tdx = Math.cos(tAngle);
const tdy = Math.sin(tAngle);
const bottomX = topX - (yM - yL) * (tdx / tdy);

const intersections = {
    top: { x: topX, y: yL },
    bottom: { x: bottomX, y: yM },
};

// atan2 screen angles (y down). / rays: up-right = -30°, down-left = 150°.
const aEast = 0;
const aTUp = -tAngle;
const aWest = Math.PI;
const aTDown = Math.PI - tAngle;

const wedges = {
    // clockwise wedges: 2 NE acute, 3 SE obtuse, 4 SW acute, 1 NW obtuse
    2: { a0: aTUp, a1: aEast },
    3: { a0: aEast, a1: aTDown },
    4: { a0: aTDown, a1: aWest },
    1: { a0: aWest, a1: aTUp + 2 * Math.PI },
    6: { a0: aTUp, a1: aEast },
    7: { a0: aEast, a1: aTDown },
    8: { a0: aTDown, a1: aWest },
    5: { a0: aWest, a1: aTUp + 2 * Math.PI },
};

const angleDefs = [1, 2, 3, 4, 5, 6, 7, 8].map((id) => ({
    id,
    vertex: id <= 4 ? 'top' : 'bottom',
}));

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

const sectorPath = (cx, cy, r, a0, a1) => {
    const delta = normalizeDelta(a0, a1);
    const large = delta > Math.PI ? 1 : 0;
    const p0 = polar(cx, cy, r, a0);
    const p1 = polar(cx, cy, r, a1);

    return `M ${cx.toFixed(1)} ${cy.toFixed(1)} L ${p0.x.toFixed(1)} ${p0.y.toFixed(1)} A ${r} ${r} 0 ${large} 1 ${p1.x.toFixed(1)} ${p1.y.toFixed(1)} Z`;
};

const regions = angleDefs.map((def) => {
    const v = intersections[def.vertex];
    const { a0, a1 } = wedges[def.id];
    const mid = a0 + normalizeDelta(a0, a1) / 2;

    return {
        id: def.id,
        arc: arcPath(v.x, v.y, ARC_R, a0, a1),
        highlightArc: arcPath(v.x, v.y, HIGHLIGHT_R, a0, a1),
        highlightFill: sectorPath(v.x, v.y, HIGHLIGHT_R, a0, a1),
        hit: sectorPath(v.x, v.y, HIT_R, a0, a1),
        label: polar(v.x, v.y, LABEL_R, mid),
    };
});

const extend = 48;
const tStart = {
    x: intersections.bottom.x - tdx * extend,
    y: intersections.bottom.y + tdy * extend,
};
const tEnd = {
    x: intersections.top.x + tdx * extend,
    y: intersections.top.y - tdy * extend,
};

const viewWidth = 420;
const viewHeight = 360;
const viewBox = `0 0 ${viewWidth} ${viewHeight}`;
const lineLeft = 50;
const lineRight = viewWidth - 50;

const isActive = (id) => id === props.highlight
    || id === props.selected
    || (props.revealed && id === props.correct);

const fillFor = (id) => {
    if (props.revealed && id === props.correct) {
        return 'rgba(167, 243, 208, 0.45)';
    }
    if (props.revealed && id === props.selected && id !== props.correct) {
        return 'rgba(254, 202, 202, 0.45)';
    }
    if (!props.revealed && id === props.selected) {
        return 'rgba(199, 210, 254, 0.4)';
    }
    if (id === props.highlight) {
        return 'rgba(253, 230, 138, 0.4)';
    }
    return 'none';
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
    return '#111827';
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
    <div class="rounded-lg border border-slate-200 bg-white p-3">
        <p class="mb-2 px-1 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            {{ hint }}
        </p>
        <svg
            :viewBox="viewBox"
            class="mx-auto h-auto w-full max-w-lg select-none"
            role="img"
            aria-label="Parallel lines cut by a transversal with angles 1 to 8"
            text-rendering="geometricPrecision"
            shape-rendering="geometricPrecision"
        >
            <defs>
                <marker id="arrow-end" markerWidth="8" markerHeight="8" refX="6" refY="3" orient="auto" markerUnits="strokeWidth">
                    <path d="M0,0 L6,3 L0,6 Z" fill="#111827" />
                </marker>
                <marker id="arrow-start" markerWidth="8" markerHeight="8" refX="2" refY="3" orient="auto" markerUnits="strokeWidth">
                    <path d="M6,0 L0,3 L6,6 Z" fill="#111827" />
                </marker>
            </defs>

            <!-- Lines first (textbook look) -->
            <line
                :x1="lineLeft"
                :y1="yL"
                :x2="lineRight"
                :y2="yL"
                stroke="#111827"
                stroke-width="2.5"
                marker-start="url(#arrow-start)"
                marker-end="url(#arrow-end)"
            />
            <line
                :x1="lineLeft"
                :y1="yM"
                :x2="lineRight"
                :y2="yM"
                stroke="#111827"
                stroke-width="2.5"
                marker-start="url(#arrow-start)"
                marker-end="url(#arrow-end)"
            />
            <line
                :x1="tStart.x"
                :y1="tStart.y"
                :x2="tEnd.x"
                :y2="tEnd.y"
                stroke="#111827"
                stroke-width="2.5"
                marker-start="url(#arrow-start)"
                marker-end="url(#arrow-end)"
            />

            <text
                :x="lineLeft - 18"
                :y="yL + 5"
                font-size="18"
                font-style="italic"
                font-weight="700"
                fill="#111827"
                font-family="Georgia, 'Times New Roman', Times, serif"
            >l</text>
            <text
                :x="lineLeft - 18"
                :y="yM + 5"
                font-size="18"
                font-style="italic"
                font-weight="700"
                fill="#111827"
                font-family="Georgia, 'Times New Roman', Times, serif"
            >m</text>
            <text
                :x="tEnd.x + 8"
                :y="tEnd.y + 6"
                font-size="18"
                font-style="italic"
                font-weight="700"
                fill="#111827"
                font-family="Georgia, 'Times New Roman', Times, serif"
            >t</text>

            <!-- Soft highlight only for active angle (tight to arc, textbook size) -->
            <path
                v-for="region in regions.filter((r) => isActive(r.id))"
                :key="`fill-${region.id}`"
                :d="region.highlightFill"
                :fill="fillFor(region.id)"
                stroke="none"
                class="pointer-events-none"
            />

            <!-- Default thin black arcs (all 8) -->
            <path
                v-for="region in regions"
                :key="`arc-${region.id}`"
                :d="isActive(region.id) ? region.highlightArc : region.arc"
                fill="none"
                :stroke="strokeFor(region.id)"
                :stroke-width="isActive(region.id) ? 3 : 1.75"
                stroke-linecap="round"
                class="pointer-events-none"
            />

            <!-- Invisible tap wedges -->
            <path
                v-for="region in regions"
                :key="`hit-${region.id}`"
                :d="region.hit"
                fill="transparent"
                class="cursor-pointer"
                :class="{ 'pointer-events-none': disabled || revealed }"
                @click="onSelect(region.id)"
            />

            <!-- Crisp serif numbers with white halo (no badge circles) -->
            <text
                v-for="region in regions"
                :key="`label-${region.id}`"
                :x="region.label.x"
                :y="region.label.y"
                text-anchor="middle"
                dominant-baseline="central"
                font-size="17"
                font-weight="700"
                fill="#111827"
                stroke="#ffffff"
                stroke-width="4"
                paint-order="stroke fill"
                font-family="Georgia, 'Times New Roman', Times, serif"
                class="pointer-events-none"
            >
                {{ region.id }}
            </text>
        </svg>
        <p class="mt-2 px-1 text-center text-[11px] text-slate-500">
            Angles 1–4 at line <em>l</em>, angles 5–8 at line <em>m</em>, transversal <em>t</em> at 45°.
        </p>
    </div>
</template>
