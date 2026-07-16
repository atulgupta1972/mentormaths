<script setup>
import QuestionDiagram from '@/Components/QuestionDiagram.vue';
import { formatMcqText } from '@/utils/mcqDisplay';
import { computed } from 'vue';

const props = defineProps({
    questionText: {
        type: String,
        default: '',
    },
    diagramUrl: {
        type: String,
        default: null,
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const formattedText = computed(() => formatMcqText(props.questionText));
</script>

<template>
    <div>
        <QuestionDiagram :url="diagramUrl" :compact="compact" />
        <p
            v-if="questionText"
            class="whitespace-pre-wrap font-medium text-gray-900 [&_sup]:relative [&_sup]:top-[-0.35em] [&_sup]:text-[0.75em]"
            v-html="formattedText"
        />
    </div>
</template>
