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
        default: 2,
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
            {{ isTest ? 'Test mode — work on your own' : 'Practice mode — work on your own' }}
        </p>
        <p class="mt-1 text-xs leading-relaxed opacity-90">
            Fullscreen is required so only Mentor Maths stays on screen.
            Copy, paste, right-click, and text selection are disabled.
            Leaving fullscreen, switching tabs/apps, or opening side panels (Gemini) hides questions and counts as a leave.
            After {{ lockLimit }} leaves, the attempt locks — ask your teacher.
        </p>
    </div>
</template>
