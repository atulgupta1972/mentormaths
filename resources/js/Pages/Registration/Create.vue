<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    academicYear: Object,
    boards: Array,
    gradeLevels: Array,
});

const form = useForm({
    student_name: '',
    date_of_birth: '',
    student_mobile: '',
    parent1_name: '',
    parent1_mobile: '',
    parent2_name: '',
    parent2_mobile: '',
    school_name: '',
    board_id: '',
    grade_level_id: '',
    email: '',
    notes: '',
});

const submit = () => {
    form.post(route('registration.store'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Request Registration" />

        <div class="mb-6">
            <h1 class="text-xl font-semibold text-gray-900">Request Registration</h1>
            <p class="mt-1 text-sm text-gray-600">
                Academic year: {{ academicYear.name }}
                ({{ academicYear.starts_on }} – {{ academicYear.ends_on }})
            </p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="student_name" value="Student full name *" />
                <TextInput id="student_name" v-model="form.student_name" class="mt-1 block w-full" required />
                <InputError class="mt-1" :message="form.errors.student_name" />
            </div>

            <div>
                <InputLabel for="date_of_birth" value="Date of birth" />
                <TextInput id="date_of_birth" v-model="form.date_of_birth" type="date" class="mt-1 block w-full" />
                <InputError class="mt-1" :message="form.errors.date_of_birth" />
            </div>

            <div>
                <InputLabel for="student_mobile" value="Student mobile (optional)" />
                <TextInput id="student_mobile" v-model="form.student_mobile" type="tel" class="mt-1 block w-full" placeholder="10-digit mobile" />
                <InputError class="mt-1" :message="form.errors.student_mobile" />
            </div>

            <div>
                <InputLabel for="parent1_name" value="Parent 1 name *" />
                <TextInput id="parent1_name" v-model="form.parent1_name" class="mt-1 block w-full" required />
                <InputError class="mt-1" :message="form.errors.parent1_name" />
            </div>

            <div>
                <InputLabel for="parent1_mobile" value="Parent 1 mobile *" />
                <TextInput id="parent1_mobile" v-model="form.parent1_mobile" type="tel" class="mt-1 block w-full" required placeholder="10-digit mobile" />
                <InputError class="mt-1" :message="form.errors.parent1_mobile" />
            </div>

            <div>
                <InputLabel for="parent2_name" value="Parent 2 name" />
                <TextInput id="parent2_name" v-model="form.parent2_name" class="mt-1 block w-full" />
                <InputError class="mt-1" :message="form.errors.parent2_name" />
            </div>

            <div>
                <InputLabel for="parent2_mobile" value="Parent 2 mobile" />
                <TextInput id="parent2_mobile" v-model="form.parent2_mobile" type="tel" class="mt-1 block w-full" placeholder="10-digit mobile" />
                <InputError class="mt-1" :message="form.errors.parent2_mobile" />
            </div>

            <div>
                <InputLabel for="school_name" value="School name *" />
                <TextInput id="school_name" v-model="form.school_name" class="mt-1 block w-full" required />
                <InputError class="mt-1" :message="form.errors.school_name" />
            </div>

            <div>
                <InputLabel for="board_id" value="Board *" />
                <select
                    id="board_id"
                    v-model="form.board_id"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    required
                >
                    <option value="" disabled>Select board</option>
                    <option v-for="board in boards" :key="board.id" :value="board.id">
                        {{ board.name }}
                    </option>
                </select>
                <InputError class="mt-1" :message="form.errors.board_id" />
            </div>

            <div>
                <InputLabel for="grade_level_id" value="Class *" />
                <select
                    id="grade_level_id"
                    v-model="form.grade_level_id"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    required
                >
                    <option value="" disabled>Select class</option>
                    <option v-for="grade in gradeLevels" :key="grade.id" :value="grade.id">
                        {{ grade.name }}
                    </option>
                </select>
                <InputError class="mt-1" :message="form.errors.grade_level_id" />
            </div>

            <div>
                <InputLabel for="email" value="Email (optional, for login)" />
                <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" />
                <InputError class="mt-1" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="notes" value="Notes" />
                <textarea
                    id="notes"
                    v-model="form.notes"
                    rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    placeholder="e.g. No own phone — use father's number"
                />
                <InputError class="mt-1" :message="form.errors.notes" />
            </div>

            <PrimaryButton class="w-full justify-center" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                Submit request
            </PrimaryButton>
        </form>
    </GuestLayout>
</template>
