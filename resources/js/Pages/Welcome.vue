<script setup>
import MentorMathsLogo from '@/Components/MentorMathsLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

const classes = ['Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10'];

const pillars = [
    {
        step: '01',
        title: 'Plan',
        subtitle: 'Learn the topic',
        description: 'Follow CBSE & ICSE syllabus chapter by chapter. Know exactly what to cover before each unit test and exam.',
        icon: '📋',
        gradient: 'from-blue-500 to-cyan-500',
        ring: 'ring-blue-100',
    },
    {
        step: '02',
        title: 'Practice',
        subtitle: 'Starter · Builder · Champion',
        description: 'Structured question sets that grow with your child — from getting comfortable to exam-ready challenge.',
        icon: '✏️',
        gradient: 'from-emerald-500 to-teal-500',
        ring: 'ring-emerald-100',
        tiers: [
            { code: 'S', name: 'Starter', hint: 'Getting comfortable' },
            { code: 'B', name: 'Builder', hint: 'Building confidence' },
            { code: 'C', name: 'Champion', hint: 'Exam-ready' },
        ],
    },
    {
        step: '03',
        title: 'Perform',
        subtitle: 'Score · Track · Improve',
        description: 'Every set is scored and timed. See what improved, what needs work, and practice again until exam day.',
        icon: '🎯',
        gradient: 'from-amber-500 to-orange-500',
        ring: 'ring-amber-100',
    },
];

const highlights = [
    { value: '6–10', label: 'Classes covered' },
    { value: 'S·B·C', label: 'Practice tiers' },
    { value: '100%', label: 'Syllabus aligned' },
];
</script>

