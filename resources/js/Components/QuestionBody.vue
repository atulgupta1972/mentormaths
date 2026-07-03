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
    useHtml: {
        type: Boolean,
        default: false,
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
            v-if="questionText && useHtml"
            class="font-medium text-gray-900"
            v-html="formattedText"
        />
        <p
            v-else-if="questionText"
            class="whitespace-pre-wrap font-medium text-gray-900"
        >
            {{ questionText }}
        </p>
    </div>
</template>
