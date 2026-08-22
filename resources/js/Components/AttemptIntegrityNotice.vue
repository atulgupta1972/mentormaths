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
        class="rounded-xl border-2 px-5 py-4 text-center"
        :class="isTest ? 'border-rose-400 bg-rose-50 text-rose-950' : 'border-amber-400 bg-amber-50 text-amber-950'"
    >
        <p class="text-lg font-extrabold leading-snug sm:text-xl">
            Do not switch tabs or apps
        </p>
        <p class="mt-2 text-sm font-bold leading-relaxed sm:text-base">
            Stay on this screen in fullscreen until you finish.
            Each time you leave or switch tabs, your teacher is informed.
        </p>
    </div>
</template>
