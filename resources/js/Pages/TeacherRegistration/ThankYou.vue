<script setup>
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Application received" />

    <div class="flex min-h-screen items-center justify-center bg-gradient-to-b from-violet-50 to-white px-6">
        <div class="max-w-lg text-center">
            <h1 class="text-2xl font-bold text-gray-900">Thank you — mentor application received</h1>
            <p class="mt-3 text-gray-600">
                We will review your profile shortly. If you applied for online mentoring and we need to discuss your hourly rate, we will email you a counter offer to accept or decline.
            </p>
            <Link href="/" class="mt-6 inline-block text-indigo-600 hover:underline">Back to home</Link>
        </div>
    </div>
</template>
