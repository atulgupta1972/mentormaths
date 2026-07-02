<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <GuestLayout>
        <Head title="Request Submitted" />

        <div class="text-center">
            <h1 class="text-xl font-semibold text-gray-900">Thank you!</h1>
            <p class="mt-3 text-gray-600">
                Your registration request has been submitted. We will review it and contact you
                once access is approved.
            </p>
            <p class="mt-2 text-sm text-gray-500">
                If you entered an email address, a confirmation has been sent there.
            </p>
            <Link
                :href="route('home')"
                class="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-800"
            >
                Back to home
            </Link>
        </div>
    </GuestLayout>
</template>
