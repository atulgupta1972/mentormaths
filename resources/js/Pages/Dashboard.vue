<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    isAdmin: { type: Boolean, default: false },
    topics: { type: Array, default: () => [] },
    activeYear: Object,
});

const statusDot = (status) => {
    if (status === 'green') return 'bg-green-500';
    if (status === 'green-late') return 'bg-amber-500';
    if (status === 'overdue') return 'bg-red-500';
    if (status === 'yellow') return 'bg-yellow-400';
    return 'bg-gray-300';
};

const formatDate = (d) => {
    if (!d) return '';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
};

const statusText = (set) => {
    if (set.status === 'green' || set.status === 'green-late') {
        const late = set.submission_timing === 'late' ? ' · Delayed' : '';
        return `${set.latest_score}/${set.latest_max_score}${late}`;
    }
    if (set.is_overdue) return 'Overdue — please complete';
    if (set.status === 'yellow') return 'In progress';
    return 'Not started';
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Dashboard</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div v-if="isAdmin" class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <p>Welcome, {{ $page.props.auth.user.name }}.</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <Link :href="route('admin.classes.index')" class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white">Classes</Link>
                        <Link :href="route('admin.practice-sets.index')" class="rounded-md bg-indigo-600 px-4 py-2 text-sm text-white">Practice sets</Link>
                    </div>
                </div>

                <div v-else class="space-y-6">
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <p>Welcome, {{ $page.props.auth.user.name }}.</p>
                        <p v-if="activeYear" class="mt-1 text-sm text-gray-500">{{ activeYear.name }}</p>
                    </div>

                    <div v-if="topics.length === 0" class="rounded-lg bg-white p-8 text-center text-gray-500 shadow-sm">
                        No practice sets assigned yet.
                    </div>

                    <div v-for="topic in topics" :key="topic.topic_id" class="rounded-lg bg-white shadow-sm">
                        <div class="border-b px-6 py-4">
                            <p class="text-xs uppercase text-gray-500">{{ topic.chapter_name }}</p>
                            <h3 class="text-lg font-semibold">{{ topic.topic_name }}</h3>
                        </div>
                        <ul class="divide-y">
                            <li
                                v-for="set in topic.sets"
                                :key="set.assignment_id"
                                class="flex flex-wrap items-center justify-between gap-3 px-6 py-4"
                            >
                                <div class="flex items-center gap-3">
                                    <span class="h-3 w-3 shrink-0 rounded-full" :class="statusDot(set.status)" />
                                    <div>
                                        <p class="font-mono font-bold text-gray-900">{{ set.set_code || `Set ${set.set_number}` }}</p>
                                        <p class="text-sm text-gray-600">{{ set.tier_label }} · {{ statusText(set) }}</p>
                                        <p v-if="set.target_date" class="text-xs text-gray-500">
                                            Target: {{ formatDate(set.target_date) }}
                                            <span v-if="set.is_overdue" class="text-red-600">(overdue)</span>
                                        </p>
                                    </div>
                                </div>
                                <Link
                                    :href="route('student.assignments.show', set.assignment_id)"
                                    class="rounded-md px-4 py-2 text-sm font-medium text-white"
                                    :class="set.is_overdue ? 'bg-red-600 hover:bg-red-700' : 'bg-indigo-600 hover:bg-indigo-700'"
                                >
                                    {{ set.status === 'green' || set.status === 'green-late' ? 'View' : set.is_overdue ? 'Complete now' : 'Start' }}
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
