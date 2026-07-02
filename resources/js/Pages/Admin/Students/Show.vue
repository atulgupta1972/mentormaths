<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    student: Object,
    enrollmentHistory: Array,
    latestEnrollment: Object,
    nextGrade: Object,
    academicYears: Array,
    gradeLevels: Array,
    boards: Array,
});

const defaultYear = computed(() => props.academicYears.find((y) => !y.is_active) || props.academicYears[0]);

const form = useForm({
    academic_year_id: defaultYear.value?.id || '',
    grade_level_id: props.nextGrade?.id || '',
    board_id: props.latestEnrollment?.board_id || '',
    school_name: props.latestEnrollment?.school_name || props.student.school_name,
});

watch(defaultYear, (year) => {
    if (year && !form.academic_year_id) {
        form.academic_year_id = year.id;
    }
});

const submit = () => {
    form.post(route('admin.students.promote', props.student.id));
};
</script>

<template>
    <Head :title="student.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">{{ student.name }}</h2>
                <Link :href="route('admin.students.index')" class="text-sm text-indigo-600">Back to students</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-indigo-50 p-4 text-sm text-indigo-900">
                    <strong>Same student, new class each year.</strong>
                    Profile stays one record. Each academic year gets its own enrollment row
                    (class, board, school). Past years are kept for history.
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b px-6 py-4">
                        <h3 class="font-medium">Enrollment history</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Year</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Class</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Board</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="row in enrollmentHistory" :key="row.id">
                                <td class="px-4 py-3">{{ row.academic_year?.name }}</td>
                                <td class="px-4 py-3">{{ row.grade_level?.name }}</td>
                                <td class="px-4 py-3">{{ row.board?.code }}</td>
                                <td class="px-4 py-3 capitalize">{{ row.status }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="latestEnrollment" class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="font-medium text-gray-900">Promote to next class</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Latest: {{ latestEnrollment.grade_level?.name }} ({{ latestEnrollment.academic_year?.name }}).
                        <span v-if="nextGrade">Suggested next: {{ nextGrade.name }}.</span>
                    </p>

                    <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                        <div>
                            <InputLabel value="Academic year" />
                            <select v-model="form.academic_year_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option v-for="year in academicYears" :key="year.id" :value="year.id">
                                    {{ year.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Class" />
                            <select v-model="form.grade_level_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option v-for="grade in gradeLevels" :key="grade.id" :value="grade.id">
                                    {{ grade.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Board" />
                            <select v-model="form.board_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option v-for="board in boards" :key="board.id" :value="board.id">
                                    {{ board.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="School" />
                            <TextInput v-model="form.school_name" class="mt-1 block w-full" />
                        </div>
                        <div class="sm:col-span-2">
                            <PrimaryButton :disabled="form.processing">Promote student</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
