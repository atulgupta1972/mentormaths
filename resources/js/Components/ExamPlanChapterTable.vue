<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { questionHubChapterUrl } from '@/utils/questionHub';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    plan: { type: Object, required: true },
    groups: { type: Array, default: () => [] },
    isAdminContext: { type: Boolean, default: false },
    studentId: { type: [Number, String], default: null },
    assigningPlanId: { type: [Number, String], default: null },
    assignDueDate: { type: String, default: '' },
    assignProcessing: { type: Boolean, default: false },
    hasChapters: { type: Boolean, default: false },
    prepSummary: { type: Object, default: null },
});

const emit = defineEmits(['assign-set', 'update:assignDueDate']);

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

/** Question bank chapter hub — Add MCQs / fill blanks / written / formulas (board & class from chapter). */
const chapterHubHref = (chapterId) => questionHubChapterUrl(chapterId);

const setHref = (set) => {
    if (!props.isAdminContext || !set.practice_set_id) {
        return null;
    }

    return route('admin.questions.sets.show', set.practice_set_id);
};
</script>

<template>
    <div v-if="hasChapters" class="px-3 py-2.5">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                Chapters · practice / tests
            </p>
            <p v-if="prepSummary" class="text-[11px] text-gray-500">
                {{ prepSummary.completed }}/{{ prepSummary.total }} done
            </p>
        </div>

        <div
            v-if="isAdminContext && assigningPlanId === plan.id"
            class="mb-3 flex flex-wrap items-end gap-3 rounded-md border border-indigo-200 bg-indigo-50/50 px-2.5 py-2"
        >
            <p class="text-[11px] leading-snug text-gray-600">
                Set due date, then assign. Click chapter name to create sets.
            </p>
            <div>
                <InputLabel value="Due date" class="!text-[10px]" />
                <input
                    :value="assignDueDate"
                    type="date"
                    class="mt-0.5 rounded-md border-gray-300 text-xs"
                    @input="emit('update:assignDueDate', $event.target.value)"
                />
            </div>
        </div>

        <div class="space-y-2.5">
            <section
                v-for="group in groups"
                :key="`${plan.id}-chapter-${group.chapter_id}`"
                class="inline-block max-w-full overflow-hidden rounded-md border border-gray-200"
            >
                <div class="flex items-center justify-between gap-2 border-b border-indigo-100 bg-indigo-50/80 px-2.5 py-1.5">
                    <Link
                        v-if="isAdminContext"
                        :href="chapterHubHref(group.chapter_id)"
                        class="text-xs font-semibold text-indigo-800 hover:text-indigo-950 hover:underline"
                    >
                        {{ group.chapter_label }}
                    </Link>
                    <p v-else class="text-xs font-semibold text-gray-900">
                        {{ group.chapter_label }}
                    </p>
                    <Link
                        v-if="isAdminContext"
                        :href="chapterHubHref(group.chapter_id)"
                        class="shrink-0 text-[10px] font-semibold text-indigo-600 hover:underline"
                    >
                        + New set
                    </Link>
                </div>

                <div v-if="group.is_empty" class="bg-white px-2.5 py-2 text-[11px] text-gray-500">
                    No sets yet.
                    <Link
                        v-if="isAdminContext"
                        :href="chapterHubHref(group.chapter_id)"
                        class="font-semibold text-indigo-600 hover:underline"
                    >
                        Create
                    </Link>
                </div>

                <div v-else class="overflow-x-auto bg-white">
                    <table class="w-max max-w-full table-auto border-collapse text-xs">
                        <thead class="border-b border-gray-100 bg-gray-50/80">
                            <tr class="text-left text-[10px] uppercase tracking-wide text-gray-500">
                                <th class="whitespace-nowrap px-2 py-1 font-semibold">Set no</th>
                                <th class="whitespace-nowrap px-2 py-1 font-semibold">Qs</th>
                                <th class="whitespace-nowrap px-2 py-1 font-semibold">Type</th>
                                <th class="whitespace-nowrap px-2 py-1 font-semibold">Status</th>
                                <th v-if="isAdminContext" class="whitespace-nowrap px-2 py-1 text-right font-semibold">
                                    Assign
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="(set, setIndex) in group.sets"
                                :key="`${group.chapter_id}-${set.practice_set_id || setIndex}`"
                            >
                                <td class="px-2 py-1 align-top">
                                    <Link
                                        v-if="setHref(set)"
                                        :href="setHref(set)"
                                        class="whitespace-nowrap font-mono font-semibold text-indigo-700 hover:text-indigo-950 hover:underline"
                                    >
                                        {{ set.set_code }}
                                    </Link>
                                    <span v-else class="whitespace-nowrap font-mono font-semibold text-gray-900">
                                        {{ set.set_code || '—' }}
                                    </span>
                                    <span
                                        v-if="set.topic_name"
                                        class="mt-0.5 block whitespace-nowrap text-[11px] font-semibold text-gray-700"
                                    >
                                        {{ set.topic_name }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-2 py-1 text-center text-gray-700">
                                    {{ set.questions_count ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-2 py-1 text-gray-600">
                                    {{ set.kind_label || '—' }}
                                </td>
                                <td class="px-2 py-1">
                                    <span
                                        class="inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide"
                                        :class="prepStatusClass(set)"
                                    >
                                        {{ set.progress_label }}
                                    </span>
                                </td>
                                <td v-if="isAdminContext" class="whitespace-nowrap px-2 py-1 text-right">
                                    <PrimaryButton
                                        v-if="set.practice_set_id"
                                        type="button"
                                        class="!px-2 !py-0.5 !text-[10px]"
                                        :disabled="assignProcessing"
                                        @click="emit('assign-set', set.practice_set_id)"
                                    >
                                        {{ set.is_assigned ? 'Re-assign' : 'Assign' }}
                                    </PrimaryButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
    <div v-else class="px-3 py-2.5">
        <p class="text-xs text-gray-400">
            {{ isAdminContext ? 'Edit the exam to select chapters, then assign practice or tests.' : 'No chapters selected yet.' }}
        </p>
    </div>
</template>
