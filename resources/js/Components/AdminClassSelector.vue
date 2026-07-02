<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const context = computed(() => page.props.gradeContext);

const onChange = (event) => {
    const value = event.target.value || null;
    router.post(route('admin.grade-context.update'), { grade_level_id: value }, { preserveScroll: true });
};
</script>

<template>
    <div v-if="context?.levels?.length" class="flex items-center gap-2">
        <label for="admin-class-select" class="hidden text-xs font-medium text-gray-500 sm:inline">Class</label>
        <select
            id="admin-class-select"
            class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            :value="context.selected?.id ?? ''"
            @change="onChange"
        >
            <option value="">All classes (6–10)</option>
            <option v-for="grade in context.levels" :key="grade.id" :value="grade.id">
                {{ grade.name }}
            </option>
        </select>
    </div>
</template>
