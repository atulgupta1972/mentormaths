<script setup>
defineProps({
    mode: {
        type: String,
        default: 'off',
    },
    tabLeaveCount: {
        type: Number,
        default: 0,
    },
    locked: {
        type: Boolean,
        default: false,
    },
    lockLimit: {
        type: Number,
        default: 2,
    },
});
</script>

<template>
    <div
        v-if="mode !== 'off'"
        class="flex flex-wrap items-center gap-2 text-xs"
    >
        <span
            class="rounded-full px-2 py-0.5 font-semibold uppercase tracking-wide"
            :class="mode === 'strict' ? 'bg-amber-100 text-amber-900' : 'bg-sky-100 text-sky-900'"
        >
            {{ mode === 'strict' ? 'Test protected' : 'Practice protected' }}
        </span>
        <span
            class="rounded-full px-2 py-0.5 font-medium"
            :class="locked ? 'bg-rose-100 text-rose-900' : 'bg-gray-100 text-gray-700'"
        >
            <template v-if="locked">Locked ({{ tabLeaveCount }}/{{ lockLimit }} tabs)</template>
            <template v-else>Tab switches: {{ tabLeaveCount }}/{{ lockLimit }}</template>
        </span>
    </div>
</template>
