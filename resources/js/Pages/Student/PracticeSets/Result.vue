<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    attempt: Object,
    assignment: Object,
    practiceSet: Object,
    questions: Array,
});

const formatTime = (seconds) => {
    if (!seconds) return '—';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m ? `${m}m ${s}s` : `${s}s`;
};

const percent = (score, max) => (max ? Math.round((score / max) * 100) : 0);
</script>

<template>
    <Head title="Results" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">Results — {{ practiceSet.display_title }}</h2>
                <Link :href="route('dashboard')" class="text-sm text-indigo-600">Dashboard</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 text-center shadow-sm">
                    <p class="text-4xl font-bold text-indigo-600">{{ attempt.score }}/{{ attempt.max_score }}</p>
                    <p class="mt-1 text-lg text-gray-600">{{ percent(attempt.score, attempt.max_score) }}%</p>
                    <p class="mt-2 text-sm text-gray-500">Time: {{ formatTime(attempt.time_seconds) }} · Attempt {{ attempt.attempt_number }}</p>
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
                    <p v-if="assignment?.target_date" class="mt-2 text-xs text-gray-500">
                        Target: {{ assignment.target_date }} · Submitted: {{ attempt.completed_at?.slice(0, 10) }}
                    </p>
                </div>

                <div
                    v-for="(q, index) in questions"
                    :key="index"
                    class="rounded-lg border bg-white p-5 shadow-sm"
                    :class="q.is_correct ? 'border-green-200' : 'border-red-200'"
                >
                    <div class="flex items-center gap-2">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="q.is_correct ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                        >
                            {{ q.is_correct ? 'Correct' : 'Incorrect' }}
                        </span>
                        <span class="text-sm text-gray-500">Q{{ index + 1 }}</span>
                    </div>
                    <p class="mt-2 font-medium">{{ q.question_text }}</p>
                    <ul class="mt-3 space-y-1 text-sm">
                        <li
                            v-for="(opt, optIndex) in q.options"
                            :key="opt.id"
                            :class="{
                                'font-semibold text-green-700': opt.is_correct,
                                'text-red-700': q.selected_option_id === opt.id && !opt.is_correct,
                                'text-gray-700': !opt.is_correct && q.selected_option_id !== opt.id,
                            }"
                        >
                            <McqOptionLine :index="optIndex" :text="opt.option_text" />
                            <span v-if="opt.is_correct"> ✓</span>
                            <span v-if="q.selected_option_id === opt.id && !opt.is_correct"> (your answer)</span>
                        </li>
                    </ul>
                    <p v-if="q.explanation" class="mt-3 rounded bg-gray-50 p-3 text-xs text-gray-600">{{ q.explanation }}</p>
                </div>

                <Link :href="route('dashboard')">
                    <PrimaryButton>Back to dashboard</PrimaryButton>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
