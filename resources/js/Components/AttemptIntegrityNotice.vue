<script setup>
defineProps({
    isTest: {
        type: Boolean,
        default: false,
    },
    mode: {
        type: String,
        default: 'strict',
    },
    lockLimit: {
        type: Number,
        default: 4,
    },
});
</script>

<template>
    <div
        v-if="mode !== 'off'"
        class="rounded-lg border px-4 py-3 text-sm"
        :class="isTest ? 'border-amber-200 bg-amber-50 text-amber-950' : 'border-sky-200 bg-sky-50 text-sky-950'"
    >
        <p class="font-medium">
            {{ isTest ? 'Test mode — do not switch tabs' : 'Practice mode — do not switch tabs' }}
        </p>
        <p class="mt-1 text-xs leading-relaxed opacity-90">
            Complete this set without leaving Mentor Maths. Fullscreen stays on; copy/paste is blocked.
            Do not open other tabs, apps, or AI side panels (Gemini).
            After {{ lockLimit }} leaves, the attempt locks — your teacher must unlock it.
        </p>
    </div>
</template>
