<script setup>
defineProps({
    variant: {
        type: String,
        default: 'full',
    },
    class: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <svg
        v-if="variant === 'mark'"
        viewBox="0 0 48 48"
        xmlns="http://www.w3.org/2000/svg"
        :class="class"
        role="img"
        aria-label="Mentor Maths"
    >
        <defs>
            <linearGradient id="mm-mark-bg" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#2563EB" />
                <stop offset="100%" stop-color="#0891B2" />
            </linearGradient>
            <linearGradient id="mm-mark-accent" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="#F59E0B" />
                <stop offset="100%" stop-color="#F97316" />
            </linearGradient>
        </defs>
        <rect width="48" height="48" rx="12" fill="url(#mm-mark-bg)" />
        <path
            d="M10 34V14l6 8 6-8v20"
            fill="none"
            stroke="#fff"
            stroke-width="3"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <path
            d="M28 34V22c0-3.3 2.7-6 6-6"
            fill="none"
            stroke="#fff"
            stroke-width="3"
            stroke-linecap="round"
        />
        <path d="M28 34h6" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" />
        <path
            d="M30 28l4-4"
            fill="none"
            stroke="url(#mm-mark-accent)"
            stroke-width="2"
            stroke-linecap="round"
        />
        <circle cx="34" cy="24" r="1.5" fill="url(#mm-mark-accent)" />
    </svg>

    <svg
        v-else
        viewBox="0 0 220 48"
        xmlns="http://www.w3.org/2000/svg"
        :class="class"
        role="img"
        aria-label="Mentor Maths"
    >
        <defs>
            <linearGradient id="mm-full-bg" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#2563EB" />
                <stop offset="100%" stop-color="#0891B2" />
            </linearGradient>
            <linearGradient id="mm-full-accent" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="#F59E0B" />
                <stop offset="100%" stop-color="#F97316" />
            </linearGradient>
        </defs>
        <rect width="48" height="48" rx="12" fill="url(#mm-full-bg)" />
        <path
            d="M10 34V14l6 8 6-8v20"
            fill="none"
            stroke="#fff"
            stroke-width="3"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <path
            d="M28 34V22c0-3.3 2.7-6 6-6"
            fill="none"
            stroke="#fff"
            stroke-width="3"
            stroke-linecap="round"
        />
        <path d="M28 34h6" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" />
        <path
            d="M30 28l4-4"
            fill="none"
            stroke="url(#mm-full-accent)"
            stroke-width="2"
            stroke-linecap="round"
        />
        <circle cx="34" cy="24" r="1.5" fill="url(#mm-full-accent)" />
        <text
            x="58"
            y="22"
            fill="#0F172A"
            font-family="system-ui, -apple-system, sans-serif"
            font-size="20"
            font-weight="700"
        >
            Mentor Maths
        </text>
        <text
            x="58"
            y="38"
            fill="#64748B"
            font-family="system-ui, -apple-system, sans-serif"
            font-size="11"
            font-weight="500"
            letter-spacing="0.08em"
        >
            PLAN · PRACTICE · PERFORM
        </text>
    </svg>
</template>
