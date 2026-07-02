<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    students: Object,
    activeYear: Object,
    selectedGrade: Object,
});
</script>

<template>
    <Head title="Students" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Students</h2>
                    <p v-if="selectedGrade" class="text-sm text-indigo-600">{{ selectedGrade.name }}</p>
                    <p v-else-if="activeYear" class="text-sm text-gray-500">All classes · {{ activeYear.name }}</p>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Student</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Current class</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Board</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Parent contact</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="student in students.data" :key="student.id">
                                <td class="px-4 py-3">
                                    <Link
                                        :href="route('admin.students.show', student.id)"
                                        class="font-medium text-indigo-600 hover:text-indigo-800"
                                    >
                                        {{ student.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    {{ student.enrollments[0]?.grade_level?.name || '—' }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    {{ student.enrollments[0]?.board?.code || '—' }}
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ student.parent1_mobile }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
