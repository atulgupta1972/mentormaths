<script setup>
import { computed } from 'vue';

const props = defineProps({
    expr: { type: String, default: '' },
    times: { type: [String, Number], default: null },
    showTimesSlot: { type: Boolean, default: false },
});

const parts = computed(() => {
    const text = String(props.expr ?? '').trim();
    const idx = text.lastIndexOf('/');
    if (idx <= 0 || idx === text.length - 1) {
        return { num: text, den: null, isFraction: false };
    }

    return {
        num: text.slice(0, idx).trim(),
        den: text.slice(idx + 1).trim(),
        isFraction: true,
    };
});

const timesText = computed(() => {
    const text = String(props.times ?? '').trim();

    return text === '' ? null : text;
});
const showTimes = computed(() => props.showTimesSlot || !! timesText.value);
</script>

<template>
    <span
        v-if="!parts.isFraction"
        class="inline-flex items-center gap-1 font-mono font-extrabold text-slate-900"
    >
        <span>{{ parts.num }}</span>
        <template v-if="showTimes">
            <span class="text-rose-700">×</span>
            <slot />
            <span v-if="!showTimesSlot">{{ timesText }}</span>
        </template>
    </span>
    <span
        v-else
        class="inline-flex items-center font-mono font-extrabold leading-none text-slate-900"
    >
        <span class="inline-flex min-w-[2.5rem] flex-col items-center">
            <span class="inline-flex items-center justify-center gap-1 px-1">
                <span>{{ parts.num }}</span>
                <template v-if="showTimes">
                    <span class="text-rose-700">×</span>
                    <slot />
                    <span v-if="!showTimesSlot">{{ timesText }}</span>
                </template>
            </span>
            <span class="my-1 h-[2px] w-full min-w-[2.25rem] rounded-full bg-slate-800" />
            <span>{{ parts.den }}</span>
        </span>
    </span>
</template>
