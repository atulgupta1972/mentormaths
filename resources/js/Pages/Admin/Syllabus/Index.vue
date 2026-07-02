<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    versions: Array,
    boards: Array,
    gradeLevels: Array,
    subjects: Array,
    academicYears: Array,
    selectedGrade: Object,
});

const form = useForm({
    board_id: '',
    grade_level_id: '',
    subject_id: '',
    academic_year_id: '',
    file: null,
});

const onFileChange = (event) => {
    form.file = event.target.files[0];
};

const submit = () => {
    form.post(route('admin.syllabus.import'), {
        forceFormData: true,
        onSuccess: () => form.reset('file'),
    });
};
</script>

<template>
    <Head title="Syllabus" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Syllabus Master</h2>
                <p v-if="selectedGrade" class="text-sm text-indigo-600">Showing: {{ selectedGrade.name }}</p>
                <p v-else class="text-sm text-gray-500">Classes 6–10 · use class selector to filter</p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="font-medium text-gray-900">Import from Excel</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Upload CBSE/ICSE syllabus file with columns: Chapter No., Main Topic, Sub-Topic,
                        Key Concepts, Difficulty Level, Approx. Periods, Remarks.
                    </p>

                    <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                        <div>
                            <InputLabel value="Board" />
                            <select v-model="form.board_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="" disabled>Select board</option>
                                <option v-for="board in boards" :key="board.id" :value="board.id">{{ board.name }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Class" />
                            <select v-model="form.grade_level_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="" disabled>Select class</option>
                                <option v-for="grade in gradeLevels" :key="grade.id" :value="grade.id">{{ grade.name }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Subject" />
                            <select v-model="form.subject_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="" disabled>Select subject</option>
                                <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Academic year" />
                            <select v-model="form.academic_year_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="" disabled>Select year</option>
                                <option v-for="year in academicYears" :key="year.id" :value="year.id">{{ year.name }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Excel file (.xlsx)" />
                            <input type="file" accept=".xlsx,.xls" class="mt-1 block w-full" required @change="onFileChange" />
                        </div>
                        <div class="sm:col-span-2">
                            <PrimaryButton :disabled="form.processing">Import syllabus</PrimaryButton>
                        </div>
                    </form>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Syllabus</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapters</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="version in versions" :key="version.id">
                                <td class="px-4 py-3">
                                    <Link
                                        :href="route('admin.syllabus.show', version.id)"
                                        class="font-medium text-indigo-600 hover:text-indigo-800"
                                    >
                                        {{ version.label }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 text-sm">{{ version.chapters_count }}</td>
                                <td class="px-4 py-3 text-sm capitalize">{{ version.status }}</td>
                            </tr>
                            <tr v-if="versions.length === 0">
                                <td colspan="3" class="px-4 py-8 text-center text-gray-500">No syllabus imported yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
