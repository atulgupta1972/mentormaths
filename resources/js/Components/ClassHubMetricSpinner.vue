<script setup>
defineProps({
    label: { type: String, default: 'Loading' },
    size: { type: String, default: 'md' },
});

const sizeClass = {
    sm: 'h-4 w-4',
    md: 'h-5 w-5',
    lg: 'h-6 w-6',
};
</script>

<template>
    <span
        class="inline-flex flex-col items-center justify-center gap-0.5"
        role="status"
        :aria-label="label"
    >
        <svg
            :class="[sizeClass[size] || sizeClass.md, 'animate-spin text-sky-500']"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            aria-hidden="true"
        >
            <circle
                class="opacity-20"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="3"
            />
            <path
                class="opacity-90"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
        </svg>
        <span class="text-[9px] font-medium uppercase tracking-wide text-sky-600/80">…</span>
    </span>
</template>
