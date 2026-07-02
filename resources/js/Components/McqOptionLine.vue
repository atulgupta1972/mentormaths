<script setup>
import { computed } from 'vue';
import { formatMcqText, optionLetter } from '@/utils/mcqDisplay';

const props = defineProps({
    index: {
        type: Number,
        required: true,
    },
    text: {
        type: String,
        default: '',
    },
});

const letter = computed(() => optionLetter(props.index));
const formattedText = computed(() => formatMcqText(props.text));
</script>

<template>
    <span class="inline-flex items-baseline gap-1.5">
        <span class="shrink-0 font-semibold">{{ letter }}</span>
        <span class="whitespace-pre-wrap" v-html="formattedText" />
    </span>
</template>
