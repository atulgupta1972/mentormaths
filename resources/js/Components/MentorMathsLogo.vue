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
        ? 'block h-16 w-auto max-w-[20rem] shrink-0 object-contain object-left sm:h-[4.75rem] sm:max-w-[24rem]'
        : 'block h-20 w-auto max-w-full shrink-0 object-contain sm:h-28';

    return [
        defaults,
        props.sizeClass,
        '[image-rendering:auto]',
    ].filter(Boolean).join(' ');
});
</script>

<template>
    <img
        src="/logo.jpg"
        alt="Mentor Maths — The maths of studying maths · Plan · Practice · Perform"
        width="1024"
        height="559"
        decoding="async"
        fetchpriority="high"
        :class="imgClass"
    >
</template>
