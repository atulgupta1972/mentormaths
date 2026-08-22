<script setup>
defineProps({
    tabLeaveCount: {
        type: Number,
        default: 0,
    },
    lockLimit: {
        type: Number,
        default: 4,
    },
});
</script>

<template>
    <div
        class="fixed inset-0 z-[110] flex items-center justify-center bg-rose-950/95 p-6 text-center text-white"
        role="alert"
        aria-live="assertive"
    >
        <div class="max-w-md space-y-3">
            <p class="text-2xl font-extrabold">Attempt locked</p>
            <p class="text-base font-semibold text-white/95">
                Too many tab or app switches. Your teacher has been informed.
                Ask them to unlock this attempt so you can continue.
            </p>
        </div>
    </div>
</template>
