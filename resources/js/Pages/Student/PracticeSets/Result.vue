<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AttemptReviewList from '@/Components/AttemptReviewList.vue';
import WorksheetPdfViewer from '@/Components/WorksheetPdfViewer.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { formatScoreLabel, scorePerformanceGrade, scorePerformanceGradeClasses } from '@/utils/scores';
import SimilarPracticePanel from '@/Components/SimilarPracticePanel.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    attempt: Object,
    assignment: Object,
    practiceSet: Object,
    questions: { type: Array, default: () => [] },
    referencePdfUrl: { type: String, default: null },
    similarPractice: { type: Object, default: () => ({ wrong_count: 0, variants: [], can_generate: false }) },
});

const setLabel = () => props.practiceSet.set_code || `Set ${props.practiceSet.set_number}`;

const formatTime = (seconds) => {
    if (!seconds) return '—';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m ? `${m}m ${s}s` : `${s}s`;
};

const percent = (score, max) => formatScoreLabel(score, max);

const performanceGrade = computed(() => scorePerformanceGrade(props.attempt?.score, props.attempt?.max_score));

const gradeBadgeClass = computed(() => {
    const tone = performanceGrade.value?.tone;

    return tone ? scorePerformanceGradeClasses[tone] : '';
});

const correctCount = () => props.questions.filter((q) => q.attempts?.some((row) => row.is_correct)).length;
</script>

<template>
    <Head :title="`Results — ${setLabel()}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">{{ practiceSet.kind_label }}</p>
                    <h2 class="font-mono text-xl font-semibold text-gray-800">{{ setLabel() }}</h2>
                </div>
                <Link :href="route('dashboard')" class="text-sm text-indigo-600">Dashboard</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 text-center shadow-sm">
                    <p class="text-4xl font-bold text-indigo-600">{{ percent(attempt.score, attempt.max_score) }}</p>
                    <p
                        v-if="performanceGrade"
                        class="mt-3 inline-flex rounded-full px-4 py-1.5 text-sm font-semibold ring-1 ring-inset"
                        :class="gradeBadgeClass"
                    >
                        {{ performanceGrade.label }}
                    </p>
                    <p class="mt-2 text-sm text-gray-500">
                        Time: {{ formatTime(attempt.time_seconds) }} · Attempt {{ attempt.attempt_number }}
                    </p>
                    <p
                        v-if="attempt.submission_timing === 'late'"
                        class="mt-3 inline-block rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-900"
                    >
                        Delayed submission
                    </p>
                    <p
                        v-else-if="assignment?.target_date"
                        class="mt-3 inline-block rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-800"
                    >
                        Submitted on time
                    </p>
                </div>

                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-gray-800">Sum-wise result</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ correctCount() }} correct · {{ questions.length - correctCount() }} incorrect
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span
                            v-for="q in questions"
                            :key="q.number"
                            class="inline-flex h-9 min-w-9 items-center justify-center rounded-md px-2 text-sm font-semibold"
                            :class="q.attempts?.some((row) => row.is_correct) ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                        >
                            Q{{ q.number }}
                        </span>
                    </div>
                </div>

                <AttemptReviewList :questions="questions" :attempt-id="attempt.id" allow-practice-retry />

                <SimilarPracticePanel
                    :attempt-id="attempt.id"
                    :wrong-count="similarPractice.wrong_count ?? 0"
                    :initial-variants="similarPractice.variants ?? []"
                    :can-generate="similarPractice.can_generate ?? false"
                    :generate-route="route('student.attempts.similar-practice.generate', attempt.id)"
                    :check-route="route('student.attempts.similar-practice.check', attempt.id)"
                />

                <WorksheetPdfViewer
                    v-if="referencePdfUrl"
                    :url="referencePdfUrl"
                    title="Your worksheet"
                    helper-text="Refer to your worksheet for diagrams or extra working space."
                />

                <Link :href="route('dashboard')">
                    <PrimaryButton>Back to dashboard</PrimaryButton>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
