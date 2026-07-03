<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const isAdmin = computed(() => usePage().props.auth?.isAdmin ?? false);
</script>

<template>
    <div
        v-if="!isAdmin"
        class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900"
    >
        <strong>Browse mode.</strong>
        You can view the syllabus, question counts, and question bank here.
        Practice sets can only be attempted when assigned to you from the Dashboard.
    </div>
</template>
