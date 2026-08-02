<script setup>
import { ref } from 'vue';

const props = defineProps({
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
    /** sm = admin preview, md = default, lg = student attempt (large + zoom) */
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg'].includes(value),
    },
});

const zoomOpen = ref(false);

const resolvedSize = () => {
    if (props.compact) {
        return 'sm';
    }

    return props.size;
};

const sizeClass = () => {
    switch (resolvedSize()) {
    case 'sm':
        return 'max-h-32 max-w-full';
    case 'lg':
        return 'max-h-[min(70vh,640px)] w-full cursor-zoom-in';
    default:
        return 'max-h-80 max-w-full';
    }
};

const openZoom = () => {
    if (resolvedSize() === 'lg') {
        zoomOpen.value = true;
    }
};

const closeZoom = () => {
    zoomOpen.value = false;
};
</script>

<template>
    <figure v-if="url" class="my-3">
        <button
            v-if="resolvedSize() === 'lg'"
            type="button"
            class="block w-full text-left"
            @click="openZoom"
        >
            <img
                :src="url"
                :alt="alt"
                class="rounded-lg border border-gray-200 bg-white object-contain shadow-sm transition hover:ring-2 hover:ring-indigo-200"
                :class="sizeClass()"
            />
        </button>
        <img
            v-else
            :src="url"
            :alt="alt"
            class="rounded-lg border border-gray-200 bg-white object-contain shadow-sm"
            :class="sizeClass()"
        />
        <figcaption v-if="resolvedSize() === 'lg'" class="mt-1 text-xs text-gray-500">
            Tap chart to enlarge
        </figcaption>
    </figure>

    <div
        v-if="zoomOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
        role="dialog"
        aria-modal="true"
        aria-label="Enlarged chart"
        @click.self="closeZoom"
    >
        <button
            type="button"
            class="absolute right-4 top-4 rounded-full bg-white/90 px-3 py-1 text-sm font-medium text-gray-800 shadow"
            @click="closeZoom"
        >
            Close
        </button>
        <img
            :src="url"
            :alt="alt"
            class="max-h-[92vh] max-w-full rounded-lg bg-white object-contain shadow-2xl"
        />
    </div>
</template>
