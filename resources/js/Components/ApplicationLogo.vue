<script setup>
import MentorMathsLogo from '@/Components/MentorMathsLogo.vue';

defineProps({
    sizeClass: {
        type: String,
        default: 'block h-12 w-auto max-w-[15rem] shrink-0 object-contain object-left sm:h-14 sm:max-w-[17rem]',
    },
});
</script>

<template>
    <MentorMathsLogo variant="mark" :size-class="sizeClass" />
</template>
