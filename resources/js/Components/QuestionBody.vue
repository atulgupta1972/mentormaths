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
    /** Larger chart + tap-to-zoom on student attempt screens */
    enlargeDiagram: {
        type: Boolean,
        default: false,
    },
});

const stripEmbeddedChartTable = (text) => {
    if (!text) {
        return '';
    }

    return text
        .split(/\n\n/)
        .filter((part) => !part.startsWith('Chart:') && !part.startsWith('Table:'))
        .join('\n\n')
        .trim();
};

const displayQuestionText = computed(() => {
    const text = props.diagramUrl
        ? stripEmbeddedChartTable(props.questionText)
        : props.questionText;

    return formatMcqText(text);
});

const diagramSize = computed(() => {
    if (props.enlargeDiagram) {
        return 'lg';
    }

    return props.compact ? 'sm' : 'md';
});
</script>

<template>
    <div>
        <QuestionDiagram :url="diagramUrl" :compact="compact" :size="diagramSize" />
        <p
            v-if="displayQuestionText"
            class="whitespace-pre-wrap font-medium text-gray-900 [&_sup]:relative [&_sup]:top-[-0.35em] [&_sup]:text-[0.75em]"
            v-html="displayQuestionText"
        />
    </div>
</template>
