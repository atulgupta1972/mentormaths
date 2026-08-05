<script setup>
import { formatScoreLabel } from '@/utils/scores';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    groups: { type: Array, default: () => [] },
    /** pending | catchup | checking | completed | prep */
    variant: { type: String, default: 'pending' },
    chapterField: { type: String, default: 'chapter_name' },
    countSuffix: { type: String, default: 'pending' },
});

const gridCell = 'border border-slate-300 px-2 py-1 align-middle whitespace-nowrap text-[11px] leading-tight';
const gridHead = 'border border-slate-400 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-800 whitespace-nowrap';

const headBg = {
    pending: 'bg-amber-200 border-amber-400',
    catchup: 'bg-sky-200 border-sky-400',
    checking: 'bg-violet-200 border-violet-400',
    completed: 'bg-emerald-200 border-emerald-400',
    prep: 'bg-violet-200 border-violet-400',
};

const groupHeadBg = {
    pending: 'bg-amber-50 border-amber-200 text-amber-950',
    catchup: 'bg-sky-50 border-sky-200 text-sky-950',
    checking: 'bg-violet-50 border-violet-200 text-violet-950',
    completed: 'bg-emerald-50 border-emerald-200 text-emerald-950',
    prep: 'bg-violet-50 border-violet-100 text-violet-950',
};

const rowBg = (index) => (index % 2 === 0 ? 'bg-white' : 'bg-slate-50');

const chapterLabel = (group) => group[props.chapterField] || 'Other';

