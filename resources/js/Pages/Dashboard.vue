<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    isAdmin: { type: Boolean, default: false },
    assignments: { type: Array, default: () => [] },
    activeYear: Object,
});

const pendingAssignments = computed(() =>
    props.assignments.filter((a) => a.status !== 'green' && a.status !== 'green-late'),
);

const completedAssignments = computed(() =>
    props.assignments.filter((a) => a.status === 'green' || a.status === 'green-late'),
);

const formatDate = (d) => {
    if (!d) {
        return '';
    }

    return new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
};

const formatTime = (seconds) => {
    if (!seconds) {
        return '';
    }

    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;

    return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
};

const scorePercent = (set) => {
    if (!set.latest_max_score) {
        return null;
    }

    return Math.round((set.latest_score / set.latest_max_score) * 100);
};

const setLabel = (set) => set.set_code || `Set ${set.set_number}`;

const pendingBorderClass = (set) => {
    if (set.is_overdue) {
        return 'border-red-300 ring-1 ring-red-200';
    }
    if (set.status === 'yellow') {
        return 'border-amber-300 ring-1 ring-amber-200';
    }

    return 'border-indigo-200 hover:border-indigo-400';
};

const pendingBadgeClass = (set) => {
    if (set.is_overdue) {
        return 'bg-red-100 text-red-800';
    }
    if (set.status === 'yellow') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-indigo-100 text-indigo-800';
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
        return 'bg-red-600 hover:bg-red-700';
    }

    return 'bg-indigo-600 hover:bg-indigo-700';
};

const pendingButtonLabel = (set) => {
    if (set.status === 'yellow') {
        return 'Continue';
    }
    if (set.is_overdue) {
        return 'Complete now';
    }

    return 'Start';
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Dashboard</h2>
                <p v-if="!isAdmin && activeYear" class="text-sm text-gray-500">{{ activeYear.name }}</p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-8 sm:px-6 lg:px-8">
                <div v-if="isAdmin" class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <p>Welcome, {{ $page.props.auth.user.name }}.</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <Link :href="route('admin.classes.index')" class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white">Classes</Link>
                        <Link :href="route('admin.practice-sets.index')" class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white">Practice sets</Link>
                    </div>
                </div>

                <template v-else>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <p class="text-lg font-medium text-gray-900">Welcome, {{ $page.props.auth.user.name }}</p>
                        <p class="mt-1 text-sm text-gray-500">Your assigned sets appear below. Open a set to work on the sheet.</p>
                    </div>

                    <div v-if="assignments.length === 0" class="rounded-lg bg-white p-10 text-center text-gray-500 shadow-sm">
                        No sets assigned yet. Check back after your teacher assigns work.
                    </div>

                    <section v-if="pendingAssignments.length">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">
                                To do · {{ pendingAssignments.length }}
                            </h3>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <div
                                v-for="set in pendingAssignments"
                                :key="set.assignment_id"
                                class="flex aspect-square flex-col rounded-xl border bg-white p-4 shadow-sm transition"
                                :class="pendingBorderClass(set)"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                        :class="pendingBadgeClass(set)"
                                    >
                                        {{ pendingStatusLabel(set) }}
                                    </span>
                                    <span class="text-[10px] font-medium uppercase text-gray-400">
                                        {{ set.kind_label || (set.scope === 'chapter' ? 'Test' : 'Practice') }}
                                    </span>
                                </div>

                                <div class="mt-3 flex flex-1 flex-col justify-center">
                                    <p class="font-mono text-3xl font-bold tracking-wide text-gray-900">
                                        {{ setLabel(set) }}
                                    </p>
                                    <p v-if="set.target_date" class="mt-4 text-xs" :class="set.is_overdue ? 'font-medium text-red-600' : 'text-gray-500'">
                                        Due {{ formatDate(set.target_date) }}
                                    </p>
                                </div>

                                <Link
                                    :href="route('student.assignments.show', set.assignment_id)"
                                    class="mt-3 block w-full rounded-lg py-2 text-center text-sm font-medium text-white"
                                    :class="pendingButtonClass(set)"
                                >
                                    {{ pendingButtonLabel(set) }}
                                </Link>
                            </div>
                        </div>
                    </section>

                    <section v-else-if="completedAssignments.length" class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm text-gray-500">
                        All caught up — no pending work right now.
                    </section>

                    <section v-if="completedAssignments.length">
                        <div class="mb-4">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-green-800">
                                Completed · {{ completedAssignments.length }}
                            </h3>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <Link
                                v-for="set in completedAssignments"
                                :key="`done-${set.assignment_id}`"
                                :href="route('student.assignments.show', set.assignment_id)"
                                class="flex aspect-square flex-col rounded-xl border border-green-300 bg-green-50 p-4 shadow-sm transition hover:border-green-500 hover:bg-green-100"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <span class="rounded-full bg-green-200 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-green-900">
                                        Done
                                    </span>
                                    <span class="text-[10px] font-medium uppercase text-green-700">
                                        {{ set.kind_label || (set.scope === 'chapter' ? 'Test' : 'Practice') }}
                                    </span>
                                </div>

                                <div class="mt-3 flex flex-1 flex-col justify-center">
                                    <p class="font-mono text-3xl font-bold tracking-wide text-green-900">
                                        {{ setLabel(set) }}
                                    </p>
                                    <p class="mt-4 text-lg font-bold text-green-800">
                                        {{ set.latest_score }}/{{ set.latest_max_score }}
                                        <span v-if="scorePercent(set) !== null" class="text-sm font-semibold">
                                            ({{ scorePercent(set) }}%)
                                        </span>
                                    </p>
                                    <p class="mt-1 text-xs text-green-800">
                                        <span v-if="set.submission_timing === 'late'">Submitted late</span>
                                        <span v-else>On time</span>
                                        <span v-if="set.latest_time_seconds"> · {{ formatTime(set.latest_time_seconds) }}</span>
                                    </p>
                                </div>

                                <span class="mt-3 block w-full rounded-lg border border-green-400 bg-white py-2 text-center text-sm font-medium text-green-800">
                                    View result
                                </span>
                            </Link>
                        </div>
                    </section>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
