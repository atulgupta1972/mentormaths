<script setup>
import { computed } from 'vue';

const props = defineProps({
    start: { type: Number, default: 12 },
    selected: { type: Number, default: null },
    correct: { type: Number, default: null },
    revealed: { type: Boolean, default: false },
    interactive: { type: Boolean, default: true },
});

const emit = defineEmits(['select']);

const cx = 160;
const cy = 170;
const r = 110;

const faces = [
    { id: 12, label: '12', angle: -90 },
    { id: 3, label: '3', angle: 0 },
    { id: 6, label: '6', angle: 90 },
    { id: 9, label: '9', angle: 180 },
];

const toRad = (deg) => (deg * Math.PI) / 180;

const pointOn = (deg, radius) => ({
    x: cx + radius * Math.cos(toRad(deg)),
    y: cy + radius * Math.sin(toRad(deg)),
});

const handTip = computed(() => {
    const face = props.revealed && props.correct ? props.correct : (props.selected ?? props.start ?? 12);
    const match = faces.find((f) => f.id === face) || faces[0];
    return pointOn(match.angle, r - 28);
});

const startTip = computed(() => {
    const match = faces.find((f) => f.id === props.start) || faces[0];
    return pointOn(match.angle, r - 28);
});

const fillFor = (id) => {
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

const onSelect = (id) => {
    if (! props.interactive || props.revealed) {
        return;
    }
    emit('select', id);
};
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-2">
        <p class="mb-1 px-1 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            <span v-if="interactive">Yellow = start. Tap where you face after the turn.</span>
            <span v-else>Clock hand shows the turn.</span>
        </p>
        <svg viewBox="0 0 320 340" class="mx-auto h-auto w-full max-w-sm select-none" role="img" aria-label="Clock face for turns">
            <circle :cx="cx" :cy="cy" :r="r" fill="#fff" stroke="#0f172a" stroke-width="3" />
            <circle :cx="cx" :cy="cy" r="6" fill="#0f172a" />

            <!-- tick marks -->
            <g v-for="n in 12" :key="`tick-${n}`">
                <line
                    :x1="pointOn((n - 3) * 30, r - 8).x"
                    :y1="pointOn((n - 3) * 30, r - 8).y"
                    :x2="pointOn((n - 3) * 30, r).x"
                    :y2="pointOn((n - 3) * 30, r).y"
                    stroke="#64748b"
                    stroke-width="2"
                />
            </g>

            <!-- tap targets -->
            <g v-for="face in faces" :key="face.id">
                <circle
                    :cx="pointOn(face.angle, r - 34).x"
                    :cy="pointOn(face.angle, r - 34).y"
                    r="22"
                    :fill="fillFor(face.id)"
                    :stroke="strokeFor(face.id)"
                    stroke-width="2.5"
                    :class="interactive && !revealed ? 'cursor-pointer' : ''"
                    @click="onSelect(face.id)"
                />
                <text
                    :x="pointOn(face.angle, r - 34).x"
                    :y="pointOn(face.angle, r - 34).y + 5"
                    text-anchor="middle"
                    font-size="16"
                    font-weight="700"
                    fill="#0f172a"
                    class="pointer-events-none"
                >
                    {{ face.label }}
                </text>
            </g>

            <!-- start ghost hand -->
            <line
                v-if="selected || revealed"
                :x1="cx"
                :y1="cy"
                :x2="startTip.x"
                :y2="startTip.y"
                stroke="#f59e0b"
                stroke-width="3"
                stroke-dasharray="5 4"
                opacity="0.7"
            />

            <!-- main hand -->
            <line
                :x1="cx"
                :y1="cy"
                :x2="handTip.x"
                :y2="handTip.y"
                stroke="#4f46e5"
                stroke-width="5"
                stroke-linecap="round"
            />
            <polygon
                :points="`${handTip.x},${handTip.y - 8} ${handTip.x + 10},${handTip.y} ${handTip.x},${handTip.y + 8}`"
                fill="#4f46e5"
                :transform="`rotate(${(faces.find(f => f.id === (revealed && correct ? correct : (selected ?? start)))?.angle ?? -90) + 90} ${handTip.x} ${handTip.y})`"
            />

            <text :x="cx" y="318" text-anchor="middle" font-size="12" fill="#64748b">
                Full turn = around the clock once
            </text>
        </svg>
    </div>
</template>
