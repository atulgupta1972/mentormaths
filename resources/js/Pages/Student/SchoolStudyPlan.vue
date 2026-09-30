<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ClassCoveragePanel from '@/Components/ClassCoveragePanel.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();

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

const needsTicks = computed(() => {
    const chapters = props.classCoverage?.chapters || [];

    return chapters.length > 0 && !chapters.some((chapter) => chapter.studied || chapter.under_study);
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
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6">
                <div v-if="page.props.flash?.warning" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    {{ page.props.flash.warning }}
                </div>
                <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    {{ page.props.flash.error }}
                </div>
                <section
                    v-if="needsTicks"
                    class="rounded-xl border-2 border-sky-400 bg-sky-50 p-4"
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-sky-950">Tick your chapters before drills</p>
                    <p class="mt-1 text-sm text-sky-950">
                        There are no drills until you mark this planner. Tick <strong>Studied</strong> for every chapter you have already finished, and <strong>Under study</strong> for the chapter you are doing now.
                    </p>
                </section>
                <ClassCoveragePanel
                    :class-coverage="classCoverage"
                    :upcoming-exams="upcomingExams"
                    update-route-name="student.class-coverage.update"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
