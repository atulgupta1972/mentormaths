<script setup>
import { tokenizeConceptMath } from '@/utils/conceptMathText';
import { computed } from 'vue';

const props = defineProps({
    text: { type: [String, Number, null], default: '' },
});

const tokens = computed(() => tokenizeConceptMath(props.text));
</script>

<template>
    <span class="concept-math-text">
        <template v-for="(token, index) in tokens" :key="index">
            <span v-if="token.type === 'text'" class="whitespace-pre-wrap">{{ token.value }}</span>
            <span
                v-else
                class="concept-fraction mx-[0.12em] inline-flex items-center align-middle font-serif text-[1.05em] leading-none text-inherit"
                :aria-label="token.whole ? `${token.whole} and ${token.num} over ${token.den}` : `${token.num} over ${token.den}`"
            >
                <span v-if="token.whole" class="mr-[0.08em] text-[1em] leading-none">{{ token.whole }}</span>
                <span class="inline-flex flex-col items-center justify-center align-middle">
                    <span class="px-[0.12em] text-[0.72em] leading-none">{{ token.num }}</span>
                    <span class="my-[0.08em] block h-[1.5px] min-w-[0.85em] w-full bg-current" aria-hidden="true" />
                    <span class="px-[0.12em] text-[0.72em] leading-none">{{ token.den }}</span>
                </span>
            </span>
        </template>
    </span>
</template>
