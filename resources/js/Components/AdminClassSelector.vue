<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const context = computed(() => page.props.gradeContext);

const onChange = (event) => {
    const raw = event.target.value;
    const gradeLevelId = raw ? Number(raw) : null;

    // Cancel deferred dashboard loads so a stale partial response cannot 500 the page.
    router.cancel();
    router.post(route('admin.grade-context.update'), { grade_level_id: gradeLevelId }, {
        preserveScroll: true,
        preserveState: false,
    });
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
            <option value="">All classes (4–10)</option>
            <option v-for="grade in context.levels" :key="grade.id" :value="grade.id">
                {{ grade.name }} (age {{ grade.typical_age }})
            </option>
        </select>
    </div>
</template>
