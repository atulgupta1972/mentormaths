<script setup>
defineProps({
    message: {
        type: String,
        default: 'Return to this tab to continue your attempt.',
    },
});
</script>

<template>
    <div
        class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/95 p-6 text-center text-white"
        role="alert"
        aria-live="assertive"
    >
        <div class="max-w-md space-y-3">
            <p class="text-lg font-semibold">Attempt paused</p>
            <p class="text-sm text-white/85">{{ message }}</p>
            <p class="text-xs text-white/60">Questions stay hidden while this tab is in the background.</p>
        </div>
    </div>
</template>
