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
            <p class="text-lg font-semibold">Attempt locked</p>
            <p class="text-sm text-white/90">
                You left this page {{ tabLeaveCount }} time{{ tabLeaveCount === 1 ? '' : 's' }}
                (limit {{ lockLimit }}). Ask your teacher to unlock this attempt so you can continue.
            </p>
            <p class="text-xs text-white/70">
                Teacher path: open this assignment → Attempts → Unlock.
            </p>
        </div>
    </div>
</template>
