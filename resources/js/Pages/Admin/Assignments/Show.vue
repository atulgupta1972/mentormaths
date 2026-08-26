<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import { formatDate, formatDateTime, formatTime } from '@/utils/dates';
import { formatScoreLabel } from '@/utils/scores';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    assignment: Object,
    homeChapters: { type: Array, default: () => [] },
    attempts: Array,
    latestResult: { type: Object, default: null },
    tabLeaveLockLimit: { type: Number, default: 4 },
});

const page = usePage();
const unlockForm = useForm({});
const chapterForm = useForm({
    effective_syllabus_chapter_id: props.assignment.effective_syllabus_chapter_id || '',
});

watch(
    () => props.assignment.effective_syllabus_chapter_id,
    (value) => {
        chapterForm.effective_syllabus_chapter_id = value || '';
    },
);

const timingLabel = (t) => (t === 'late' ? 'Delayed submission' : t === 'on_time' ? 'On time' : '—');

const outcomeClass = (outcome) => {
    if (outcome === 'correct') {
        return 'bg-green-50 text-green-800';
    }

    if (outcome === 'gave_up') {
        return 'bg-rose-50 text-rose-800';
    }

    if (outcome === 'corrected_after_help') {
        return 'bg-amber-50 text-amber-800';
    }

    return 'bg-red-50 text-red-800';
};

const needsReview = (question) => question.outcome !== 'correct';

const lockLimit = computed(() => props.tabLeaveLockLimit || 4);

const unlockAttempt = (attempt) => {
    if (!window.confirm(`Unlock attempt #${attempt.attempt_number}? Tab leaves will reset to 0 so the student can continue.`)) {
        return;
    }

    unlockForm.post(route('admin.set-attempts.unlock', attempt.id), {
        preserveScroll: true,
    });
};

const saveEffectiveChapter = () => {
    chapterForm
        .transform((data) => ({
            effective_syllabus_chapter_id: data.effective_syllabus_chapter_id || null,
        }))
        .post(route('admin.set-assignments.effective-chapter', props.assignment.assignment_id), {
            preserveScroll: true,
        });
};
</script>

