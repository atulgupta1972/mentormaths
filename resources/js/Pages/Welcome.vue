<script setup>
import { Head, Link } from '@inertiajs/vue3';

const classes = ['Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10'];

const pillars = [
    {
        step: 'Plan',
        title: 'Learn the topic',
        description: 'Follow your school syllabus chapter by chapter. Know what to cover before each unit test and exam.',
        accent: 'bg-indigo-600',
        light: 'bg-indigo-50 text-indigo-900',
    },
    {
        step: 'Practice',
        title: 'Starter · Builder · Champion',
        description: 'Topic-wise question sets that grow with your child — from getting comfortable, to building confidence, to exam-ready challenge.',
        accent: 'bg-emerald-600',
        light: 'bg-emerald-50 text-emerald-900',
        tiers: [
            { code: 'S', name: 'Starter', hint: 'Getting comfortable' },
            { code: 'B', name: 'Builder', hint: 'Building confidence' },
            { code: 'C', name: 'Champion', hint: 'Exam-ready challenge' },
        ],
    },
    {
        step: 'Perform',
        title: 'Measure · Evaluate · Restart',
        description: 'Every set is scored and timed. See what improved, what needs work, and practice again until your child is ready.',
        accent: 'bg-amber-500',
        light: 'bg-amber-50 text-amber-900',
    },
];
</script>

<template>
    <Head title="Maths Foundation" />

    <div class="min-h-screen bg-gradient-to-b from-slate-50 via-white to-indigo-50/40">
        <header class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-6">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600">
                    Maths Foundation
                </p>
                <h1 class="text-2xl font-bold text-gray-900">Plan. Practice. Perform.</h1>
            </div>
            <div class="flex gap-3">
                <Link
                    v-if="$page.props.auth?.user"
                    :href="route('dashboard')"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="route('registration.create')"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        Request Registration
                    </Link>
                </template>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 pb-16">
            <section class="text-center">
                <p class="text-sm font-medium uppercase tracking-widest text-indigo-600">
                    CBSE &amp; ICSE · Mathematics
                </p>
                <h2 class="mt-3 text-4xl font-bold tracking-tight text-gray-900 sm:text-5xl">
                    Class 6 to Class 10
                </h2>
                <p class="mx-auto mt-4 max-w-2xl text-lg text-gray-600">
                    A clear path through the syllabus — plan what to learn, practice in structured sets,
                    and perform with scores you can track.
                </p>

                <div class="mt-8 flex flex-wrap justify-center gap-2">
                    <span
                        v-for="klass in classes"
                        :key="klass"
                        class="rounded-full border border-indigo-200 bg-white px-4 py-1.5 text-sm font-medium text-indigo-800 shadow-sm"
                    >
                        {{ klass }}
                    </span>
                </div>
            </section>

            <section class="mt-14 grid gap-6 lg:grid-cols-3">
                <article
                    v-for="pillar in pillars"
                    :key="pillar.step"
                    class="flex flex-col rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-100"
                >
                    <span
                        class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide text-white"
                        :class="pillar.accent"
                    >
                        {{ pillar.step }}
                    </span>
                    <h3 class="mt-4 text-xl font-bold text-gray-900">{{ pillar.title }}</h3>
                    <p class="mt-2 flex-1 text-sm leading-relaxed text-gray-600">
                        {{ pillar.description }}
                    </p>

                    <div v-if="pillar.tiers" class="mt-5 space-y-2">
                        <div
                            v-for="tier in pillar.tiers"
                            :key="tier.name"
                            class="flex items-center gap-3 rounded-lg px-3 py-2"
                            :class="pillar.light"
                        >
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white font-mono text-sm font-bold shadow-sm">
                                {{ tier.code }}
                            </span>
                            <div class="text-left">
                                <p class="text-sm font-semibold">{{ tier.name }}</p>
                                <p class="text-xs opacity-80">{{ tier.hint }}</p>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="pillar.step === 'Perform'" class="mt-5 grid grid-cols-3 gap-2 text-center text-xs font-medium text-amber-900">
                        <div class="rounded-lg bg-amber-50 py-3">Measure</div>
                        <div class="rounded-lg bg-amber-50 py-3">Evaluate</div>
                        <div class="rounded-lg bg-amber-50 py-3">Restart</div>
                    </div>
                </article>
            </section>

            <section class="mt-14 rounded-2xl bg-indigo-600 px-8 py-10 text-center text-white shadow-lg">
                <h3 class="text-2xl font-bold">Ready to begin?</h3>
                <p class="mx-auto mt-2 max-w-lg text-indigo-100">
                    Request access for your child. Your teacher will assign practice sets with target dates —
                    and you can follow progress every step of the way.
                </p>
                <Link
                    v-if="!$page.props.auth?.user"
                    :href="route('registration.create')"
                    class="mt-6 inline-flex rounded-md bg-white px-6 py-3 text-base font-semibold text-indigo-700 hover:bg-indigo-50"
                >
                    Request Registration
                </Link>
                <p class="mt-4 text-sm text-indigo-200">
                    Submit your details. Access is granted after admin approval.
                </p>
            </section>
        </main>
    </div>
</template>
