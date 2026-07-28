<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    plan: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    wide: { type: Boolean, default: false },
    isAdminContext: { type: Boolean, default: false },
    assigningPlanId: { type: [Number, String], default: null },
    assignDueDate: { type: String, default: '' },
    assignProcessing: { type: Boolean, default: false },
    hasChapters: { type: Boolean, default: false },
    prepSummary: { type: Object, default: null },
});

const emit = defineEmits(['assign-set', 'update:assignDueDate']);

const formatDate = (d) => {
    if (!d) {
        return '';
    }

    return new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const prepStatusClass = (row) => {
    if (row.status === 'none' || row.status === 'unassigned') {
        return 'bg-gray-100 text-gray-600';
    }

    if (row.assignment_status === 'completed') {
        return row.submission_timing === 'late' ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-800';
    }

    if (row.is_overdue) {
        return 'bg-red-100 text-red-800';
    }

    if (row.assignment_status === 'in_progress') {
        return 'bg-yellow-100 text-yellow-800';
    }

    return 'bg-indigo-50 text-indigo-800';
};

const cellPad = computed(() => (props.wide ? 'px-4 py-3' : 'px-2.5 py-2'));
const textSize = computed(() => (props.wide ? 'text-sm' : 'text-xs'));
const headSize = computed(() => (props.wide ? 'text-xs' : 'text-[10px]'));
</script>

<template>
    <div v-if="hasChapters" :class="wide ? 'px-6 py-4' : 'px-4 py-3'">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p class="font-semibold uppercase tracking-wide text-gray-500" :class="headSize">
                Chapters · practice / tests
            </p>
            <p v-if="prepSummary" class="text-gray-500" :class="textSize">
                {{ prepSummary.completed }}/{{ prepSummary.total }} done
            </p>
        </div>

        <div
            v-if="isAdminContext && assigningPlanId === plan.id"
            class="mb-4 flex flex-wrap items-end justify-between gap-3 rounded-lg border border-indigo-200 bg-indigo-50/50 px-4 py-3"
        >
            <p class="text-sm text-gray-600">
                Assign practice or chapter tests below. Default due date is the day before the exam.
            </p>
            <div>
                <InputLabel value="Due date for new assignments" class="!text-xs" />
                <input
                    :value="assignDueDate"
                    type="date"
                    class="mt-1 rounded-md border-gray-300 text-sm"
                    @input="emit('update:assignDueDate', $event.target.value)"
                />
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200">
            <table class="w-full min-w-[720px]" :class="textSize">
                <thead class="bg-gray-50">
                    <tr class="text-left uppercase tracking-wide text-gray-500" :class="headSize">
                        <th class="font-semibold" :class="cellPad">Chapter</th>
                        <th class="font-semibold" :class="cellPad">Topic</th>
                        <th class="whitespace-nowrap font-semibold" :class="cellPad">Set</th>
                        <th class="whitespace-nowrap font-semibold" :class="cellPad">Type</th>
                        <th class="font-semibold" :class="cellPad">Status</th>
                        <th class="whitespace-nowrap font-semibold" :class="cellPad">Due</th>
                        <th v-if="isAdminContext" class="font-semibold" :class="cellPad">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr
                        v-for="(row, rowIndex) in rows"
                        :key="`${plan.id}-${row.chapter_id}-${row.practice_set_id || rowIndex}`"
                        class="align-top"
                    >
                        <td class="text-gray-800" :class="cellPad">
                            {{ row.chapter_label }}
                        </td>
                        <td class="text-gray-700" :class="cellPad">
                            {{ row.topic_label }}
                        </td>
                        <td class="whitespace-nowrap font-mono font-semibold text-gray-900" :class="cellPad">
                            {{ row.set_code || '—' }}
                        </td>
                        <td class="whitespace-nowrap text-gray-600" :class="cellPad">
                            {{ row.kind_label || '—' }}
                        </td>
                        <td :class="cellPad">
                            <span
                                class="inline-block rounded-full px-2.5 py-0.5 font-medium uppercase tracking-wide"
                                :class="[prepStatusClass(row), wide ? 'text-xs' : 'text-[10px]']"
                            >
                                {{ row.progress_label }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap text-gray-500" :class="cellPad">
                            {{ row.target_date ? formatDate(row.target_date) : '—' }}
                        </td>
                        <td v-if="isAdminContext" :class="cellPad">
                            <div class="flex flex-col gap-1 font-medium leading-snug" :class="wide ? 'text-sm' : 'text-[11px]'">
                                <Link
                                    v-if="row.topic_id"
                                    :href="route('admin.questions.topics.show', row.topic_id)"
                                    class="text-indigo-600 hover:underline"
                                >
                                    Topic questions
                                </Link>
                                <template v-else-if="row.is_empty">
                                    <Link
                                        :href="route('admin.questions.chapters.show', row.chapter_id)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        Question bank
                                    </Link>
                                    <Link
                                        :href="route('admin.questions.create', { syllabus_chapter_id: row.chapter_id, scope: 'chapter' })"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        Add MCQs
                                    </Link>
                                    <Link
                                        :href="route('admin.practice-sets.chapters.show', row.chapter_id)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        Create test
                                    </Link>
                                </template>
                                <Link
                                    v-else-if="!row.topic_id && row.practice_set_id"
                                    :href="route('admin.questions.chapters.show', row.chapter_id)"
                                    class="text-indigo-600 hover:underline"
                                >
                                    Chapter bank
                                </Link>
                                <PrimaryButton
                                    v-if="assigningPlanId === plan.id && row.practice_set_id"
                                    type="button"
                                    class="!mt-1 !py-1"
                                    :class="wide ? '!text-xs' : '!text-[10px]'"
                                    :disabled="assignProcessing"
                                    @click="emit('assign-set', row.practice_set_id)"
                                >
                                    {{ row.is_assigned ? 'Re-assign' : 'Assign' }}
                                </PrimaryButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div v-else :class="wide ? 'px-6 py-4' : 'px-4 py-3'">
        <p class="text-gray-400" :class="textSize">
            {{ isAdminContext ? 'Edit the exam to select chapters, then assign practice or tests.' : 'No chapters selected yet.' }}
        </p>
    </div>
</template>
