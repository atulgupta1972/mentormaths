<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AttemptReviewList from '@/Components/AttemptReviewList.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { formatScoreLabel } from '@/utils/scores';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    attempt: Object,
    assignment: Object,
    practiceSet: Object,
    questions: { type: Array, default: () => [] },
});

const setLabel = () => props.practiceSet?.set_code || 'Practice';

const formatTime = (seconds) => {
    if (!seconds) {
        return '—';
    }

    const m = Math.floor(seconds / 60);
    const s = seconds % 60;

    return m ? `${m}m ${s}s` : `${s}s`;
};
</script>

<template>
    <Head :title="`Results — ${setLabel()}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Guided practice complete</p>
                    <h2 class="font-mono text-xl font-semibold text-gray-800">{{ setLabel() }}</h2>
                </div>
                <Link :href="route('dashboard')" class="text-sm text-indigo-600">Dashboard</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 text-center shadow-sm">
                    <p class="text-sm text-gray-500">First-try score</p>
                    <p class="text-4xl font-bold text-indigo-600">
                        {{ formatScoreLabel(attempt.first_try_correct, attempt.max_score) }}
                    </p>
                    <p class="mt-2 text-sm text-gray-600">Time: {{ formatTime(attempt.time_seconds) }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-center">
                        <p class="text-2xl font-bold text-emerald-700">{{ attempt.corrected_after_help }}</p>
                        <p class="mt-1 text-xs font-medium uppercase tracking-wide text-emerald-800">Fixed after method</p>
                    </div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-center">
                        <p class="text-2xl font-bold text-amber-700">{{ attempt.given_up }}</p>
                        <p class="mt-1 text-xs font-medium uppercase tracking-wide text-amber-800">Need teacher help</p>
                    </div>
                </div>

                <div v-if="attempt.given_up > 0" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    {{ attempt.given_up }} sum{{ attempt.given_up === 1 ? '' : 's' }} moved to your
                    <strong>Resolution</strong> list on the dashboard. Your teacher will explain them, then you can retry.
                </div>

                <AttemptReviewList :questions="questions" :attempt-id="attempt.id" allow-practice-retry />

                <Link :href="route('dashboard')">
                    <PrimaryButton>Back to dashboard</PrimaryButton>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
