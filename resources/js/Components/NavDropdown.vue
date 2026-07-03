<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { computed } from 'vue';

const props = defineProps({
    label: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
        default: false,
    },
    items: {
        type: Array,
        default: () => [],
    },
});

const visibleItems = computed(() =>
    props.items.filter((item) => item.show !== false),
);

const triggerClass = computed(() =>
    props.active
        ? 'inline-flex items-center gap-1 border-b-2 border-indigo-400 px-1 pt-1 text-sm font-medium leading-5 text-gray-900'
        : 'inline-flex items-center gap-1 border-b-2 border-transparent px-1 pt-1 text-sm font-medium leading-5 text-gray-500 hover:border-gray-300 hover:text-gray-700',
);
</script>

<template>
    <Dropdown v-if="visibleItems.length" align="left" width="52">
        <template #trigger>
            <button type="button" :class="triggerClass">
                {{ label }}
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path
                        fill-rule="evenodd"
                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                        clip-rule="evenodd"
                    />
                </svg>
            </button>
        </template>

        <template #content>
            <DropdownLink
                v-for="item in visibleItems"
                :key="item.href"
                :href="item.href"
                :active="item.active"
            >
                {{ item.label }}
            </DropdownLink>
        </template>
    </Dropdown>
</template>
