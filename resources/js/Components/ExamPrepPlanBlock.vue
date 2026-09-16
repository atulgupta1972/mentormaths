<script setup>
import SecondaryButton from '@/Components/SecondaryButton.vue';

defineProps({
    plan: { type: Object, required: true },
    isAdminContext: { type: Boolean, default: false },
    preview: { type: Object, default: null },
    sets: { type: Array, default: () => [] },
    studentAssignments: { type: Array, default: () => [] },
    generating: { type: Boolean, default: false },
    approvingId: { type: [Number, String], default: null },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['generate', 'approve']);

const studentAssignmentUrl = (assignmentId) => {
    if (!assignmentId || !route().has('student.assignments.show')) {
        return null;
    }

    return route('student.assignments.show', assignmentId);
};

const statusLabel = (set) => {
    if (set.assignment_id) {
        return set.assignment_status === 'completed' ? 'Assigned · done' : 'Assigned · to do';
    }

    if (set.status === 'published') {
        return 'Published · not assigned';
    }

    return 'Draft · needs review';
};
</script>

<template>
    <div
        class="border-t border-amber-200 bg-amber-50/50"
        :class="compact ? 'px-2 py-2' : 'px-4 py-3'"
    >
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <p
                    class="font-semibold uppercase tracking-wide text-amber-900"
                    :class="compact ? 'text-[10px]' : 'text-[11px]'"
                >
                    Exam prep (from wrongs)
                </p>
                <p class="mt-0.5 text-xs text-amber-950/80">
                    Combined fill-in-the-blank test from this student’s failures on exam chapters
                    (max 25 per set, up to 3 sets). MCQ only when fill-blank isn’t available.
                </p>
            </div>
            <PrimaryButton
                v-if="isAdminContext"
                type="button"
                class="!py-1.5 !text-xs"
                :disabled="generating || !(preview?.available > 0)"
                @click="emit('generate')"
            >
                {{ generating ? 'Generating…' : (sets.length ? 'Generate more' : 'Generate exam prep') }}
            </PrimaryButton>
        </div>

        <p
            v-if="isAdminContext && preview"
            class="mt-2 text-xs text-amber-900"
        >
            <template v-if="preview.available > 0">
                {{ preview.available }} wrong sum{{ preview.available === 1 ? '' : 's' }} available
                ({{ preview.fill_blank_count }} fill-blank
                <template v-if="preview.mcq_count"> · {{ preview.mcq_count }} MCQ</template>)
                · suggests {{ preview.suggested_sets }} set{{ preview.suggested_sets === 1 ? '' : 's' }}
                ({{ preview.suggested_questions }} questions).
            </template>
            <template v-else>
                No pending wrongs left for this exam’s chapters
                <template v-if="preview.already_used">
                    ({{ preview.already_used }} already used in exam-prep drafts).
                </template>
                <template v-else>.</template>
            </template>
        </p>

        <ul
            v-if="isAdminContext && sets.length"
            class="mt-2 space-y-1.5"
        >
            <li
                v-for="set in sets"
                :key="set.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-amber-200 bg-white px-2.5 py-1.5 text-xs"
            >
                <div class="min-w-0">
                    <span class="font-semibold text-slate-900">{{ set.set_code }}</span>
                    <span class="text-slate-500"> · {{ set.questions_count }} Q · {{ statusLabel(set) }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        v-if="set.review_url"
                        :href="set.review_url"
                        class="font-medium text-indigo-600 hover:underline"
                        target="_blank"
                        rel="noopener"
                    >
                        Review
                    </a>
                    <PrimaryButton
                        v-if="!set.assignment_id"
                        type="button"
                        class="!py-1 !text-[11px]"
                        :disabled="approvingId === set.id"
                        @click="emit('approve', set)"
                    >
                        {{ approvingId === set.id ? 'Approving…' : 'Approve & assign' }}
                    </PrimaryButton>
                    <span
                        v-else
                        class="text-[11px] font-medium text-emerald-700"
                    >
                        On student card
                    </span>
                </div>
            </li>
        </ul>

        <ul
            v-if="!isAdminContext && studentAssignments.length"
            class="mt-2 space-y-1.5"
        >
            <li
                v-for="prep in studentAssignments"
                :key="prep.assignment_id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-amber-200 bg-white px-2.5 py-1.5 text-xs"
            >
                <div class="min-w-0">
                    <span class="font-semibold text-slate-900">{{ prep.set_code }}</span>
                    <span class="text-slate-500">
                        · {{ prep.questions_count }} Q · {{ prep.progress_label }}
                    </span>
                </div>
                <a
                    v-if="studentAssignmentUrl(prep.assignment_id)"
                    :href="studentAssignmentUrl(prep.assignment_id)"
                    class="font-medium text-indigo-600 hover:underline"
                >
                    {{ prep.progress_label === 'To do' || prep.progress_label === 'In progress' || prep.progress_label === 'Overdue'
                        ? 'Open test'
                        : 'View' }}
                </a>
            </li>
        </ul>

        <p
            v-else-if="!isAdminContext"
            class="mt-2 text-xs text-slate-500"
        >
            Your teacher will add exam-prep tests here after reviewing them.
        </p>
    </div>
</template>
