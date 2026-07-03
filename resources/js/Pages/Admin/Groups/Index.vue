<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    groups: Array,
});

const createForm = useForm({
    code: '',
    name: '',
    description: '',
    sort_order: 99,
});

const editForms = {};

for (const group of props.groups) {
    editForms[group.id] = useForm({
        name: group.name,
        description: group.description || '',
        sort_order: group.sort_order,
        is_active: group.is_active,
    });
}

const submitCreate = () => {
    createForm.post(route('admin.groups.store'), {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
};

const submitEdit = (group) => {
    editForms[group.id].put(route('admin.groups.update', group.id), { preserveScroll: true });
};

const deleteGroup = (group) => {
    if (!confirm(`Delete group "${group.name}"?`)) {
        return;
    }

    editForms[group.id].delete(route('admin.groups.destroy', group.id), { preserveScroll: true });
};

const isSystemGroup = (code) => ['admin', 'teacher', 'student', 'parent'].includes(code);
</script>

<template>
    <Head title="User Groups" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">User Groups</h2>
                    <p class="text-sm text-gray-500">Master groups — users can belong to multiple groups</p>
                </div>
                <Link :href="route('admin.users.index')">
                    <SecondaryButton>Back to Users</SecondaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-8 sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="submitCreate">
                    <h3 class="text-lg font-medium text-gray-900">Create new group</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="code" value="Code (lowercase, unique)" />
                            <TextInput id="code" v-model="createForm.code" class="mt-1 block w-full" placeholder="coordinator" />
                            <InputError class="mt-2" :message="createForm.errors.code" />
                        </div>
                        <div>
                            <InputLabel for="name" value="Display name" />
                            <TextInput id="name" v-model="createForm.name" class="mt-1 block w-full" placeholder="Coordinator" />
                            <InputError class="mt-2" :message="createForm.errors.name" />
                        </div>
                    </div>
                    <div>
                        <InputLabel for="description" value="Description (optional)" />
                        <TextInput id="description" v-model="createForm.description" class="mt-1 block w-full" />
                    </div>
                    <PrimaryButton :disabled="createForm.processing">Add Group</PrimaryButton>
                </form>

                <div class="space-y-4">
                    <form
                        v-for="group in groups"
                        :key="group.id"
                        class="space-y-4 rounded-lg bg-white p-6 shadow-sm"
                        @submit.prevent="submitEdit(group)"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">{{ group.name }}</h3>
                                <p class="text-sm text-gray-500">
                                    Code: <span class="font-mono">{{ group.code }}</span>
                                    · {{ group.users_count }} user(s)
                                </p>
                            </div>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="group.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                            >
                                {{ group.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel :for="`name-${group.id}`" value="Display name" />
                                <TextInput
                                    :id="`name-${group.id}`"
                                    v-model="editForms[group.id].name"
                                    class="mt-1 block w-full"
                                />
                            </div>
                            <div>
                                <InputLabel :for="`sort-${group.id}`" value="Sort order" />
                                <TextInput
                                    :id="`sort-${group.id}`"
                                    v-model="editForms[group.id].sort_order"
                                    type="number"
                                    class="mt-1 block w-full"
                                />
                            </div>
                        </div>

                        <div>
                            <InputLabel :for="`desc-${group.id}`" value="Description" />
                            <TextInput
                                :id="`desc-${group.id}`"
                                v-model="editForms[group.id].description"
                                class="mt-1 block w-full"
                            />
                        </div>

                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <Checkbox v-model:checked="editForms[group.id].is_active" />
                            Group is active (can be assigned to users)
                        </label>

                        <div class="flex items-center gap-3">
                            <PrimaryButton :disabled="editForms[group.id].processing">Save</PrimaryButton>
                            <button
                                v-if="!isSystemGroup(group.code) && group.users_count === 0"
                                type="button"
                                class="text-sm text-red-600 hover:text-red-800"
                                @click="deleteGroup(group)"
                            >
                                Delete
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