<template>
    <Head :title="`${assignment.set_code} — ${assignment.student_name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">
                    <span class="font-mono text-indigo-600">{{ assignment.set_code }}</span>
                    · {{ assignment.student_name }}
                </h2>
                <p class="text-sm text-gray-500">
                    {{ assignment.display_title }}
                    <span v-if="assignment.student_class"> · {{ assignment.student_class }}</span>
                </p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
                >
                    {{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash?.error"
                    class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                >
                    {{ page.props.flash.error }}
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Sheet chapter (source)</p>
                        <p class="text-lg font-semibold">
                            {{ assignment.source_chapter_label || assignment.chapter_name || '—' }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Topic</p>
                        <p class="text-lg font-semibold">{{ assignment.topic_name || '—' }}</p>
                    </div>
                </div>

                <div class="rounded-lg border border-indigo-200 bg-indigo-50/60 p-4 shadow-sm">
                    <p class="text-sm font-semibold text-indigo-950">Count under student study-plan chapter</p>
                    <p class="mt-1 text-xs text-indigo-900">
                        When the sheet is from another board/class (e.g. ICSE), map it to this student’s CBSE chapter for study plan and scores.
                        Leave on Auto if names match; override if the system picked wrong.
                    </p>
                    <div class="mt-3 flex flex-wrap items-end gap-2">
                        <div class="min-w-[14rem] flex-1">
                            <label class="block text-xs font-medium text-indigo-900">Student syllabus chapter</label>
                            <select
                                v-model="chapterForm.effective_syllabus_chapter_id"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            >
                                <option value="">Auto (name / chapter-head match)</option>
                                <option
                                    v-for="chapter in homeChapters"
                                    :key="chapter.id"
                                    :value="chapter.id"
                                >
                                    {{ chapter.label }}
                                </option>
                            </select>
                        </div>
                        <button
                            type="button"
                            class="rounded-md bg-indigo-700 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-800 disabled:opacity-50"
                            :disabled="chapterForm.processing"
                            @click="saveEffectiveChapter"
                        >
                            {{ chapterForm.processing ? 'Saving…' : 'Save chapter' }}
                        </button>
                    </div>
                    <p v-if="assignment.resolved_chapter_label" class="mt-2 text-xs text-indigo-800">
                        Currently counting under:
                        <span class="font-semibold">{{ assignment.resolved_chapter_label }}</span>
                    </p>
                    <p v-if="chapterForm.errors.effective_syllabus_chapter_id" class="mt-1 text-xs text-rose-700">
                        {{ chapterForm.errors.effective_syllabus_chapter_id }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Target date</p>
                        <p class="text-lg font-semibold">{{ formatDate(assignment.target_date) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Submitted</p>
                        <p class="text-lg font-semibold">
                            {{ assignment.submitted_at ? formatDateTime(assignment.submitted_at) : 'Not yet' }}
                        </p>
                        <p v-if="assignment.submission_timing === 'late'" class="text-sm text-amber-700">Delayed submission</p>
                        <p v-else-if="assignment.submitted_at" class="text-sm text-green-700">On time</p>
                    </div>
                </div>

                <div v-if="assignment.latest_score != null" class="rounded-lg bg-indigo-50 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p class="text-3xl font-bold text-indigo-700">{{ assignment.latest_score_label || formatScoreLabel(assignment.latest_score, assignment.latest_max_score) }}</p>
                            <p class="text-sm text-gray-600">Latest score</p>
                        </div>
                        <div v-if="assignment.latest_time_seconds" class="text-right">
                            <p class="text-xl font-semibold text-indigo-700">{{ formatTime(assignment.latest_time_seconds) }}</p>
                            <p class="text-sm text-gray-600">Time taken</p>
                        </div>
                    </div>
                </div>

                <div v-if="latestResult" class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="border-b bg-gray-50 px-4 py-3">
                        <h3 class="text-sm font-semibold text-gray-800">Question breakdown</h3>
                        <p v-if="latestResult.is_guided" class="mt-1 text-xs text-gray-500">
                            {{ latestResult.first_try_correct }} first try ·
                            {{ latestResult.corrected_after_help }} after help ·
                            {{ latestResult.given_up }} given up
                        </p>
                        <p v-else class="mt-1 text-xs text-gray-500">
                            {{ latestResult.wrong_questions.length }} need review ·
                            {{ latestResult.questions.length - latestResult.wrong_questions.length }} correct
                        </p>
                    </div>
                    <div class="divide-y">
                        <div
                            v-for="question in latestResult.questions"
                            :key="question.number"
                            class="px-4 py-3"
                            :class="outcomeClass(question.outcome)"
                        >
                            <p class="font-semibold">Q{{ question.number }} · {{ question.outcome_label }}</p>
                            <p v-if="question.topic_name" class="mt-1 text-sm opacity-90">
                                Topic: {{ question.topic_name }}
                                <span v-if="question.chapter_name">({{ question.chapter_name }})</span>
                            </p>
                            <p v-else-if="question.chapter_name" class="mt-1 text-sm opacity-90">
                                Chapter: {{ question.chapter_name }}
                            </p>

                            <div v-if="question.question_text" class="mt-2 text-sm text-gray-900">
                                <QuestionBody
                                    :question-text="question.question_text"
                                    :diagram-url="question.diagram_url"
                                    :compact="true"
                                />
                            </div>

                            <dl
                                v-if="needsReview(question) && (question.student_answer || question.correct_answer)"
                                class="mt-2 grid gap-1 text-sm sm:grid-cols-2"
                            >
                                <div v-if="question.student_answer">
                                    <dt class="text-xs text-gray-600">Wrong attempt</dt>
                                    <dd class="font-medium text-rose-900">{{ question.student_answer }}</dd>
                                </div>
                                <div v-if="question.correct_answer">
                                    <dt class="text-xs text-gray-600">Correct answer</dt>
                                    <dd class="font-medium text-emerald-900">{{ question.correct_answer }}</dd>
                                </div>
                            </dl>
                            <p
                                v-else-if="needsReview(question) && !question.student_answer"
                                class="mt-2 text-xs text-gray-600"
                            >
                                No recorded wrong answer text for this sum.
                            </p>

                            <p v-if="question.help_asked_label" class="mt-2 text-xs text-amber-900">
                                {{ question.help_asked_label }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-4 py-3">
                        <h3 class="text-sm font-semibold text-gray-900">Attempts</h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Locked after {{ lockLimit }} tab/app leaves. Click Unlock to reset leaves so the student can continue.
                        </p>
                    </div>
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Attempt</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Score</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Time</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Submitted</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Tab leaves</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Timing</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="att in attempts" :key="att.id">
                                <td class="px-4 py-3">#{{ att.attempt_number }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="att.status === 'submitted'">{{ formatScoreLabel(att.score, att.max_score) }}</span>
                                    <span v-else class="text-yellow-700">In progress</span>
                                </td>
                                <td class="px-4 py-3">{{ formatTime(att.time_seconds) }}</td>
                                <td class="px-4 py-3">{{ att.completed_at ? formatDateTime(att.completed_at) : '—' }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        :class="att.locked
                                            ? 'font-semibold text-rose-700'
                                            : att.tab_leave_count > 0
                                                ? 'font-medium text-amber-700'
                                                : 'text-gray-500'"
                                    >
                                        {{ att.tab_leave_count ?? 0 }}/{{ att.tab_leave_lock_limit || lockLimit }}
                                        <span v-if="att.locked" class="ml-1 text-xs uppercase">locked</span>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="att.submission_timing === 'late' ? 'text-amber-700' : 'text-green-700'">
                                        {{ timingLabel(att.submission_timing) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <button
                                        v-if="att.can_unlock"
                                        type="button"
                                        class="rounded-md bg-rose-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-rose-700 disabled:opacity-50"
                                        :disabled="unlockForm.processing"
                                        @click="unlockAttempt(att)"
                                    >
                                        Unlock
                                    </button>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                            </tr>
                            <tr v-if="attempts.length === 0">
                                <td colspan="7" class="px-4 py-6 text-center text-gray-500">No attempts yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Link href="javascript:history.back()" class="text-sm text-indigo-600 hover:underline">← Back</Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
