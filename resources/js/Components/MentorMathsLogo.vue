<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'full',
    },
    sizeClass: {
        type: String,
        default: '',
    },
});

const imgClass = computed(() => {
    const defaults = props.variant === 'mark'
        ? 'block h-12 w-auto max-w-[14rem] shrink-0 object-contain object-left'
        : 'block h-16 w-auto max-w-full shrink-0 object-contain sm:h-20';

    return [defaults, props.sizeClass].filter(Boolean).join(' ');
});
</script>

<template>
    <img
        src="/logo.jpg"
        alt="Mentor Maths — Plan · Practice · Perform"
        width="1024"
        height="559"
        decoding="async"
        fetchpriority="high"
        :class="imgClass"
    >
</template>
