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
            Copy, paste, right-click, and text selection are disabled on questions.
            If you switch tabs or apps, questions are hidden until you return — each switch is counted.
            <span v-if="mode === 'strict'"> Chapter tests also run in fullscreen.</span>
        </p>
    </div>
</template>
