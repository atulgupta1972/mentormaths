<script setup>
import MentorMathsLogo from '@/Components/MentorMathsLogo.vue';

defineProps({
    sizeClass: {
        type: String,
        default: 'block h-16 w-auto max-w-[20rem] shrink-0 object-contain object-left sm:h-[4.75rem] sm:max-w-[26rem]',
    },
});
</script>

<template>
    <MentorMathsLogo variant="mark" :size-class="sizeClass" />
</template>
