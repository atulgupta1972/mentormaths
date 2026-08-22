<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { formatScoreLabel } from '@/utils/scores';
import { requestAttemptFullscreen } from '@/utils/attemptFullscreen';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    assignment: Object,
});

const startForm = useForm({});
const startError = ref('');
const starting = ref(false);

const setLabel = () =>
    props.assignment.practice_set.set_code
    || `Set ${props.assignment.practice_set.set_number}`;

const kindLabel = () => props.assignment.practice_set.kind_label || 'Practice';

const ensureFullscreen = async () => {
    if (! props.assignment.integrity?.require_fullscreen) {
        return true;
    }

    const ok = await requestAttemptFullscreen();

    if (! ok) {
        startError.value = 'Allow fullscreen to start. Close Gemini / other side panels, then try again.';

        return false;
    }

    return true;
};

const startOrContinue = async () => {
    startError.value = '';
    starting.value = true;

    try {
        if (! await ensureFullscreen()) {
            return;
        }

        if (props.assignment.in_progress_attempt_id) {
            // Inertia visit keeps fullscreen better than a full page reload.
            router.visit(route('student.attempts.show', props.assignment.in_progress_attempt_id));

            return;
        }

        startForm.post(route('student.assignments.start', props.assignment.id), {
            onFinish: () => {
                starting.value = false;
            },
        });
    } finally {
        if (! startForm.processing) {
            starting.value = false;
        }
    }
};

const formatTime = (seconds) => {
    if (!seconds) {
        return '—';
    }

    const m = Math.floor(seconds / 60);
    const s = seconds % 60;

    return m ? `${m}m ${s}s` : `${s}s`;
};

const formatDate = (d) => {
    if (!d) {
        return '—';
    }

    return new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
};

const startLabel = () => {
    if (props.assignment.in_progress_attempt_id) {
        return props.assignment.integrity?.require_fullscreen
            ? 'Continue in fullscreen'
            : 'Continue';
    }

    if (props.assignment.is_overdue) {
        return `Submit delayed ${kindLabel().toLowerCase()}`;
    }

    if (kindLabel() === 'Test') {
        return props.assignment.integrity?.require_fullscreen ? 'Start test (fullscreen)' : 'Start test';
    }

    return props.assignment.integrity?.require_fullscreen ? 'Start practice (fullscreen)' : 'Start practice';
};
</script>

<template>
    <Head :title="setLabel()" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-mono text-2xl font-bold text-indigo-600">{{ setLabel() }}</p>
                    <p class="text-sm text-gray-500">{{ kindLabel() }}</p>
                </div>
                <Link :href="route('dashboard')" class="text-sm text-indigo-600">Dashboard</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap gap-4 text-sm">
                        <div>
                            <p class="text-xs text-gray-500">Target date</p>
                            <p class="font-semibold">{{ formatDate(assignment.target_date) }}</p>
                        </div>
                        <div v-if="assignment.is_overdue" class="self-end rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-800">
                            Past target — you can still submit (will be marked delayed)
                        </div>
                    </div>

                    <p class="mt-4 text-sm text-gray-600">
                        <template v-if="assignment.is_guided">
                            Guided practice: answer one question at a time. After two wrong tries you will see the method.
                            You can retry or give up for your teacher to explain later.
                        </template>
                        <template v-else>
                            Answer all questions and submit when finished.
                        </template>
                    </p>

                    <div
                        v-if="assignment.integrity?.track_tab_leaves || assignment.integrity?.require_fullscreen"
                        class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                    >
                        <p class="font-semibold">Do not switch tabs or apps</p>
                        <p class="mt-1 text-xs leading-relaxed text-amber-900/90">
                            Complete this {{ kindLabel().toLowerCase() }} without leaving Mentor Maths.
                            Stay in fullscreen. Do not open Gemini, ChatGPT, or other tabs.
                            After {{ assignment.integrity?.tab_leave_lock_limit || 4 }} leaves, the attempt locks and your teacher must unlock it.
                        </p>
                    </div>
                    <p v-if="assignment.notes" class="mt-3 rounded bg-amber-50 p-3 text-sm text-amber-900">
                        Teacher note: {{ assignment.notes }}
                    </p>
                    <p v-if="startError" class="mt-3 rounded bg-rose-50 p-3 text-sm text-rose-800">
                        {{ startError }}
                    </p>

                    <div v-if="assignment.attempts.length" class="mt-6">
                        <h3 class="text-sm font-semibold text-gray-800">Your attempts</h3>
                        <ul class="mt-2 space-y-2 text-sm">
                            <li v-for="att in assignment.attempts" :key="att.id" class="flex justify-between rounded border px-3 py-2">
                                <span>Attempt {{ att.attempt_number }}</span>
                                <span v-if="att.status === 'submitted'">
                                    {{ formatScoreLabel(att.score, att.max_score) }} · {{ formatTime(att.time_seconds) }}
                                    <span v-if="att.submission_timing === 'late'" class="text-amber-700">· Delayed</span>
                                    <Link :href="route('student.attempts.result', att.id)" class="ml-2 text-indigo-600">Review & retry wrong sums</Link>
                                </span>
                                <span v-else class="text-yellow-700">In progress</span>
                            </li>
                        </ul>
                    </div>

                    <Link
                        v-if="assignment.status === 'completed' && assignment.latest_attempt_id"
                        :href="route('student.attempts.result', assignment.latest_attempt_id)"
                        class="mt-6 inline-flex"
                    >
                        <PrimaryButton>Review results & retry wrong sums</PrimaryButton>
                    </Link>
                    <PrimaryButton
                        v-else-if="assignment.status !== 'completed' || assignment.in_progress_attempt_id"
                        class="mt-6"
                        :disabled="startForm.processing || starting"
                        @click="startOrContinue"
                    >
                        {{ startForm.processing || starting ? 'Starting…' : startLabel() }}
                    </PrimaryButton>
                    <p v-else class="mt-6 text-sm text-gray-600">
                        Completed. Ask your teacher to re-assign for another attempt.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
