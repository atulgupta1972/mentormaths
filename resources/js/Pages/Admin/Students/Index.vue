<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PendingWorkEmailPanel from '@/Components/PendingWorkEmailPanel.vue';
import StudentEnrollmentMentorPanel from '@/Components/StudentEnrollmentMentorPanel.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    students: Object,
    activeYear: Object,
    selectedGrade: Object,
    mailSettings: Object,
    gradeLevels: {
        type: Array,
        default: () => [],
    },
    mentorFilter: { type: String, default: '' },
    mentorCounts: {
        type: Object,
        default: () => ({ total: 0, mapped: 0, unmapped: 0 }),
    },
    enrollmentOptions: { type: Array, default: () => [] },
    coachingClasses: { type: Array, default: () => [] },
});

const mappingStudentId = ref(null);

const enrollmentStatus = (student) => student.enrollments?.[0]?.status || '—';

const statusClass = (status) => {
    if (status === 'active') {
        return 'bg-green-100 text-green-800';
    }

    if (status === 'inactive') {
        return 'bg-red-100 text-red-800';
    }

    return 'bg-gray-100 text-gray-700';
};

const setMentorFilter = (value) => {
    router.get(route('admin.students.index'), {
        mentor: value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const clearGradeFilter = () => {
    router.post(route('admin.grade-context.update'), { grade_level_id: null }, {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['students', 'selectedGrade', 'mentorCounts'] }),
    });
};

const toggleMap = (studentId) => {
    mappingStudentId.value = mappingStudentId.value === studentId ? null : studentId;
};
</script>

<template>
    <Head title="Students" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Students</h2>
                    <p v-if="selectedGrade" class="text-sm text-indigo-600">
                        {{ selectedGrade.name }}
                        <button type="button" class="ml-2 text-xs font-semibold text-slate-600 underline" @click="clearGradeFilter">
                            Show all classes
                        </button>
                    </p>
                    <p v-else-if="activeYear" class="text-sm text-gray-500">All classes · {{ activeYear.name }}</p>
                </div>
                <div class="flex flex-wrap gap-2 text-sm">
                    <button
                        type="button"
                        class="rounded-full px-3 py-1 font-semibold"
                        :class="!mentorFilter ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-700'"
                        @click="setMentorFilter('')"
                    >
                        All {{ mentorCounts.total }}
                    </button>
                    <button
                        type="button"
                        class="rounded-full px-3 py-1 font-semibold"
                        :class="mentorFilter === 'mapped' ? 'bg-emerald-700 text-white' : 'bg-emerald-50 text-emerald-800'"
                        @click="setMentorFilter('mapped')"
                    >
                        Mapped {{ mentorCounts.mapped }}
                    </button>
                    <button
                        type="button"
                        class="rounded-full px-3 py-1 font-semibold"
                        :class="mentorFilter === 'unmapped' ? 'bg-rose-700 text-white' : 'bg-rose-50 text-rose-800'"
                        @click="setMentorFilter('unmapped')"
                    >
                        Not mapped {{ mentorCounts.unmapped }}
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="mentorCounts.unmapped > 0"
                    class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                >
                    <strong>{{ mentorCounts.unmapped }}</strong> student{{ mentorCounts.unmapped === 1 ? '' : 's' }}
                    still need mentor mapping.
                    Filter <button type="button" class="font-bold underline" @click="setMentorFilter('unmapped')">Not mapped</button>
                    and use <em>Map mentor</em>.
                    New enrollments require a mentor (parent Notify tick or coaching teacher).
                </div>

                <div
                    v-if="mentorCounts.total === 0"
                    class="rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-800"
                >
                    No students in this local database yet
                    <span v-if="selectedGrade"> for {{ selectedGrade.name }}</span>.
                    If you expected older local data, it was cleared from MySQL — restore a backup/dump, or approve new registration requests.
                </div>

                <PendingWorkEmailPanel
                    v-if="mailSettings"
                    :mail-settings="mailSettings"
                    :active-year="activeYear"
                    :selected-grade="selectedGrade"
                    :grade-levels="gradeLevels"
                />

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Student</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Class</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Enrolled by</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Mentor</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <template v-for="student in students.data" :key="student.id">
                                <tr>
                                    <td class="px-4 py-3">
                                        <Link
                                            :href="route('admin.students.show', student.id)"
                                            class="font-medium hover:text-indigo-800"
                                            :class="enrollmentStatus(student) === 'inactive' ? 'text-gray-500 line-through' : 'text-indigo-600'"
                                        >
                                            {{ student.name }}
                                        </Link>
                                        <p class="text-xs text-gray-500">{{ student.parent1_mobile || '—' }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        {{ student.enrollments?.[0]?.grade_level?.name || '—' }}
                                        <span v-if="student.enrollments?.[0]?.board?.code" class="text-gray-500">
                                            · {{ student.enrollments[0].board.code }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        {{ student.mentor_summary?.source_label || '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <span
                                            v-if="student.mentor_summary?.mapped"
                                            class="inline-flex flex-col"
                                        >
                                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800 w-fit">
                                                Mapped
                                            </span>
                                            <span class="mt-1 text-slate-800">{{ student.mentor_summary.name }}</span>
                                            <span class="font-mono text-xs text-slate-600">{{ student.mentor_summary.mobile }}</span>
                                        </span>
                                        <span
                                            v-else
                                            class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800"
                                        >
                                            Not mapped
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize"
                                            :class="statusClass(enrollmentStatus(student))"
                                        >
                                            {{ enrollmentStatus(student) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <button
                                            v-if="!student.mentor_summary?.mapped"
                                            type="button"
                                            class="font-semibold text-indigo-700 hover:underline"
                                            @click="toggleMap(student.id)"
                                        >
                                            {{ mappingStudentId === student.id ? 'Hide' : 'Map mentor' }}
                                        </button>
                                        <Link
                                            v-else
                                            :href="route('admin.students.show', student.id)"
                                            class="text-slate-600 hover:underline"
                                        >
                                            Open
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="mappingStudentId === student.id">
                                    <td colspan="6" class="bg-slate-50 px-4 py-4">
                                        <StudentEnrollmentMentorPanel
                                            :student="student"
                                            :enrollment-options="enrollmentOptions"
                                            :coaching-classes="coachingClasses"
                                            :mentor="student.mentor_summary"
                                        />
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!students.data?.length">
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">
                                    No students match this filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