const formatDate = (d) => {
    if (!d) {
        return '—';
    }

    const value = String(d).includes('T') ? d : `${d}T00:00:00`;

    return new Date(value).toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const topicLabel = (set) => {
    if (set.topic_name) {
        return set.topic_name;
    }

    if (set.scope === 'chapter') {
        return set.kind_label?.includes('Test') ? 'Chapter test' : 'Chapter practice';
    }

    return '—';
};

const setLabel = (set) => set.set_code || `Set ${set.set_number}`;

const typeLabel = (set) => set.kind_label || (set.scope === 'chapter' ? 'Test' : 'Practice');

const assignmentHref = (set) => (
    set.delivery_mode === 'written'
        ? route('student.written-assignments.show', set.assignment_id)
        : route('student.assignments.show', set.assignment_id)
);

const completedAssignmentHref = (set) => {
    if (set.delivery_mode === 'written') {
        return route('student.written-assignments.show', set.assignment_id);
    }

    return set.latest_attempt_id
        ? route('student.attempts.result', set.latest_attempt_id)
        : route('student.assignments.show', set.assignment_id);
};

const prepAssignmentHref = (prep) => (
    prep.delivery_mode === 'written'
        ? route('student.written-assignments.show', prep.assignment_id)
        : route('student.assignments.show', prep.assignment_id)
);

const pendingBadgeClass = (set) => {
    if (set.is_overdue) {
        return 'bg-rose-600 text-white';
    }
    if (set.status === 'yellow') {
        return 'bg-amber-500 text-white';
    }

    return 'bg-sky-600 text-white';
};

const pendingStatusLabel = (set) => {
    if (set.is_overdue) {
        return 'Overdue';
    }
    if (set.status === 'yellow') {
        return 'In progress';
    }

    return 'To do';
};

const pendingButtonClass = (set) => {
    if (set.is_overdue) {
        return 'bg-rose-700 hover:bg-rose-800';
    }

    return 'bg-indigo-700 hover:bg-indigo-800';
};

const pendingButtonLabel = (set) => {
    if (set.delivery_mode === 'written') {
        if (set.written_submission_status === 'processing') {
            return 'Checking…';
        }

        if (set.written_submission_status === 'uploaded') {
            return 'Uploaded';
        }

        if (set.written_submission_status === 'failed') {
            return 'View / upload';
        }

        return set.written_submission_status === 'graded' ? 'View / re-upload' : 'Upload';
    }

    if (set.status === 'yellow') {
        return 'Continue';
    }
    if (set.is_overdue) {
        return 'Complete now';
    }

    return 'Start';
};

const prepStatusClass = (prep) => {
    if (prep.is_overdue) {
        return 'bg-rose-600 text-white';
    }
    if (prep.status === 'done' || prep.progress_label?.toLowerCase().includes('done')) {
        return 'bg-emerald-600 text-white';
    }

    return 'bg-amber-500 text-white';
};

const completedLinkLabel = (set) => {
    if (set.delivery_mode === 'written' && set.written_submission_status === 'graded') {
        return 'View / re-upload';
    }

    return 'Open';
};

const scoreLabel = (set) => set.latest_score_label || formatScoreLabel(set.latest_score, set.latest_max_score);
</script>

<template>
    <div class="space-y-2">
        <section
            v-for="group in groups"
            :key="`${variant}-${chapterLabel(group)}`"
            class="overflow-hidden rounded-md border-2 border-slate-300 bg-white shadow-sm"
        >
            <div
                class="border-b px-2 py-1 text-[11px] font-bold"
                :class="groupHeadBg[variant] || groupHeadBg.pending"
            >
                {{ chapterLabel(group) }}
                <span class="font-semibold opacity-80">· {{ group.sets.length }} {{ countSuffix }}</span>
                <span
                    v-if="variant === 'prep' && group.pending_count"
                    class="font-semibold text-rose-700"
                >
                    · {{ group.pending_count }} pending
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-max min-w-full border-collapse table-auto text-[11px] leading-tight">
                    <thead>
                        <tr>
                            <template v-if="variant === 'catchup'">
                                <th :class="[gridHead, headBg.catchup, 'text-left']">Set</th>
                                <th :class="[gridHead, headBg.catchup, 'text-left']">Topic</th>
                                <th :class="[gridHead, headBg.catchup, 'text-left']">Due</th>
                                <th :class="[gridHead, headBg.catchup, 'text-left']">Status</th>
                                <th :class="[gridHead, headBg.catchup, 'text-right']">Action</th>
                            </template>
                            <template v-else-if="variant === 'checking'">
                                <th :class="[gridHead, headBg.checking, 'text-left']">Topic</th>
                                <th :class="[gridHead, headBg.checking, 'text-left']">Set</th>
                                <th :class="[gridHead, headBg.checking, 'text-left']">Type</th>
                                <th :class="[gridHead, headBg.checking, 'text-left']">Submitted</th>
                                <th :class="[gridHead, headBg.checking, 'text-right']">View</th>
                            </template>
                            <template v-else-if="variant === 'completed'">
                                <th :class="[gridHead, headBg.completed, 'text-left']">Topic</th>
                                <th :class="[gridHead, headBg.completed, 'text-left']">Set</th>
                                <th :class="[gridHead, headBg.completed, 'text-left']">Type</th>
                                <th :class="[gridHead, headBg.completed, 'text-left']">Score</th>
                                <th :class="[gridHead, headBg.completed, 'text-right']">View</th>
                            </template>
                            <template v-else-if="variant === 'prep'">
                                <th :class="[gridHead, headBg.prep, 'text-left']">Set</th>
                                <th :class="[gridHead, headBg.prep, 'text-left']">Topic</th>
                                <th :class="[gridHead, headBg.prep, 'text-left']">Status</th>
                                <th :class="[gridHead, headBg.prep, 'text-right']">Action</th>
                            </template>
                            <template v-else>
                                <th :class="[gridHead, headBg.pending, 'text-left']">Topic</th>
                                <th :class="[gridHead, headBg.pending, 'text-left']">Set</th>
                                <th :class="[gridHead, headBg.pending, 'text-left']">Type</th>
                                <th :class="[gridHead, headBg.pending, 'text-left']">Due</th>
                                <th :class="[gridHead, headBg.pending, 'text-left']">Status</th>
                                <th :class="[gridHead, headBg.pending, 'text-right']">Action</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(set, index) in group.sets"
                            :key="set.assignment_id"
                            :class="[rowBg(index), 'font-semibold hover:brightness-95']"
                        >
                            <template v-if="variant === 'catchup'">
                                <td :class="[gridCell, 'font-mono font-bold text-slate-900']">{{ setLabel(set) }}</td>
                                <td :class="[gridCell, 'text-slate-800']">{{ topicLabel(set) }}</td>
                                <td :class="[gridCell, 'text-slate-700']">{{ formatDate(set.target_date) }}</td>
                                <td :class="gridCell">
                                    <span class="rounded px-1.5 py-px text-[9px] font-bold uppercase" :class="pendingBadgeClass(set)">
                                        {{ pendingStatusLabel(set) }}
                                    </span>
                                </td>
                                <td :class="[gridCell, 'text-right']">
                                    <Link
                                        :href="assignmentHref(set)"
                                        class="inline-block rounded px-2 py-px text-[10px] font-bold text-white"
                                        :class="pendingButtonClass(set)"
                                    >
                                        {{ pendingButtonLabel(set) }}
                                    </Link>
                                </td>
                            </template>

                            <template v-else-if="variant === 'checking'">
                                <td :class="[gridCell, 'text-slate-800']">{{ topicLabel(set) }}</td>
                                <td :class="[gridCell, 'font-mono font-bold text-slate-900']">{{ setLabel(set) }}</td>
                                <td :class="[gridCell, 'text-slate-700']">{{ typeLabel(set) }}</td>
                                <td :class="[gridCell, 'text-slate-700']">{{ formatDate(set.submitted_at) }}</td>
                                <td :class="[gridCell, 'text-right']">
                                    <Link
                                        :href="assignmentHref(set)"
                                        class="inline-block rounded bg-violet-700 px-2 py-px text-[10px] font-bold text-white hover:bg-violet-800"
                                    >
                                        View upload
                                    </Link>
                                </td>
                            </template>

                            <template v-else-if="variant === 'completed'">
                                <td :class="[gridCell, 'text-slate-800']">{{ topicLabel(set) }}</td>
                                <td :class="[gridCell, 'font-mono font-bold text-emerald-900']">{{ setLabel(set) }}</td>
                                <td :class="[gridCell, 'text-slate-700']">{{ typeLabel(set) }}</td>
                                <td :class="[gridCell, 'font-bold text-emerald-800']">{{ scoreLabel(set) }}</td>
                                <td :class="[gridCell, 'text-right']">
                                    <Link
                                        :href="completedAssignmentHref(set)"
                                        class="text-[10px] font-bold text-emerald-700 hover:text-emerald-900 hover:underline"
                                    >
                                        {{ completedLinkLabel(set) }}
                                    </Link>
                                </td>
                            </template>

                            <template v-else-if="variant === 'prep'">
                                <td :class="[gridCell, 'font-mono font-bold text-slate-900']">{{ set.set_code }}</td>
                                <td :class="[gridCell, 'text-slate-800']">{{ set.topic_name || set.kind_label || '—' }}</td>
                                <td :class="gridCell">
                                    <span class="rounded px-1.5 py-px text-[9px] font-bold uppercase" :class="prepStatusClass(set)">
                                        {{ set.progress_label }}
                                    </span>
                                </td>
                                <td :class="[gridCell, 'text-right']">
                                    <Link
                                        :href="prepAssignmentHref(set)"
                                        class="text-[10px] font-bold text-violet-700 hover:text-violet-950 hover:underline"
                                    >
                                        Open
                                    </Link>
                                </td>
                            </template>

                            <template v-else>
                                <td :class="[gridCell, 'text-slate-800']">{{ topicLabel(set) }}</td>
                                <td :class="[gridCell, 'font-mono font-bold text-slate-900']">{{ setLabel(set) }}</td>
                                <td :class="[gridCell, 'text-slate-700']">{{ typeLabel(set) }}</td>
                                <td :class="[gridCell, 'text-slate-700']">{{ formatDate(set.target_date) }}</td>
                                <td :class="gridCell">
                                    <span class="rounded px-1.5 py-px text-[9px] font-bold uppercase" :class="pendingBadgeClass(set)">
                                        {{ pendingStatusLabel(set) }}
                                    </span>
                                </td>
                                <td :class="[gridCell, 'text-right']">
                                    <Link
                                        :href="assignmentHref(set)"
                                        class="inline-block rounded px-2 py-px text-[10px] font-bold text-white"
                                        :class="pendingButtonClass(set)"
                                    >
                                        {{ pendingButtonLabel(set) }}
                                    </Link>
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
