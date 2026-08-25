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
        ? 'block h-10 w-auto max-w-[11rem] shrink-0 object-contain object-left'
        : 'block h-12 w-auto max-w-full shrink-0 object-contain';

    return [defaults, props.sizeClass].filter(Boolean).join(' ');
});
</script>

<template>
    <img
        src="/logo.jpg"
        alt="Mentor Maths"
        width="440"
        height="160"
        decoding="async"
        :class="imgClass"
    >
</template>
