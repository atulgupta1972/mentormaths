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
 */
const regions = [
    { id: 1, d: 'M 160 120 L 70 50 L 70 120 Z', label: { x: 105, y: 100 } },
    { id: 2, d: 'M 160 120 L 250 50 L 250 120 Z', label: { x: 210, y: 100 } },
    { id: 3, d: 'M 160 120 L 250 120 L 250 190 Z', label: { x: 210, y: 155 } },
    { id: 4, d: 'M 160 120 L 70 120 L 70 190 Z', label: { x: 105, y: 155 } },
    { id: 5, d: 'M 160 280 L 70 210 L 70 280 Z', label: { x: 105, y: 260 } },
    { id: 6, d: 'M 160 280 L 250 210 L 250 280 Z', label: { x: 210, y: 260 } },
    { id: 7, d: 'M 160 280 L 250 280 L 250 350 Z', label: { x: 210, y: 315 } },
    { id: 8, d: 'M 160 280 L 70 280 L 70 350 Z', label: { x: 105, y: 315 } },
];

const fillFor = (id) => {
    if (props.revealed && id === props.correct) {
        return '#bbf7d0';
    }
    if (props.revealed && id === props.selected && id !== props.correct) {
        return '#fecaca';
    }
    if (!props.revealed && id === props.selected) {
        return '#c7d2fe';
    }
    if (id === props.highlight) {
        return '#fde68a';
    }
    return '#f8fafc';
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
    return '#94a3b8';
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
            <path
                v-for="region in regions"
                :key="region.id"
                :d="region.d"
                :fill="fillFor(region.id)"
                :stroke="strokeFor(region.id)"
                stroke-width="2"
                class="cursor-pointer transition-colors"
                :class="{ 'pointer-events-none': disabled || revealed }"
                @click="onSelect(region.id)"
            />

            <!-- Line l -->
            <line x1="40" y1="120" x2="280" y2="120" stroke="#0f172a" stroke-width="3" />
            <!-- Line m -->
            <line x1="40" y1="280" x2="280" y2="280" stroke="#0f172a" stroke-width="3" />
            <!-- Transversal t -->
            <line x1="160" y1="30" x2="160" y2="370" stroke="#0f172a" stroke-width="3" />

            <text x="20" y="116" font-size="14" font-weight="700" fill="#334155">l</text>
            <text x="20" y="276" font-size="14" font-weight="700" fill="#334155">m</text>
            <text x="168" y="28" font-size="14" font-weight="700" fill="#334155">t</text>

            <text
                v-for="region in regions"
                :key="`label-${region.id}`"
                :x="region.label.x"
                :y="region.label.y"
                text-anchor="middle"
                font-size="16"
                font-weight="700"
                fill="#0f172a"
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
