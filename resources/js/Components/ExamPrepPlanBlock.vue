<script setup>
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
        return 'Published · awaiting assign';
    }

    return 'Draft · review then publish';
};

const canGenerate = (preview) => Boolean(preview?.available > 0);
</script>

<template>
    <div
        class="border border-amber-300 bg-amber-50"
        :class="compact ? 'rounded-md px-2.5 py-2' : 'rounded-lg px-4 py-3'"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <p
                    class="font-semibold uppercase tracking-wide text-amber-950"
                    :class="compact ? 'text-[10px]' : 'text-[11px]'"
                >
                    Exam prep (from wrongs)
                </p>
                <p class="mt-0.5 text-xs text-amber-950/80">
                    Build combined tests from this student’s failures on exam chapters
                    (fill-blank first; MCQ only if needed). Max 25 questions per set, up to 3 sets.
                </p>
                <p
                    v-if="isAdminContext"
                    class="mt-1.5 text-[11px] font-medium text-amber-900"
                >
                    Steps: 1) Generate → 2) Review → 3) Publish for student
                </p>
            </div>

            <button
                v-if="isAdminContext"
                type="button"
                class="shrink-0 rounded-md bg-amber-800 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white shadow-sm hover:bg-amber-900 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="generating || !canGenerate(preview)"
                @click="emit('generate')"
            >
                {{ generating ? 'Generating…' : (sets.length ? 'Generate more' : '1. Generate test') }}
            </button>
        </div>

        <p
            v-if="isAdminContext && preview"
            class="mt-2 text-xs text-amber-950"
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

        <div
            v-if="isAdminContext && !sets.length && canGenerate(preview)"
            class="mt-2 rounded border border-dashed border-amber-400 bg-white/70 px-2.5 py-2 text-xs text-amber-950"
        >
            Click <strong>1. Generate test</strong> to create the draft set(s). Then use
            <strong>2. Review</strong> and <strong>3. Publish for student</strong> below.
        </div>

        <ul
            v-if="isAdminContext && sets.length"
            class="mt-3 space-y-2"
        >
            <li
                v-for="set in sets"
                :key="set.id"
                class="rounded-md border border-amber-300 bg-white px-3 py-2.5 text-xs shadow-sm"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0">
                        <span class="font-semibold text-slate-900">{{ set.set_code }}</span>
                        <span class="text-slate-600">
                            · {{ set.questions_count }} Q · {{ statusLabel(set) }}
                        </span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a
                            v-if="set.review_url"
                            :href="set.review_url"
                            class="inline-flex items-center rounded-md border border-indigo-300 bg-indigo-50 px-2.5 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-indigo-800 hover:bg-indigo-100"
                            target="_blank"
                            rel="noopener"
                        >
                            2. Review
                        </a>
                        <button
                            v-if="!set.assignment_id"
                            type="button"
                            class="inline-flex items-center rounded-md bg-emerald-700 px-2.5 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="approvingId === set.id"
                            @click="emit('approve', set)"
                        >
                            {{ approvingId === set.id ? 'Publishing…' : '3. Publish for student' }}
                        </button>
                        <span
                            v-else
                            class="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-800"
                        >
                            On student card
                        </span>
                    </div>
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
