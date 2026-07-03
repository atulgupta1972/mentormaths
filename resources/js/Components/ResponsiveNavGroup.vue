<script setup>
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { computed } from 'vue';

const props = defineProps({
    label: {
        type: String,
        required: true,
    },
    items: {
        type: Array,
        default: () => [],
    },
});

const visibleItems = computed(() =>
    props.items.filter((item) => item.show !== false),
);
</script>

<template>
    <div v-if="visibleItems.length" class="border-t border-gray-100 pt-2 first:border-t-0 first:pt-0">
        <p class="px-4 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-gray-400">
            {{ label }}
        </p>
        <ResponsiveNavLink
            v-for="item in visibleItems"
            :key="item.href"
            :href="item.href"
            :active="item.active"
            class="ps-6"
        >
            {{ item.label }}
        </ResponsiveNavLink>
    </div>
</template>
