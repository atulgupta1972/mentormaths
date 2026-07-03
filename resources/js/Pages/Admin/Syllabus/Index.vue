<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    versions: Array,
    boards: Array,
    gradeLevels: Array,
    subjects: Array,
    academicYears: Array,
    selectedGrade: Object,
});

const createForm = useForm({
    board_id: '',
    grade_level_id: '',
    subject_id: '',
    academic_year_id: '',
});

const importForm = useForm({
    board_id: '',
    grade_level_id: '',
    subject_id: '',
    academic_year_id: '',
    file: null,
});

const showExcelImport = ref(false);

const submitCreate = () => {
    createForm.post(route('admin.syllabus.store'));
};

const onFileChange = (event) => {
    importForm.file = event.target.files[0];
};

const submitImport = () => {
    importForm.post(route('admin.syllabus.import'), {
        forceFormData: true,
        onSuccess: () => importForm.reset('file'),
    });
};
</script>

<template>
    <Head title="Syllabus" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Syllabus — manual entry</h2>
                    <p v-if="selectedGrade" class="text-sm text-indigo-600">Showing: {{ selectedGrade.name }}</p>
                    <p v-else class="text-sm text-gray-500">Class → Chapter → Topic</p>
                </div>
                <Link
                    :href="route('admin.chapter-heads.index')"
                    class="rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-800 hover:bg-indigo-100"
                >
                    Chapter heads (Integer, Trigonometry…)
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="font-medium text-gray-900">Create syllabus for a class</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Pick board, class, subject, and year — then add chapters and topics manually in the editor.
                    </p>

                    <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="submitCreate">
                        <div>
                            <InputLabel value="Board" />
                            <select v-model="createForm.board_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="" disabled>Select board</option>
                                <option v-for="board in boards" :key="board.id" :value="board.id">{{ board.name }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Class" />
                            <select v-model="createForm.grade_level_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="" disabled>Select class</option>
                                <option v-for="grade in gradeLevels" :key="grade.id" :value="grade.id">{{ grade.name }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Subject" />
                            <select v-model="createForm.subject_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="" disabled>Select subject</option>
                                <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Academic year" />
                            <select v-model="createForm.academic_year_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="" disabled>Select year</option>
                                <option v-for="year in academicYears" :key="year.id" :value="year.id">{{ year.name }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <PrimaryButton :disabled="createForm.processing">Create &amp; edit syllabus</PrimaryButton>
                        </div>
                    </form>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b px-4 py-3">
                        <h3 class="font-medium text-gray-900">Existing syllabi</h3>
                    </div>
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
                                <td colspan="3" class="px-4 py-8 text-center text-gray-500">
                                    No syllabus yet. Create one above.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="overflow-hidden rounded-lg border border-dashed border-gray-300 bg-gray-50/50">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-4 py-3 text-left text-sm font-medium text-gray-600 hover:bg-gray-50"
                        @click="showExcelImport = !showExcelImport"
                    >
                        <span>Optional: import from Excel</span>
                        <span>{{ showExcelImport ? '▲' : '▼' }}</span>
                    </button>
                    <form v-if="showExcelImport" class="border-t bg-white p-6" @submit.prevent="submitImport">
                        <p class="text-sm text-gray-600">
                            Legacy import — prefer manual entry above. Columns: Chapter No., Main Topic, Sub-Topic, etc.
                        </p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Board" />
                                <select v-model="importForm.board_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                    <option value="" disabled>Select board</option>
                                    <option v-for="board in boards" :key="board.id" :value="board.id">{{ board.name }}</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Class" />
                                <select v-model="importForm.grade_level_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                    <option value="" disabled>Select class</option>
                                    <option v-for="grade in gradeLevels" :key="grade.id" :value="grade.id">{{ grade.name }}</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Subject" />
                                <select v-model="importForm.subject_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                    <option value="" disabled>Select subject</option>
                                    <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Academic year" />
                                <select v-model="importForm.academic_year_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                    <option value="" disabled>Select year</option>
                                    <option v-for="year in academicYears" :key="year.id" :value="year.id">{{ year.name }}</option>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <InputLabel value="Excel file (.xlsx)" />
                                <input type="file" accept=".xlsx,.xls" class="mt-1 block w-full" required @change="onFileChange" />
                            </div>
                            <div class="sm:col-span-2">
                                <SecondaryButton :disabled="importForm.processing">Import from Excel</SecondaryButton>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
