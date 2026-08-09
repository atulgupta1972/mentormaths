<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ClassCoveragePanel from '@/Components/ClassCoveragePanel.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    gradeLevel: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    selectedStudent: { type: Object, default: null },
    classCoverage: {
        type: Object,
        default: () => ({ chapters: [], under_study_chapter_id: null }),
    },
    context: { type: Object, default: null },
});

const setStudent = (studentId) => {
    router.get(route('admin.school-study-plan.index'), {
        student_id: studentId || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

const updateRouteParams = computed(() => (
    props.selectedStudent?.id
        ? { student: props.selectedStudent.id }
        : {}
));

const contextLabel = computed(() => {
    const parts = [props.context?.grade_name, props.context?.board_name].filter(Boolean);

    return parts.join(' · ');
});

const flashSuccess = computed(() => usePage().props.flash?.success ?? '');
</script>

<template>
    <Head title="School study plans" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">School study plans</h2>
                <p class="text-sm text-gray-500">
                    Select a student in {{ gradeLevel?.name ?? 'the selected class' }} to view or update chapters covered in school.
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6">
                <div v-if="!gradeLevel" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
                    Select a class from the top bar first.
                </div>

                <template v-else>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="text-sm text-gray-600">Student</label>
                        <select
                            class="rounded-md border-gray-300 text-sm"
                            :value="filters.student_id ?? ''"
                            @change="setStudent($event.target.value || null)"
                        >
                            <option value="">Select student…</option>
                            <option
                                v-for="student in students"
                                :key="student.id"
                                :value="student.id"
                            >
                                {{ student.name }}
                            </option>
                        </select>
                    </div>

                    <div v-if="!selectedStudent" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-600">
                        Choose a student to see their school study plan.
                    </div>

                    <div v-else class="space-y-2">
                        <p v-if="flashSuccess" class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                            {{ flashSuccess }}
                        </p>
                        <p class="text-sm text-slate-700">
                            <span class="font-semibold">{{ selectedStudent.name }}</span>
                            <span v-if="contextLabel" class="text-slate-500"> · {{ contextLabel }}</span>
                        </p>
                        <ClassCoveragePanel
                            :class-coverage="classCoverage"
                            update-route-name="admin.school-study-plan.update"
                            :update-route-params="updateRouteParams"
                        />
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
