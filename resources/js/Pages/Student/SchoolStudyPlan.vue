<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ClassCoveragePanel from '@/Components/ClassCoveragePanel.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    classCoverage: {
        type: Object,
        default: () => ({ chapters: [], under_study_chapter_id: null }),
    },
    upcomingExams: {
        type: Array,
        default: () => [],
    },
    context: {
        type: Object,
        default: null,
    },
});

const subtitle = computed(() => {
    const parts = [props.context?.grade_name, props.context?.board_name].filter(Boolean);

    return parts.length ? parts.join(' · ') : 'Your class syllabus';
});
</script>

<template>
    <Head title="My School Study Plan" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">My School Study Plan</h2>
                <p class="text-sm text-gray-500">{{ subtitle }}</p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6">
                <ClassCoveragePanel
                    :class-coverage="classCoverage"
                    :upcoming-exams="upcomingExams"
                    update-route-name="student.class-coverage.update"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
