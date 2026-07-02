<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    years: Array,
});

const bulkForm = useForm({
    from_academic_year_id: '',
    to_academic_year_id: '',
});

const bulkPromote = () => {
    bulkForm.post(route('admin.students.bulk-promote'));
};

const form = useForm({
    name: '',
    starts_on: '',
    ends_on: '',
    notes: '',
    is_active: false,
});

const submit = () => {
    form.post(route('admin.academic-years.store'), {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Academic Years" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Academic Years</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="font-medium text-gray-900">Add academic year</h3>
                    <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                        <div>
                            <InputLabel for="name" value="Name (e.g. 2027-28)" />
                            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                        </div>
                        <div>
                            <InputLabel for="starts_on" value="Starts on" />
                            <TextInput id="starts_on" v-model="form.starts_on" type="date" class="mt-1 block w-full" required />
                        </div>
                        <div>
                            <InputLabel for="ends_on" value="Ends on" />
                            <TextInput id="ends_on" v-model="form.ends_on" type="date" class="mt-1 block w-full" required />
                        </div>
                        <div class="flex items-end">
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300" />
                                Set as active year
                            </label>
                        </div>
                        <div class="sm:col-span-2">
                            <PrimaryButton :disabled="form.processing">Create year</PrimaryButton>
                        </div>
                    </form>
                </div>

                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="font-medium text-gray-900">Bulk promote students</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Move all active students from one year to the next (same profile, new enrollment, next class).
                    </p>
                    <form class="mt-4 grid gap-4 sm:grid-cols-3" @submit.prevent="bulkPromote">
                        <div>
                            <InputLabel value="From year" />
                            <select v-model="bulkForm.from_academic_year_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="" disabled>Select</option>
                                <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="To year" />
                            <select v-model="bulkForm.to_academic_year_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="" disabled>Select</option>
                                <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <PrimaryButton :disabled="bulkForm.processing">Promote all</PrimaryButton>
                        </div>
                    </form>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Year</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Period</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="year in years" :key="year.id">
                                <td class="px-4 py-3 font-medium">{{ year.name }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ year.starts_on }} – {{ year.ends_on }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-1 text-xs"
                                        :class="year.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'"
                                    >
                                        {{ year.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        v-if="!year.is_active"
                                        type="button"
                                        class="text-sm text-indigo-600 hover:text-indigo-800"
                                        @click="$inertia.post(route('admin.academic-years.activate', year.id))"
                                    >
                                        Set active
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
