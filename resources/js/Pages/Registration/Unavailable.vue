<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <GuestLayout>
        <Head title="Registration Unavailable" />

        <div class="text-center">
            <h1 class="text-xl font-semibold text-gray-900">Registration not open</h1>
            <p class="mt-3 text-gray-600">
                There is no active academic year at the moment. Please check back later or
                contact us directly.
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
