<script setup>
defineProps({
    progress: { type: Number, default: 0 },
    stage: { type: String, default: '' },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div :class="compact ? 'mt-1 w-full max-w-[11rem]' : 'mt-2 w-full'">
        <div class="flex items-center justify-between gap-2 text-[10px] text-gray-600">
            <span class="truncate">{{ stage || 'Checking…' }}</span>
            <span class="shrink-0 font-semibold tabular-nums text-amber-800">{{ progress }}%</span>
        </div>
        <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
            <div
                class="h-full rounded-full bg-amber-500 transition-all duration-500"
                :style="{ width: `${Math.max(4, progress)}%` }"
            />
        </div>
    </div>
</template>
