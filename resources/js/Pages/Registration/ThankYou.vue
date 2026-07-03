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
                Your registration request has been submitted. We will review it and notify you by email
                once access is approved.
            </p>
            <div class="mt-4 rounded-lg bg-indigo-50 px-4 py-3 text-left text-sm text-indigo-900">
                <p class="font-semibold">After approval, log in with:</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    <li>The <strong>email</strong> and <strong>password</strong> you chose on this form</li>
                    <li>Go to the login page and open your dashboard</li>
                </ul>
            </div>
            <p class="mt-3 text-sm text-gray-500">
                A confirmation email has been sent to your login email address.
            </p>
            <Link
                :href="route('login')"
                class="mt-6 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
            >
                Go to login
            </Link>
            <Link
                :href="route('home')"
                class="mt-4 block text-sm font-medium text-indigo-600 hover:text-indigo-800"
            >
                Back to home
            </Link>
        </div>
    </GuestLayout>
</template>
