<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive } from 'vue';

const props = defineProps({
    task: { type: Object, required: true },
    verification: { type: Object, default: null },
    activeSeconds: { type: Number, default: 0 },
    textbookChapterUrl: { type: String, default: '' },
});

const agreeForm = useForm({});
const uploadForm = useForm({});
const submitForm = useForm({});
const completeForm = useForm({ run_id: props.verification?.run_id ?? null });

const checkForms = reactive({});

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;
const formatDuration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m ${s}s`;
};

const verificationSummary = computed(() => props.verification?.summary ?? { total: 0, verified: 0, unverified: 0 });

const initCheckForms = () => {
    if (!props.verification?.questions) {
        return;
    }
    props.verification.questions.forEach((row) => {
        if (!checkForms[row.question_id]) {
            const defaults = row.checks ?? {
                check_text: false,
                check_options: false,
                check_correct: false,
                check_hint: false,
                check_explanation: false,
                check_difficulty: false,
                check_diagram: false,
                diagram_note: 'No diagram needed',
            };
            checkForms[row.question_id] = useForm({
                run_id: props.verification.run_id,
                question_id: row.question_id,
                checks: { ...defaults },
            });
        }
    });
};

initCheckForms();

const saveCheck = (questionId) => {
    checkForms[questionId].post(route('content.tasks.verification-check', props.task.id), {
        preserveScroll: true,
    });
};

const pingSession = () => {
    if (!props.task.can_work) {
        return;
    }
    router.post(route('content.tasks.ping-session', props.task.id), {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['activeSeconds'],
    });
};

let pingTimer;
onMounted(() => {
    pingSession();
    pingTimer = window.setInterval(pingSession, 60000);
});
onUnmounted(() => clearInterval(pingTimer));
</script>

<template>
    <Head :title="`Ch ${task.chapter?.chapter_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        {{ task.chapter?.grade_name }} · Ch {{ task.chapter?.chapter_number }}
                    </h2>
                    <p class="text-sm text-gray-500">{{ task.status_label }} · {{ formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}</p>
                </div>
                <Link :href="route('content.tasks.index')" class="text-sm text-indigo-600 hover:underline">← My tasks</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-4 px-4 sm:px-6">
                <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                    Active time tracked: <strong>{{ formatDuration(activeSeconds) }}</strong> (pauses after 5 min idle).
                </div>

                <div v-if="task.awaiting_agreement" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-700">
                        Admin offered <strong>{{ formatInr(task.offered_amount_inr) }}</strong> for this chapter.
                        Agree to start work.
                    </p>
                    <PrimaryButton class="mt-4" type="button" :disabled="agreeForm.processing" @click="agreeForm.post(route('content.tasks.agree', task.id))">
                        I agree — start work
                    </PrimaryButton>
                </div>

                <div v-else-if="['in_progress', 'uploaded', 'verification_in_progress', 'verified'].includes(task.status)" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-700">
                        Open the chapter page to paste JSON, split into sets, tick each question approved, then save MCQ sets.
                        After that, mark upload complete here and finish the verification checklist.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <a v-if="textbookChapterUrl" :href="textbookChapterUrl" class="text-sm font-medium text-indigo-600 hover:underline">
                            Open textbook chapter →
                        </a>
                        <SecondaryButton
                            v-if="['in_progress', 'uploaded'].includes(task.status)"
                            type="button"
                            :disabled="uploadForm.processing"
                            @click="uploadForm.post(route('content.tasks.mark-uploaded', task.id))"
                        >
                            Mark upload complete
                        </SecondaryButton>
                    </div>
                </div>

                <div v-if="verification" class="space-y-4">
                    <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <p class="text-sm font-semibold text-gray-900">Verification progress</p>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ verificationSummary.verified }} verified · {{ verificationSummary.unverified }} remaining · {{ verificationSummary.total }} total
                        </p>
                        <p v-if="verificationSummary.total === 0" class="mt-2 text-sm text-amber-800">
                            No published MCQ questions found yet. Open the textbook chapter, tick Approved, click Save MCQ sets, then mark upload complete again.
                        </p>
                    </div>

                    <div
                        v-for="row in verification.questions"
                        :key="row.question_id"
                        class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200"
                        :class="row.checks?.is_complete ? 'ring-emerald-200' : ''"
                    >
                        <p class="text-sm font-medium text-gray-900">{{ row.question_text }}</p>
                        <div v-if="checkForms[row.question_id]" class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                            <label v-for="field in ['check_text','check_options','check_correct','check_hint','check_explanation','check_difficulty','check_diagram']" :key="field" class="flex items-center gap-2">
                                <Checkbox v-model:checked="checkForms[row.question_id].checks[field]" @change="saveCheck(row.question_id)" />
                                {{ field.replace('check_', '').replace('_', ' ') }}
                            </label>
                        </div>
                    </div>

                    <div v-if="task.status === 'verification_in_progress' || task.status === 'verified'" class="flex flex-wrap gap-3">
                        <PrimaryButton
                            type="button"
                            :disabled="completeForm.processing || verificationSummary.unverified > 0"
                            @click="completeForm.post(route('content.tasks.complete-verification', task.id))"
                        >
                            All verified — lock verification
                        </PrimaryButton>
                        <PrimaryButton
                            v-if="task.status === 'verified'"
                            type="button"
                            :disabled="submitForm.processing"
                            @click="submitForm.post(route('content.tasks.submit-for-publish', task.id))"
                        >
                            Submit for admin publish
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
