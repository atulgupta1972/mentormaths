<script setup>
import MentorMathsLogo from '@/Components/MentorMathsLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center bg-gradient-to-b from-slate-50 to-blue-50/40 pt-8 sm:justify-center sm:pt-0">
        <div class="px-4 text-center">
            <Link href="/" class="inline-block transition hover:opacity-90">
                <MentorMathsLogo class="mx-auto h-12 w-auto" />
            </Link>
            <p class="mt-2 text-sm text-slate-500">Plan · Practice · Perform</p>
        </div>

        <div
            class="mt-6 w-full overflow-hidden bg-white/90 px-6 py-6 shadow-lg ring-1 ring-slate-200/60 backdrop-blur sm:max-w-md sm:rounded-2xl"
        >
            <slot />
        </div>
    </div>
</template>
