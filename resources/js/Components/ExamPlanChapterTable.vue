<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    plan: { type: Object, required: true },
    groups: { type: Array, default: () => [] },
    wide: { type: Boolean, default: false },
    isAdminContext: { type: Boolean, default: false },
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

const cellPad = computed(() => (props.wide ? 'px-4 py-2.5' : 'px-3 py-2'));
const textSize = computed(() => (props.wide ? 'text-sm' : 'text-xs'));
const headSize = computed(() => (props.wide ? 'text-xs' : 'text-[10px]'));

const chapterHubHref = (chapterId) => route('admin.practice-sets.chapters.show', chapterId);
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
                Pick a due date, then assign sets below. Click a chapter name to create new practice or tests.
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

        <div class="space-y-4">
            <section
                v-for="group in groups"
                :key="`${plan.id}-chapter-${group.chapter_id}`"
                class="overflow-hidden rounded-lg border border-gray-200"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-100 bg-indigo-50/80"
                    :class="wide ? 'px-4 py-3' : 'px-3 py-2.5'"
                >
                    <div class="min-w-0">
                        <Link
                            v-if="isAdminContext"
                            :href="chapterHubHref(group.chapter_id)"
                            class="font-semibold text-indigo-800 hover:text-indigo-950 hover:underline"
                            :class="wide ? 'text-base' : 'text-sm'"
                        >
                            {{ group.chapter_label }}
                        </Link>
                        <p v-else class="font-semibold text-gray-900" :class="wide ? 'text-base' : 'text-sm'">
                            {{ group.chapter_label }}
                        </p>
                        <p v-if="isAdminContext" class="mt-0.5 text-[11px] text-indigo-700/80">
                            Click chapter name to create sets &amp; add questions
                        </p>
                    </div>
                    <Link
                        v-if="isAdminContext"
                        :href="chapterHubHref(group.chapter_id)"
                        class="inline-flex shrink-0 items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 font-semibold text-gray-700 shadow-sm hover:bg-gray-50"
                        :class="wide ? 'text-xs' : 'text-[10px]'"
                    >
                        + New set
                    </Link>
                </div>

                <div v-if="group.is_empty" class="bg-white px-4 py-4 text-sm text-gray-500">
                    No sets for this chapter yet.
                    <Link
                        v-if="isAdminContext"
                        :href="chapterHubHref(group.chapter_id)"
                        class="font-medium text-indigo-600 hover:underline"
                    >
                        Create practice or test
                    </Link>
                </div>

                <div v-else class="overflow-x-auto bg-white">
                    <table class="w-full min-w-[560px]" :class="textSize">
                        <thead class="border-b border-gray-100 bg-gray-50/80">
                            <tr class="text-left uppercase tracking-wide text-gray-500" :class="headSize">
                                <th class="whitespace-nowrap font-semibold" :class="cellPad">Set no</th>
                                <th class="whitespace-nowrap font-semibold" :class="cellPad">Questions</th>
                                <th class="whitespace-nowrap font-semibold" :class="cellPad">Type</th>
                                <th class="font-semibold" :class="cellPad">Status</th>
                                <th v-if="isAdminContext" class="whitespace-nowrap font-semibold text-right" :class="cellPad">
                                    Assign
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="(set, setIndex) in group.sets"
                                :key="`${group.chapter_id}-${set.practice_set_id || setIndex}`"
                            >
                                <td class="font-mono font-semibold text-gray-900" :class="cellPad">
                                    {{ set.set_code || '—' }}
                                    <span
                                        v-if="set.topic_name"
                                        class="mt-0.5 block font-sans font-normal text-gray-400"
                                        :class="wide ? 'text-xs' : 'text-[10px]'"
                                    >
                                        {{ set.topic_name }}
                                    </span>
                                </td>
                                <td class="text-gray-700" :class="cellPad">
                                    {{ set.questions_count ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap text-gray-600" :class="cellPad">
                                    {{ set.kind_label || '—' }}
                                </td>
                                <td :class="cellPad">
                                    <span
                                        class="inline-block rounded-full px-2.5 py-0.5 font-medium uppercase tracking-wide"
                                        :class="[prepStatusClass(set), wide ? 'text-xs' : 'text-[10px]']"
                                    >
                                        {{ set.progress_label }}
                                    </span>
                                </td>
                                <td v-if="isAdminContext" class="text-right" :class="cellPad">
                                    <PrimaryButton
                                        v-if="set.practice_set_id"
                                        type="button"
                                        class="!py-1"
                                        :class="wide ? '!text-xs' : '!text-[10px]'"
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
    <div v-else :class="wide ? 'px-6 py-4' : 'px-4 py-3'">
        <p class="text-gray-400" :class="textSize">
            {{ isAdminContext ? 'Edit the exam to select chapters, then assign practice or tests.' : 'No chapters selected yet.' }}
        </p>
    </div>
</template>
