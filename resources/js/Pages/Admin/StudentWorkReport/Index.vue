<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    gradeLevel: { type: Object, default: null },
    boards: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    report: { type: Object, required: true },
});

const page = usePage();
const showOnlyPending = ref(true);
const expandedStudentId = ref(null);
const lastRefreshedAt = ref(new Date());

const reminderForm = useForm({});

let refreshTimer = null;

const refreshReport = () => {
    router.get(route('admin.student-work-report.index'), {
        board_id: props.filters.board_id || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['report', 'filters', 'boards', 'gradeLevel'],
        onSuccess: () => {
            lastRefreshedAt.value = new Date();
        },
    });
};

onMounted(() => {
    refreshTimer = setInterval(refreshReport, 20000);
});

onUnmounted(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
    }
});

const setBoard = (boardId) => {
    router.get(route('admin.student-work-report.index'), {
        board_id: boardId || undefined,
    }, { preserveState: true, replace: true });
};

const sendReminders = () => {
    if (!confirm(`Send pending-work reminder emails to all students in ${props.gradeLevel?.name ?? 'this class'} who have incomplete work? Parents will be CC'd when email is on file.`)) {
        return;
    }

    reminderForm.post(route('admin.student-work-report.send-reminders'), {
        preserveScroll: true,
    });
};

const toggleStudent = (studentId) => {
    expandedStudentId.value = expandedStudentId.value === studentId ? null : studentId;
};

const liveRows = computed(() => props.report.live ?? []);

const studentRows = computed(() => {
    const rows = props.report.students ?? [];

    if (!showOnlyPending.value) {
        return rows;
    }

    return rows.filter((row) => row.pending_count > 0 || (row.live_activities?.length ?? 0) > 0 || row.is_online);
});

const summary = computed(() => props.report.summary ?? {});

const formatWhen = (iso) => {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    return date.toLocaleString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        day: 'numeric',
        month: 'short',
    });
};

const progressPercent = (done, total) => {
    if (!total) {
        return 0;
    }

    return Math.min(100, Math.round((done / total) * 100));
};

const statusClass = (status) => ({
    overdue: 'bg-rose-100 text-rose-900 ring-rose-200',
    pending: 'bg-amber-100 text-amber-900 ring-amber-200',
}[status] ?? 'bg-slate-100 text-slate-700 ring-slate-200');
</script>

