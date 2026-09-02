<script setup>
import { formatDate } from '@/utils/dates';
import { hasRoute } from '@/utils/routes';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    groups: { type: Array, default: () => [] },
    variant: {
        type: String,
        default: 'latest',
        validator: (value) => ['latest', 'older'].includes(value),
    },
    chapterOrder: { type: Array, default: () => [] },
});

const sortedGroups = computed(() => {
    const order = props.chapterOrder;
    const groups = [...props.groups];

    if (order.length === 0) {
        return groups;
    }

    return groups.sort((left, right) => {
        const leftIndex = order.indexOf(left.chapter_name);
        const rightIndex = order.indexOf(right.chapter_name);

        if (leftIndex === -1 && rightIndex === -1) {
            return left.chapter_name.localeCompare(right.chapter_name);
        }

        if (leftIndex === -1) {
            return 1;
        }

        if (rightIndex === -1) {
            return -1;
        }

        return leftIndex - rightIndex;
    });
});

const itemHref = (item) => {
    if (item.attempt_id && hasRoute('student.attempts.show')) {
        return route('student.attempts.show', item.attempt_id);
    }

    if (item.delivery_mode === 'written' && item.assignment_id) {
        return route('student.written-assignments.show', item.assignment_id);
    }

    return item.assignment_id
        ? route('student.assignments.show', item.assignment_id)
        : '#';
};

const statusLabel = (item) => {
    if (item.status === 'not_started') {
        return item.is_overdue ? 'Overdue' : 'To do';
    }

    return `In progress (${item.progress_label} — ${item.remaining} left)`;
};

const statusClass = (item) => {
    if (item.status === 'not_started') {
        return item.is_overdue
            ? 'bg-rose-100 text-rose-950'
            : 'bg-slate-100 text-slate-800';
    }

    return 'bg-sky-100 text-sky-950';
};

const buttonClass = (item) => {
    if (item.status === 'not_started') {
        return item.is_overdue
            ? 'bg-rose-600 hover:bg-rose-700'
            : 'bg-indigo-600 hover:bg-indigo-700';
    }

    return 'bg-emerald-600 hover:bg-emerald-700';
};

const buttonLabel = (item) => (item.status === 'not_started' ? 'Start' : 'Open');

const totalItems = computed(() =>
    sortedGroups.value.reduce((count, group) => count + (group.items?.length ?? 0), 0),
);

defineExpose({ totalItems });
</script>

<template>
    <div v-if="sortedGroups.length" class="space-y-2">
        <div
            v-for="group in sortedGroups"
            :key="`${variant}-${group.chapter_name}`"
            class="rounded-lg border bg-white px-3 py-2 shadow-sm"
            :class="variant === 'latest' ? 'border-sky-200' : 'border-slate-200'"
        >
            <div class="flex flex-wrap items-start gap-x-3 gap-y-2">
                <p class="min-w-[10rem] shrink-0 text-[11px] font-bold uppercase tracking-wide text-slate-700">
                    {{ group.chapter_name }}
                </p>

                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                    <div
                        v-for="item in group.items"
                        :key="`${variant}-${group.chapter_name}-${item.assignment_id || item.attempt_id}`"
                        class="inline-flex max-w-full flex-wrap items-center gap-1.5 rounded-md border border-slate-200 bg-slate-50 px-2 py-1"
                    >
                        <span class="font-mono text-[11px] font-bold text-slate-900">
                            {{ item.set_code || 'Set' }}
                            <span class="font-semibold text-slate-600">({{ item.total }})</span>
                        </span>
                        <span
                            class="rounded px-1.5 py-px text-[10px] font-bold uppercase tracking-wide"
                            :class="statusClass(item)"
                        >
                            {{ statusLabel(item) }}
                        </span>
                        <span class="rounded bg-slate-200/80 px-1 py-px text-[9px] font-semibold uppercase text-slate-600">
                            {{ item.kind_label }}
                        </span>
                        <Link
                            :href="itemHref(item)"
                            class="inline-flex rounded px-2 py-0.5 text-[10px] font-semibold text-white"
                            :class="buttonClass(item)"
                        >
                            {{ buttonLabel(item) }}
                        </Link>
                    </div>
                </div>

                <p
                    v-if="group.items?.[0]?.target_date"
                    class="ml-auto shrink-0 text-[10px] font-medium"
                    :class="group.items.some((item) => item.is_overdue) ? 'text-rose-700' : 'text-slate-500'"
                >
                    due {{ formatDate(group.items[0].target_date) }}
                </p>
            </div>
        </div>
    </div>
</template>
