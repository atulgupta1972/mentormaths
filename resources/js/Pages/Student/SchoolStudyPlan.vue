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
    chapterTermMapGate: {
        type: Object,
        default: () => ({ required: false, satisfied: true, message: null }),
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

const termsGateBlocking = computed(() => (
    props.chapterTermMapGate?.required && !props.chapterTermMapGate?.satisfied
));
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
                    v-if="termsGateBlocking"
                    class="rounded-xl border-2 border-amber-400 bg-amber-50 p-4"
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-950">Map Term 1 &amp; Term 2 — required</p>
                    <p class="mt-1 text-sm text-amber-950">
                        {{ chapterTermMapGate.message || 'Tag every chapter as Term 1 or Term 2 before drills and sums unlock.' }}
                    </p>
                    <p class="mt-2 text-xs text-amber-900/90">
                        Mapped {{ chapterTermMapGate.mapped_chapters || 0 }}/{{ chapterTermMapGate.total_chapters || 0 }}
                        · Term 1: {{ chapterTermMapGate.term1_count || 0 }}
                        · Term 2: {{ chapterTermMapGate.term2_count || 0 }}
                        <span v-if="chapterTermMapGate.unmapped_chapters">
                            · Untagged: {{ chapterTermMapGate.unmapped_chapters }}
                        </span>
                    </p>
                    <p class="mt-1 text-xs text-amber-900/80">
                        Use the Term 1 / Term 2 filters below, or set the last 1st-term chapter and apply the split.
                    </p>
                </section>

                <section
                    v-if="needsTicks && !termsGateBlocking"
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