<template>
    <Head title="Student work report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Student work report</h2>
                    <p class="text-sm text-gray-500">
                        Live activity and incomplete work for {{ gradeLevel?.name ?? 'selected class' }}.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <PrimaryButton
                        type="button"
                        :disabled="reminderForm.processing || !gradeLevel"
                        @click="sendReminders"
                    >
                        {{ reminderForm.processing ? 'Sending…' : 'Remind all with pending work' }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.warning" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    {{ page.props.flash.warning }}
                </div>

                <div v-if="!gradeLevel" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
                    Select a class from the top bar first, then open this report again.
                </div>

                <template v-else>
                    <div class="flex flex-wrap items-center gap-3 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                        <div v-if="boards.length" class="flex items-center gap-2">
                            <label class="text-sm text-gray-600">Board</label>
                            <select
                                class="rounded-md border-gray-300 text-sm"
                                :value="filters.board_id ?? ''"
                                @change="setBoard($event.target.value || null)"
                            >
                                <option value="">All boards</option>
                                <option v-for="board in boards" :key="board.id" :value="board.id">
                                    {{ board.label }} ({{ board.students_count }})
                                </option>
                            </select>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="showOnlyPending" type="checkbox" class="rounded border-gray-300">
                            Show only students with pending / live work
                        </label>
                        <div class="ml-auto flex flex-wrap gap-3 text-sm text-gray-600">
                            <span><strong>{{ summary.students_live_now ?? 0 }}</strong> working now</span>
                            <span><strong>{{ summary.students_online ?? 0 }}</strong> active (5 min)</span>
                            <span><strong>{{ summary.students_with_pending ?? 0 }}</strong> with pending work</span>
                            <span><strong>{{ summary.total_pending_items ?? 0 }}</strong> pending items</span>
                        </div>
                    </div>

                    <!-- Live activity matrix -->
                    <section class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div class="border-b border-gray-100 bg-sky-50 px-4 py-3">
                            <h3 class="text-sm font-semibold text-sky-950">Live now — students working</h3>
                            <p class="text-xs text-sky-800">
                                Students who answered a question, used a drill, or loaded a page in the last 5 minutes. Auto-refreshes every 20 seconds.
                            </p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-4 py-3">Student</th>
                                        <th class="px-4 py-3">Activity</th>
                                        <th class="px-4 py-3">Progress</th>
                                        <th class="px-4 py-3">Last active</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="row in liveRows" :key="`${row.student_id}-${row.activity_label}-${row.last_active_at}`">
                                        <td class="px-4 py-3">
                                            <Link :href="row.student_url" class="font-medium text-indigo-700 hover:underline">
                                                {{ row.student_name }}
                                            </Link>
                                        </td>
                                        <td class="px-4 py-3">
                                            <Link
                                                v-if="row.assignment_url"
                                                :href="row.assignment_url"
                                                class="text-gray-800 hover:underline"
                                            >
                                                {{ row.activity_label }}
                                            </Link>
                                            <span v-else>{{ row.activity_label }}</span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex min-w-[8rem] items-center gap-2">
                                                <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-200">
                                                    <div
                                                        class="h-full rounded-full bg-sky-500"
                                                        :style="{ width: `${progressPercent(row.progress_done, row.progress_total)}%` }"
                                                    />
                                                </div>
                                                <span class="shrink-0 font-medium tabular-nums">{{ row.progress_label }}</span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-gray-500">{{ formatWhen(row.last_active_at) }}</td>
                                    </tr>
                                    <tr v-if="!liveRows.length">
                                        <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                            No students actively working in the last 5 minutes.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Pending work matrix -->
                    <section class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div class="border-b border-gray-100 bg-amber-50 px-4 py-3">
                            <h3 class="text-sm font-semibold text-amber-950">Incomplete work — pending & partially done</h3>
                            <p class="text-xs text-amber-800">Click a student row to expand items. Progress shows questions done (e.g. 7/20).</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-4 py-3">Student</th>
                                        <th class="px-4 py-3">Online</th>
                                        <th class="px-4 py-3">Live now</th>
                                        <th class="px-4 py-3">Pending</th>
                                        <th class="px-4 py-3">Overdue</th>
                                        <th class="px-4 py-3 text-right">Details</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <template v-for="student in studentRows" :key="student.id">
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3">
                                                <Link :href="student.show_url" class="font-medium text-indigo-700 hover:underline">
                                                    {{ student.name }}
                                                </Link>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span
                                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                                    :class="student.is_online ? 'bg-emerald-100 text-emerald-800 ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-slate-200'"
                                                >
                                                    {{ student.is_online ? 'Yes' : 'No' }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span v-if="student.live_activities?.length" class="text-sky-800">
                                                    {{ student.live_activities[0].activity_label }}
                                                    ({{ student.live_activities[0].progress_label }})
                                                </span>
                                                <span v-else class="text-gray-400">—</span>
                                            </td>
                                            <td class="px-4 py-3 font-medium">{{ student.pending_count }}</td>
                                            <td class="px-4 py-3">
                                                <span :class="student.overdue_count ? 'font-medium text-rose-700' : 'text-gray-400'">
                                                    {{ student.overdue_count || '—' }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                <SecondaryButton
                                                    v-if="student.pending_items?.length"
                                                    type="button"
                                                    class="!px-3 !py-1.5 !text-xs"
                                                    @click="toggleStudent(student.id)"
                                                >
                                                    {{ expandedStudentId === student.id ? 'Hide' : 'Show' }} items
                                                </SecondaryButton>
                                            </td>
                                        </tr>
                                        <tr v-if="expandedStudentId === student.id && student.pending_items?.length">
                                            <td colspan="6" class="bg-slate-50 px-4 py-3">
                                                <div class="overflow-x-auto rounded-md border border-slate-200 bg-white">
                                                    <table class="min-w-full text-sm">
                                                        <thead class="bg-slate-100 text-left text-xs uppercase tracking-wide text-slate-500">
                                                            <tr>
                                                                <th class="px-3 py-2">Work</th>
                                                                <th class="px-3 py-2">Type</th>
                                                                <th class="px-3 py-2">Progress</th>
                                                                <th class="px-3 py-2">Waiting</th>
                                                                <th class="px-3 py-2">Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-slate-100">
                                                            <tr v-for="(item, idx) in student.pending_items" :key="`${student.id}-${item.assignment_id ?? item.title}-${idx}`">
                                                                <td class="px-3 py-2">
                                                                    <Link
                                                                        v-if="item.assignment_id"
                                                                        :href="route('admin.set-assignments.show', item.assignment_id)"
                                                                        class="text-indigo-700 hover:underline"
                                                                    >
                                                                        {{ item.title }}
                                                                    </Link>
                                                                    <span v-else>{{ item.title }}</span>
                                                                    <span v-if="item.chapter_name" class="block text-xs text-gray-500">{{ item.chapter_name }}</span>
                                                                </td>
                                                                <td class="px-3 py-2 text-gray-600">{{ item.kind_label }}</td>
                                                                <td class="px-3 py-2">
                                                                    <div class="flex min-w-[7rem] items-center gap-2">
                                                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-200">
                                                                            <div
                                                                                class="h-full rounded-full"
                                                                                :class="item.started ? 'bg-amber-500' : 'bg-gray-300'"
                                                                                :style="{ width: `${progressPercent(item.progress_done, item.progress_total)}%` }"
                                                                            />
                                                                        </div>
                                                                        <span class="shrink-0 tabular-nums">{{ item.progress_label }}</span>
                                                                    </div>
                                                                </td>
                                                                <td class="px-3 py-2 text-gray-600">{{ item.pending_days_label }}</td>
                                                                <td class="px-3 py-2">
                                                                    <span
                                                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                                                        :class="statusClass(item.status)"
                                                                    >
                                                                        {{ item.is_overdue ? 'Overdue' : 'Pending' }}
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr v-if="!studentRows.length">
                                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                            No students with pending or live work for this class.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
