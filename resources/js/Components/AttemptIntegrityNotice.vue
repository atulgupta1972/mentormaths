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
        <p v-if="mode === 'strict'" class="mt-1 text-xs leading-relaxed opacity-90">
            Copy, paste, and right-click are disabled. The test runs in fullscreen. If you switch tabs, questions are hidden until you return.
        </p>
        <p v-else class="mt-1 text-xs leading-relaxed opacity-90">
            If you switch tabs or apps, questions are hidden until you return. Tab switches are recorded for your teacher.
        </p>
    </div>
</template>
