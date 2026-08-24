<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    class_name: '',
    teacher_name: '',
    mobile: '',
    email: '',
});

const submit = () => {
    form.post(route('mentor-access.store'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Mentor trial access" />

        <div class="mb-6 text-center">
            <h1 class="text-xl font-semibold text-gray-900">Mentor / tuition class access</h1>
            <p class="mt-2 text-sm text-gray-600">
                Self-serve trial — you get an access code (tcode) by email/mobile. No admin approval.
                Valid for 15 days from generation.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <InputLabel for="class_name" value="Class / coaching name *" />
                <TextInput id="class_name" v-model="form.class_name" class="mt-1 block w-full" required />
                <InputError class="mt-1" :message="form.errors.class_name" />
            </div>
            <div>
                <InputLabel for="teacher_name" value="Teacher / mentor name *" />
                <TextInput id="teacher_name" v-model="form.teacher_name" class="mt-1 block w-full" required />
                <InputError class="mt-1" :message="form.errors.teacher_name" />
            </div>
            <div>
                <InputLabel for="mobile" value="Mobile *" />
                <TextInput id="mobile" v-model="form.mobile" type="tel" class="mt-1 block w-full" required />
                <InputError class="mt-1" :message="form.errors.mobile" />
            </div>
            <div>
                <InputLabel for="email" value="Email *" />
                <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" required />
                <InputError class="mt-1" :message="form.errors.email" />
            </div>

            <PrimaryButton class="w-full justify-center" :disabled="form.processing">
                Get access code
            </PrimaryButton>
        </form>

        <p class="mt-4 text-center text-sm text-gray-500">
            Already have a tcode?
            <Link :href="route('login')" class="font-medium text-indigo-600 hover:text-indigo-800">Log in</Link>
        </p>
    </GuestLayout>
</template>
