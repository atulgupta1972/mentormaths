<script setup>
import MentorMathsLogo from '@/Components/MentorMathsLogo.vue';

defineProps({
    class: {
        type: String,
        default: 'block h-9 w-9 shrink-0',
    },
});
</script>

<template>
    <MentorMathsLogo variant="mark" :class="class" />
</template>
