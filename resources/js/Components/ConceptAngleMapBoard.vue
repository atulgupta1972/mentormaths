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
 * Drawn like a textbook: circular arcs + labels (not filled triangles).
 */
const ARC_R = 34;
const HIT_R = 52;
const LABEL_R = 22;

const intersections = {
    top: { x: 160, y: 120 },
    bottom: { x: 160, y: 280 },
};

/** Quadrant: start/end unit vectors (screen coords: +x right, +y down). */
const quadrants = {
    NW: { ax: -1, ay: 0, bx: 0, by: -1 },
    NE: { ax: 1, ay: 0, bx: 0, by: -1 },
    SE: { ax: 1, ay: 0, bx: 0, by: 1 },
    SW: { ax: -1, ay: 0, bx: 0, by: 1 },
};

const angleDefs = [
    { id: 1, vertex: 'top', q: 'NW' },
    { id: 2, vertex: 'top', q: 'NE' },
    { id: 3, vertex: 'top', q: 'SE' },
    { id: 4, vertex: 'top', q: 'SW' },
    { id: 5, vertex: 'bottom', q: 'NW' },
    { id: 6, vertex: 'bottom', q: 'NE' },
    { id: 7, vertex: 'bottom', q: 'SE' },
    { id: 8, vertex: 'bottom', q: 'SW' },
];

/**
 * SVG arc from arm A to arm B (90°). sweep=0 = CCW in SVG y-down space
 * when going NE from east→north; for SE we need clockwise (sweep=1).
 */
const arcPath = (cx, cy, r, q) => {
    const { ax, ay, bx, by } = quadrants[q];
    const x1 = cx + ax * r;
    const y1 = cy + ay * r;
    const x2 = cx + bx * r;
    const y2 = cy + by * r;
    // SVG y-down: sweep 0 = CCW, 1 = CW. Short 90° arcs:
    // NE east→north CCW; NW west→north CW; SE east→south CW; SW west→south CCW.
    const sweep = (q === 'NE' || q === 'SW') ? 0 : 1;

    return `M ${x1} ${y1} A ${r} ${r} 0 0 ${sweep} ${x2} ${y2}`;
};

const sectorHitPath = (cx, cy, r, q) => {
    const { ax, ay, bx, by } = quadrants[q];
    const x1 = cx + ax * r;
    const y1 = cy + ay * r;
    const x2 = cx + bx * r;
    const y2 = cy + by * r;
    const sweep = (q === 'NE' || q === 'SW') ? 0 : 1;

    return `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 0 ${sweep} ${x2} ${y2} Z`;
};

const labelPoint = (cx, cy, q) => {
    const { ax, ay, bx, by } = quadrants[q];
    const mx = (ax + bx) / 2;
    const my = (ay + by) / 2;
    const len = Math.hypot(mx, my) || 1;

    return {
        x: cx + (mx / len) * LABEL_R,
        y: cy + (my / len) * LABEL_R,
    };
};

const regions = angleDefs.map((def) => {
    const v = intersections[def.vertex];

    return {
        id: def.id,
        arc: arcPath(v.x, v.y, ARC_R, def.q),
        hit: sectorHitPath(v.x, v.y, HIT_R, def.q),
        label: labelPoint(v.x, v.y, def.q),
    };
});

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
        <svg viewBox="0 0 320 400" class="mx-auto h-auto w-full max-w-sm select-none" role="img" aria-label="Two lines cut by a transversal with angles 1 to 8">
            <!-- Line l -->
            <line x1="40" y1="120" x2="280" y2="120" stroke="#0f172a" stroke-width="3" />
            <!-- Line m -->
            <line x1="40" y1="280" x2="280" y2="280" stroke="#0f172a" stroke-width="3" />
            <!-- Transversal t -->
            <line x1="160" y1="30" x2="160" y2="370" stroke="#0f172a" stroke-width="3" />

            <text x="20" y="116" font-size="14" font-weight="700" fill="#334155" font-family="Georgia, 'Times New Roman', serif">l</text>
            <text x="20" y="276" font-size="14" font-weight="700" fill="#334155" font-family="Georgia, 'Times New Roman', serif">m</text>
            <text x="168" y="28" font-size="14" font-weight="700" fill="#334155" font-family="Georgia, 'Times New Roman', serif">t</text>

            <!-- Soft highlight under arc only when selected / highlighted / revealed -->
            <path
                v-for="region in regions"
                :key="`fill-${region.id}`"
                :d="region.hit"
                :fill="fillFor(region.id)"
                stroke="none"
                class="pointer-events-none"
            />

            <!-- Textbook-style angle arcs -->
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

            <!-- Invisible tap targets -->
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
            Angles 1–4 at line <strong>l</strong>, angles 5–8 at line <strong>m</strong>, transversal <strong>t</strong>.
        </p>
    </div>
</template>
