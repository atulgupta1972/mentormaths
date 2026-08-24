<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    issuedAccess: { type: Object, default: null },
});
</script>

<template>
    <GuestLayout>
        <Head title="Access code ready" />

        <div class="text-center">
            <h1 class="text-xl font-semibold text-gray-900">Your access is ready</h1>
            <p class="mt-3 text-gray-600">
                Your access code (tcode) has been emailed (and sent to mobile when WhatsApp is configured).
                Use it to log in — no admin approval needed.
            </p>

            <div
                v-if="issuedAccess"
                class="mt-4 rounded-lg bg-indigo-50 px-4 py-3 text-left text-sm text-indigo-900"
            >
                <p class="font-semibold">Login details</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    <li><strong>Email:</strong> {{ issuedAccess.email }}</li>
                    <li><strong>Access code (tcode):</strong> {{ issuedAccess.code }}</li>
                    <li v-if="issuedAccess.expires_on"><strong>Valid until:</strong> {{ issuedAccess.expires_on }}</li>
                </ul>
            </div>

            <Link
                :href="route('login')"
                class="mt-6 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
            >
                Go to login
            </Link>
        </div>
    </GuestLayout>
</template>
