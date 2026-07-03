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
    user: Object,
    selectedGroupIds: Array,
    groups: Array,
});

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    mobile: props.user.mobile || '',
    password: '',
    is_active: props.user.is_active,
    group_ids: [...props.selectedGroupIds],
});

const toggleGroup = (groupId, checked) => {
    if (checked) {
        if (!form.group_ids.includes(groupId)) {
            form.group_ids.push(groupId);
        }
    } else {
        form.group_ids = form.group_ids.filter((id) => id !== groupId);
    }
};

const submit = () => {
    form.put(route('admin.users.update', props.user.id));
};
</script>

<template>
    <Head :title="`Edit ${user.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Edit User</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <form class="space-y-6 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="submit">
                    <div>
                        <InputLabel for="name" value="Name" />
                        <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div>
                        <InputLabel for="mobile" value="Mobile (optional)" />
                        <TextInput id="mobile" v-model="form.mobile" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.mobile" />
                    </div>

                    <div>
                        <InputLabel for="password" value="New password (leave blank to keep current)" />
                        <TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>

                    <div>
                        <InputLabel value="Groups (select one or more)" />
                        <div class="mt-2 space-y-2">
                            <label
                                v-for="group in groups"
                                :key="group.id"
                                class="flex items-center gap-2 text-sm text-gray-700"
                            >
                                <Checkbox
                                    :checked="form.group_ids.includes(group.id)"
                                    @update:checked="toggleGroup(group.id, $event)"
                                />
                                {{ group.name }}
                                <span class="text-xs text-gray-400">({{ group.code }})</span>
                            </label>
                        </div>
                        <InputError class="mt-2" :message="form.errors.group_ids" />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <Checkbox :checked="form.is_active" @update:checked="form.is_active = $event" />
                        Account is active (can log in)
                    </label>

                    <div class="flex items-center gap-3">
                        <PrimaryButton :disabled="form.processing">Save Changes</PrimaryButton>
                        <Link :href="route('admin.users.index')">
                            <SecondaryButton type="button">Cancel</SecondaryButton>
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
