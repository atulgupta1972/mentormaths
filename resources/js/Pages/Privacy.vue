<script setup>
import MentorMathsLogo from '@/Components/MentorMathsLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

const sections = [
    {
        title: 'What we collect',
        items: [
            'Student and parent/guardian names',
            'Mobile phone numbers and email addresses',
            'Class, school, and syllabus details needed for assignments',
            'Learning progress — practice set scores, completion, timings, and written work uploads',
            'Account login details (email and password, stored securely)',
        ],
    },
    {
        title: 'How we use it',
        items: [
            'To provide maths practice, assignments, and progress tracking on Mentor Maths',
            'To let teachers and mentors assign work and review performance',
            'To send service notifications (e.g. new assignments, reminders, progress summaries)',
            'To improve the platform and support students during their course',
        ],
    },
    {
        title: 'WhatsApp messages',
        items: [
            'Progress updates and reminders may be sent by WhatsApp to parent or guardian mobile numbers you provide.',
            'We do not send marketing messages to students’ personal numbers without consent.',
            'You can ask us to update or remove a contact number by writing to the address below.',
        ],
    },
    {
        title: 'Sharing and retention',
        items: [
            'Data is used only for Mentor Maths education services — not sold to advertisers.',
            'Teachers and admins assigned to a student can see that student’s progress within the platform.',
            'We keep data while the account is active and for a reasonable period afterward for records and support.',
        ],
    },
];
</script>

<template>
    <Head title="Privacy Policy — Mentor Maths">
        <link rel="icon" type="image/jpeg" href="/favicon.jpg" />
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700&display=swap" rel="stylesheet" />
    </Head>

    <div class="min-h-screen font-[Plus_Jakarta_Sans,sans-serif] text-slate-800">
        <div class="pointer-events-none fixed inset-0 overflow-hidden">
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-blue-400/15 blur-3xl" />
            <div class="absolute -right-24 top-1/3 h-80 w-80 rounded-full bg-cyan-400/15 blur-3xl" />
        </div>

        <header class="relative z-10 mx-auto flex max-w-3xl items-center justify-between gap-4 px-6 py-5">
            <Link href="/" class="transition hover:opacity-90">
                <MentorMathsLogo size-class="h-20 w-auto max-w-[22rem] sm:h-24 sm:max-w-[28rem]" />
            </Link>
            <Link
                :href="route('home')"
                class="text-sm font-medium text-blue-600 hover:text-blue-800"
            >
                ← Home
            </Link>
        </header>

        <main class="relative z-10 mx-auto max-w-3xl px-6 pb-16">
            <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
                Privacy Policy
            </h1>
            <p class="mt-3 text-sm text-slate-500">
                Last updated: {{ new Date().getFullYear() }} ·
                <a href="https://mentormaths.in" class="text-blue-600 hover:underline">mentormaths.in</a>
            </p>

            <p class="mt-6 text-base leading-relaxed text-slate-700">
                Mentor Maths helps students practise school maths with teacher-assigned sets and progress tracking.
                This page explains, in brief, what information we collect and how we use it.
            </p>

            <div class="mt-10 space-y-8">
                <section
                    v-for="section in sections"
                    :key="section.title"
                    class="rounded-2xl border border-slate-200/80 bg-white/80 p-6 shadow-sm backdrop-blur"
                >
                    <h2 class="text-lg font-semibold text-slate-900">{{ section.title }}</h2>
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-slate-700">
                        <li v-for="item in section.items" :key="item">{{ item }}</li>
                    </ul>
                </section>
            </div>

            <section class="mt-10 rounded-2xl border border-blue-200 bg-blue-50/80 p-6">
                <h2 class="text-lg font-semibold text-slate-900">Questions</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">
                    For privacy requests (access, correction, or deletion of contact details), email
                    <a href="mailto:admin@mentormaths.in" class="font-medium text-blue-700 hover:underline">admin@mentormaths.in</a>.
                </p>
            </section>
        </main>

        <footer class="relative z-10 border-t border-slate-200/60 bg-white/50 py-6 text-center backdrop-blur">
            <p class="text-sm text-slate-500">
                <Link :href="route('home')" class="font-medium text-blue-600 hover:text-blue-800">Mentor Maths</Link>
                · Plan · Practice · Perform
            </p>
        </footer>
    </div>
</template>
