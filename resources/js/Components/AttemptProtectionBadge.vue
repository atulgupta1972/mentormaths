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
        <span class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-700">
            Tab switches: {{ tabLeaveCount }}
        </span>
    </div>
</template>
