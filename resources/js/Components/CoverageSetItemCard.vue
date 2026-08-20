<script setup>
import { formatDate } from '@/utils/dates';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    item: { type: Object, required: true },
    groupKey: { type: String, default: '' },
    isStudentView: { type: Boolean, default: false },
    canStaffAssign: { type: Boolean, default: false },
    assigningWorksheetId: { type: [Number, String, null], default: null },
    pendingAssignKey: { type: String, default: null },
    staffAssignForm: { type: Object, default: null },
});

const emit = defineEmits([
    'self-assign',
    'start-correction',
    'open-staff-assign',
    'confirm-staff-assign',
    'cancel-staff-assign',
]);

const questionSuffix = computed(() => {
    const count = Number(props.item.question_count ?? 0);

    return count > 0 ? ` (${count})` : '';
});

const statusClass = computed(() => ({
    done: 'bg-emerald-200 text-emerald-950',
    checking: 'bg-amber-200 text-amber-950',
    overdue: 'bg-rose-200 text-rose-950',
    in_progress: 'bg-sky-200 text-sky-950',
    pending: 'bg-slate-200 text-slate-800',
    not_assigned: 'bg-slate-100 text-slate-700',
    correction_pending: 'bg-orange-200 text-orange-950',
    published: 'bg-emerald-200 text-emerald-950',
    draft: 'bg-amber-200 text-amber-950',
}[props.item.status] ?? 'bg-slate-200 text-slate-800'));

const itemKey = computed(() => `${props.groupKey}-${props.item.worksheet_id}`);

const canAssign = computed(() =>
    props.canStaffAssign && props.item.worksheet_id && ! props.item.is_correction,
);

const isPending = computed(() => props.pendingAssignKey === itemKey.value);

const itemHref = computed(() => {
    if (! props.isStudentView || ! props.item.can_open) {
        return null;
    }

    if (props.item.latest_attempt_id && route().has('student.attempts.show')) {
        return route('student.attempts.show', props.item.latest_attempt_id);
    }

    if (props.item.assignment_id && route().has('student.assignments.show')) {
        return route('student.assignments.show', props.item.assignment_id);
    }

    return null;
});
</script>

<template>
    <div class="inline-flex flex-wrap items-center gap-1.5 rounded-md border-2 border-slate-400 bg-white px-2 py-1 shadow-sm">
        <span class="font-mono text-[11px] font-extrabold text-slate-950">
            {{ item.short_label }}<span class="font-bold text-slate-600">{{ questionSuffix }}</span>
        </span>
        <span
            class="rounded px-1.5 py-px text-[10px] font-extrabold uppercase"
            :class="statusClass"
        >
            {{ item.status_label }}
        </span>
        <span
            v-if="item.target_date"
            class="text-[10px] font-bold text-slate-700"
        >
            due {{ formatDate(item.target_date) }}
        </span>
        <span
            v-if="item.correction_count > 0 && !item.is_correction"
            class="rounded bg-orange-200 px-1.5 py-px text-[10px] font-extrabold uppercase text-orange-950"
        >
            {{ item.correction_count }} wrong
        </span>
        <template v-if="isStudentView">
            <button
                v-if="item.can_redo_wrong"
                type="button"
                class="rounded bg-orange-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-orange-800 disabled:opacity-50"
                :disabled="assigningWorksheetId === item.worksheet_id"
                @click.stop="emit('start-correction', item)"
            >
                Redo wrong
            </button>
            <button
                v-if="item.can_assign"
                type="button"
                class="rounded bg-sky-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-sky-800 disabled:opacity-50"
                :disabled="assigningWorksheetId === item.worksheet_id"
                @click.stop="emit('self-assign', item)"
            >
                {{ item.status === 'done' ? 'Redo' : 'Assign me' }}
            </button>
            <Link
                v-else-if="itemHref"
                :href="itemHref"
                class="rounded bg-emerald-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-emerald-800"
                @click.stop
            >
                Open
            </Link>
        </template>
        <template v-else-if="canAssign">
            <button
                v-if="!isPending"
                type="button"
                class="rounded bg-sky-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-sky-800"
                @click.stop="emit('open-staff-assign', item, groupKey)"
            >
                {{ item.status === 'not_assigned' ? 'Assign' : 'Reassign' }}
            </button>
            <form
                v-else-if="staffAssignForm"
                class="inline-flex items-center gap-1"
                @submit.prevent="emit('confirm-staff-assign', item)"
            >
                <input
                    v-model="staffAssignForm.target_date"
                    type="date"
                    class="rounded border-slate-300 px-1 py-0.5 text-[11px]"
                    required
                >
                <button
                    type="submit"
                    class="rounded bg-emerald-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-emerald-800 disabled:opacity-50"
                    :disabled="staffAssignForm.processing"
                >
                    {{ staffAssignForm.processing ? 'Saving…' : 'Save' }}
                </button>
                <button
                    type="button"
                    class="text-[10px] text-slate-500 hover:underline"
                    @click.stop="emit('cancel-staff-assign')"
                >
                    Cancel
                </button>
            </form>
        </template>
        <Link
            v-else-if="item.admin_url"
            :href="item.admin_url"
            class="rounded bg-indigo-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-indigo-800"
            @click.stop
        >
            Open
        </Link>
    </div>
</template>