<template>
    <Head title="Mentor Maths — Plan. Practice. Perform.">
        <link rel="icon" type="image/svg+xml" href="/logo.svg" />
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />
    </Head>

    <div class="min-h-screen font-[Plus_Jakarta_Sans,sans-serif] text-slate-800">
        <!-- Background -->
        <div class="pointer-events-none fixed inset-0 overflow-hidden">
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-blue-400/20 blur-3xl" />
            <div class="absolute -right-24 top-1/4 h-80 w-80 rounded-full bg-cyan-400/20 blur-3xl" />
            <div class="absolute bottom-0 left-1/3 h-72 w-72 rounded-full bg-amber-300/15 blur-3xl" />
        </div>

        <!-- Header -->
        <header class="relative z-10 mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-5">
            <Link href="/" class="transition opacity-90 hover:opacity-100">
                <MentorMathsLogo class="h-11 w-auto" />
            </Link>
            <div class="flex items-center gap-3">
                <Link
                    v-if="$page.props.auth?.user"
                    :href="route('dashboard')"
                    class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-md shadow-blue-600/25 transition hover:bg-blue-700"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="rounded-xl border border-slate-200 bg-white/80 px-5 py-2.5 text-sm font-semibold text-slate-700 backdrop-blur transition hover:bg-white"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="route('registration.create')"
                        class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-md shadow-blue-600/25 transition hover:bg-blue-700"
                    >
                        Request access
                    </Link>
                </template>
            </div>
        </header>

        <main class="relative z-10 mx-auto max-w-6xl px-6 pb-20">
            <!-- Hero -->
            <section class="pt-8 text-center sm:pt-14">
                <div class="mx-auto inline-flex items-center gap-2 rounded-full border border-blue-200/80 bg-white/70 px-4 py-1.5 text-sm font-medium text-blue-700 shadow-sm backdrop-blur">
                    <span class="h-2 w-2 rounded-full bg-emerald-500" />
                    CBSE &amp; ICSE Mathematics · Class 6 to 10
                </div>

                <h1 class="mx-auto mt-6 max-w-3xl text-4xl font-extrabold tracking-tight text-slate-900 sm:text-6xl sm:leading-[1.1]">
                    Maths practice that
                    <span class="bg-gradient-to-r from-blue-600 to-cyan-600 bg-clip-text text-transparent">
                        builds confidence
                    </span>
                </h1>

                <p class="mx-auto mt-5 max-w-2xl text-lg leading-relaxed text-slate-600">
                    Mentor Maths guides your child through the school syllabus with structured practice sets,
                    clear progress tracking, and teacher-assigned targets — so every exam feels manageable.
                </p>

                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <Link
                        v-if="!$page.props.auth?.user"
                        :href="route('registration.create')"
                        class="rounded-xl bg-gradient-to-r from-blue-600 to-cyan-600 px-8 py-3.5 text-base font-bold text-white shadow-lg shadow-blue-600/30 transition hover:shadow-xl hover:brightness-105"
                    >
                        Get started — request access
                    </Link>
                    <Link
                        :href="route('login')"
                        class="rounded-xl border border-slate-200 bg-white px-8 py-3.5 text-base font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                    >
                        Already registered? Log in
                    </Link>
                </div>

                <div class="mt-10 flex flex-wrap justify-center gap-2">
                    <span
                        v-for="klass in classes"
                        :key="klass"
                        class="rounded-full border border-slate-200/80 bg-white/80 px-4 py-1.5 text-sm font-semibold text-slate-700 shadow-sm backdrop-blur"
                    >
                        {{ klass }}
                    </span>
                </div>
            </section>

            <!-- Stats strip -->
            <section class="mt-16 grid grid-cols-3 gap-4 sm:gap-6">
                <div
                    v-for="item in highlights"
                    :key="item.label"
                    class="rounded-2xl border border-white/60 bg-white/70 px-4 py-5 text-center shadow-sm backdrop-blur sm:px-6"
                >
                    <p class="text-2xl font-extrabold text-blue-600 sm:text-3xl">{{ item.value }}</p>
                    <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500 sm:text-sm">{{ item.label }}</p>
                </div>
            </section>

            <!-- Pillars -->
            <section class="mt-16">
                <div class="text-center">
                    <h2 class="text-2xl font-bold text-slate-900 sm:text-3xl">How Mentor Maths works</h2>
                    <p class="mt-2 text-slate-600">Plan what to learn · Practice in sets · Perform on exam day</p>
                </div>

                <div class="mt-10 grid gap-6 lg:grid-cols-3">
                    <article
                        v-for="pillar in pillars"
                        :key="pillar.title"
                        class="group flex flex-col rounded-2xl border border-white/80 bg-white/80 p-6 shadow-sm ring-1 backdrop-blur transition hover:-translate-y-0.5 hover:shadow-md"
                        :class="pillar.ring"
                    >
                        <div class="flex items-start justify-between">
                            <span
                                class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br text-xl text-white shadow-md"
                                :class="pillar.gradient"
                            >
                                {{ pillar.icon }}
                            </span>
                            <span class="font-mono text-xs font-bold text-slate-300">{{ pillar.step }}</span>
                        </div>

                        <p class="mt-4 text-xs font-bold uppercase tracking-widest text-blue-600">{{ pillar.title }}</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-900">{{ pillar.subtitle }}</h3>
                        <p class="mt-3 flex-1 text-sm leading-relaxed text-slate-600">
                            {{ pillar.description }}
                        </p>

                        <div v-if="pillar.tiers" class="mt-5 space-y-2">
                            <div
                                v-for="tier in pillar.tiers"
                                :key="tier.name"
                                class="flex items-center gap-3 rounded-xl bg-emerald-50 px-3 py-2.5"
                            >
                                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-white font-mono text-sm font-bold text-emerald-700 shadow-sm">
                                    {{ tier.code }}
                                </span>
                                <div class="text-left">
                                    <p class="text-sm font-semibold text-emerald-900">{{ tier.name }}</p>
                                    <p class="text-xs text-emerald-700/80">{{ tier.hint }}</p>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <!-- CTA -->
            <section class="mt-16 overflow-hidden rounded-3xl bg-gradient-to-br from-blue-600 via-blue-700 to-cyan-700 px-8 py-12 text-center text-white shadow-xl shadow-blue-900/20 sm:px-12">
                <MentorMathsLogo variant="mark" class="mx-auto h-14 w-14 rounded-2xl shadow-lg" />
                <h3 class="mt-5 text-2xl font-bold sm:text-3xl">Ready to begin with Mentor Maths?</h3>
                <p class="mx-auto mt-3 max-w-lg text-blue-100">
                    Request access for your child. Your teacher assigns practice sets with target dates —
                    and you follow progress every step of the way.
                </p>
                <Link
                    v-if="!$page.props.auth?.user"
                    :href="route('registration.create')"
                    class="mt-8 inline-flex rounded-xl bg-white px-8 py-3.5 text-base font-bold text-blue-700 shadow-lg transition hover:bg-blue-50"
                >
                    Request Registration
                </Link>
                <p class="mt-4 text-sm text-blue-200/90">
                    Submit your details · Access granted after admin approval
                </p>
            </section>
        </main>

        <footer class="relative z-10 border-t border-slate-200/60 bg-white/50 py-8 text-center backdrop-blur">
            <MentorMathsLogo class="mx-auto h-9 w-auto opacity-80" />
            <p class="mt-3 text-sm text-slate-500">
                <a href="https://mentormaths.in" class="font-medium text-blue-600 hover:text-blue-800">mentormaths.in</a>
                · CBSE &amp; ICSE Mathematics
            </p>
            <p class="mt-1 text-xs text-slate-400">Plan · Practice · Perform</p>
        </footer>
    </div>
</template>
