<script setup>
defineProps({
    url: {
        type: String,
        required: true,
    },
    title: {
        type: String,
        default: 'Worksheet PDF',
    },
    helperText: {
        type: String,
        default: 'Use your worksheet above, then record answers below.',
    },
    protected: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div
        class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
        :class="{ 'attempt-protected': protected }"
    >
        <div class="border-b bg-gray-50 px-4 py-2 text-sm font-medium text-gray-700">
            {{ title }}
        </div>
        <iframe
            :src="url"
            class="h-[min(70vh,720px)] w-full bg-white"
            :title="title"
            :sandbox="protected ? 'allow-scripts allow-same-origin' : undefined"
        />
        <p class="border-t px-4 py-2 text-xs text-gray-500">
            {{ helperText }}
        </p>
    </div>
</template>
