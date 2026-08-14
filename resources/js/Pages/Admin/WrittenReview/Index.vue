<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ClassSetStatusPanel from '@/Components/ClassSetStatusPanel.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    gradeLevel: { type: Object, default: null },
    activeYear: { type: Object, default: null },
    boardOptions: { type: Array, default: () => [] },
    selectedBoardId: { type: [Number, String, null], default: null },
    selectedStudentId: { type: [Number, String, null], default: null },
    classStudents: { type: Array, default: () => [] },
    setStatusBoard: { type: Object, required: true },
    queue: { type: Object, default: () => ({}) },
    grades: { type: Array, default: () => [] },
});

const refresh = (overrides = {}) => {
    router.get(route('admin.written-review.index'), {
        grade_level_id: overrides.grade_level_id ?? props.gradeLevel?.id ?? undefined,
        board_id: overrides.board_id === '' ? undefined : (overrides.board_id ?? props.selectedBoardId ?? undefined),
        student_id: overrides.student_id === '' ? undefined : (overrides.student_id ?? props.selectedStudentId ?? undefined),
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const queueCards = computed(() => [
    { key: 'upload_pending', label: 'Upload pending', hint: 'Assigned — no photos yet', count: props.queue.upload_pending ?? 0, tone: 'bg-indigo-50 text-indigo-950 ring-indigo-200' },
    { key: 'under_review', label: 'Under review', hint: 'Uploaded / AI checking', count: props.queue.under_review ?? 0, tone: 'bg-violet-50 text-violet-950 ring-violet-200' },
    { key: 'teacher_check', label: 'Teacher check', hint: 'AI flagged steps to verify', count: props.queue.teacher_check ?? 0, tone: 'bg-amber-50 text-amber-950 ring-amber-200' },
    { key: 'failed', label: 'AI failed', hint: 'Upload again or mark manually', count: props.queue.failed ?? 0, tone: 'bg-rose-50 text-rose-950 ring-rose-200' },
    { key: 'graded', label: 'Graded', hint: 'Score ready', count: props.queue.graded ?? 0, tone: 'bg-emerald-50 text-emerald-950 ring-emerald-200' },
]);
</script>

<template>
    <Head title="Written review" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Written test upload &amp; review</h2>
                    <p class="text-sm text-gray-500">
                        Class matrix for written sheets — upload student photos, watch AI under review, then correct steps.
                    </p>
                </div>
                <Link
                    :href="route('admin.written-sheets.index')"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                >
                    Create / verify sheets
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-[96rem] space-y-4 px-4 sm:px-6">
                <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">Class</label>
                            <select
                                class="mt-1 block rounded-md border-gray-300 text-sm"
                                :value="gradeLevel?.id || ''"
                                @change="refresh({ grade_level_id: $event.target.value ? Number($event.target.value) : undefined, student_id: '' })"
                            >
                                <option value="">Select class</option>
                                <option v-for="grade in grades" :key="grade.id" :value="grade.id">{{ grade.name }}</option>
                            </select>
                        </div>
                        <div v-if="boardOptions.length">
                            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">Board</label>
                            <select
                                class="mt-1 block rounded-md border-gray-300 text-sm"
                                :value="selectedBoardId || ''"
                                @change="refresh({ board_id: $event.target.value ? Number($event.target.value) : '', student_id: '' })"
                            >
                                <option v-for="board in boardOptions" :key="board.id" :value="board.id">
                                    {{ board.code }} — {{ board.name }}
                                </option>
                            </select>
                        </div>
                        <div v-if="classStudents.length">
                            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">Student</label>
                            <select
                                class="mt-1 block min-w-[12rem] rounded-md border-gray-300 text-sm"
                                :value="selectedStudentId || ''"
                                @change="refresh({ student_id: $event.target.value ? Number($event.target.value) : '' })"
                            >
                                <option value="">All students</option>
                                <option v-for="student in classStudents" :key="student.id" :value="student.id">
                                    {{ student.name }}
                                </option>
                            </select>
                        </div>
                        <p v-if="activeYear" class="text-xs text-gray-500">{{ activeYear.name }}</p>
                    </div>
                </div>

                <div v-if="gradeLevel" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div
                        v-for="card in queueCards"
                        :key="card.key"
                        class="rounded-lg px-3 py-3 ring-1"
                        :class="card.tone"
                    >
                        <p class="text-xs font-semibold uppercase tracking-wide opacity-80">{{ card.label }}</p>
                        <p class="mt-1 text-2xl font-semibold">{{ card.count }}</p>
                        <p class="mt-1 text-xs opacity-80">{{ card.hint }}</p>
                    </div>
                </div>

                <div
                    v-if="!gradeLevel"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-6 text-sm text-amber-950"
                >
                    Select a class above to open the written-test matrix.
                </div>

                <ClassSetStatusPanel
                    v-else
                    :chapters="setStatusBoard.chapters"
                    :students="setStatusBoard.students"
                    :grade-level-id="gradeLevel.id"
                    :grade-level-name="gradeLevel.name"
                    :board-id="selectedBoardId"
                    :can-assign="true"
                    written-only
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
