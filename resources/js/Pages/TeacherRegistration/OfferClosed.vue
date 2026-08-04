<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    application: { type: Object, required: true },
});
</script>

<template>
    <Head title="Offer closed" />

    <div class="flex min-h-screen items-center justify-center bg-gray-50 px-6">
        <div class="max-w-md text-center">
            <h1 class="text-xl font-bold text-gray-900">Offer no longer open</h1>
            <p class="mt-2 text-sm text-gray-600">
                Hi {{ application.name }}, this link is closed (status: {{ application.status_label }}).
            </p>
            <Link href="/" class="mt-4 inline-block text-indigo-600 hover:underline">Back to home</Link>
        </div>
    </div>
</template>
