<script setup>
import MentorMathsLogo from '@/Components/MentorMathsLogo.vue';

defineProps({
    sizeClass: {
        type: String,
        default: 'block h-10 w-auto max-w-[11rem] shrink-0 object-contain object-left',
    },
});
</script>

<template>
    <MentorMathsLogo variant="mark" :size-class="sizeClass" />
</template>
