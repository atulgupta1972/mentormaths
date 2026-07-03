<script setup>
defineProps({
    url: {
        type: String,
        default: null,
    },
    alt: {
        type: String,
        default: 'Question diagram',
    },
    compact: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <figure v-if="url" class="my-3">
        <img
            :src="url"
            :alt="alt"
            class="rounded-lg border border-gray-200 bg-white object-contain shadow-sm"
            :class="compact ? 'max-h-32 max-w-full' : 'max-h-80 max-w-full'"
        />
    </figure>
</template>
