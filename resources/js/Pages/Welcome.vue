<script setup>
import MentorMathsLogo from '@/Components/MentorMathsLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

const classes = ['Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10'];

const audiences = [
    {
        title: 'Coaching class',
        text: 'One syllabus map for every batch. Mentors see who studied what, what’s due today, and where scores are slipping — without chasing notebooks.',
    },
    {
        title: 'Tuition / small group',
        text: 'Assign chapter work the day a student marks Under study. Corrections and revise queues stay visible so every session has a clear next step.',
    },
    {
        title: 'Individual learner',
        text: 'Follow the school chapter order, practise in tiers, and watch Comp % and Score % move on the study-plan scorecard — like a report card that updates as you work.',
    },
];

const pillars = [
    {
        title: 'Plan',
        text: 'CBSE & ICSE chapters on one study plan. Mark Studied / Under study. Upcoming exams sit on the chapters that matter.',
    },
    {
        title: 'Practice',
        text: 'Starter · Builder · Champion sets, written work, fill-blanks, formula and basics drills — assigned when learning starts, due the same day.',
    },
    {
        title: 'Perform',
        text: 'Completion, average score, and revise (wrong sums to redo) on one scorecard. Improve the numbers before the school test.',
    },
];

const mockChapters = [
    { no: 'Ch 1', name: 'Large numbers around us', comp: '8%', score: '100%', revise: '0/2', studied: true },
    { no: 'Ch 2', name: 'Arithmetic Expressions', comp: '27%', score: '79%', revise: '0/24', studied: true },
    { no: 'Ch 4', name: 'Letter-Numbers · Equations', comp: '21%', score: '87%', revise: '0/5', studied: true },
    { no: 'Ch 6', name: 'Measuring Space', comp: '0%', score: '—', revise: '0/0', under: true },
];
</script>

<template>
    <Head title="Mentor Maths — Planned practice. Visible progress.">
        <link rel="icon" type="image/svg+xml" href="/logo.svg" />
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link href="https://fonts.bunny.net/css?family=fraunces:600,700&family=source-sans-3:400,500,600,700&display=swap" rel="stylesheet" />
        <meta
            name="description"
            content="Mentor Maths — CBSE & ICSE maths study plan, drills, and scorecards for coaching, tuition, and individual learners. Soft launch."
        />
    </Head>

    <div class="min-h-screen bg-[#f3f6f4] font-['Source_Sans_3',system-ui,sans-serif] text-slate-800 antialiased">
        <style>
            @keyframes mm-rise {
                from { opacity: 0; transform: translateY(14px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @keyframes mm-bar {
                from { width: 0; }
                to { width: var(--mm-w); }
            }
            .mm-rise { animation: mm-rise 0.7s ease-out both; }
            .mm-rise-delay { animation: mm-rise 0.75s ease-out 0.12s both; }
            .mm-rise-late { animation: mm-rise 0.8s ease-out 0.22s both; }
            .mm-bar { animation: mm-bar 1.1s ease-out 0.4s both; }
        </style>

        <!-- Soft atmosphere (not a flat wash) -->
        <div class="pointer-events-none fixed inset-0 overflow-hidden" aria-hidden="true">
            <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_#dce8e2_0%,_#f3f6f4_55%,_#eef2f7_100%)]" />
            <div class="absolute -left-20 top-24 h-72 w-72 rounded-full bg-teal-400/15 blur-3xl" />
            <div class="absolute right-0 top-0 h-[28rem] w-[28rem] rounded-full bg-sky-400/10 blur-3xl" />
            <div
                class="absolute inset-0 opacity-[0.35]"
                style="background-image: url(\"data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%230f766e' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E\");"
            />
        </div>

        <header class="relative z-20 mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-5 py-5 sm:px-8">
            <Link href="/" class="opacity-95 transition hover:opacity-100">
                <MentorMathsLogo size-class="h-11 w-auto" />
            </Link>
            <div class="flex flex-wrap items-center justify-end gap-2 sm:gap-3">
                <Link
                    v-if="$page.props.auth?.user"
                    :href="route('dashboard')"
                    class="rounded-lg bg-[#0f4c5c] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#0a3642]"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="rounded-lg border border-slate-300/80 bg-white/80 px-4 py-2 text-sm font-semibold text-slate-700 backdrop-blur transition hover:bg-white sm:px-5 sm:py-2.5"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="route('registration.create')"
                        class="rounded-lg border border-slate-300/80 bg-white/80 px-4 py-2 text-sm font-semibold text-slate-700 backdrop-blur transition hover:bg-white sm:px-5 sm:py-2.5"
                    >
                        Request access
                    </Link>
                    <Link
                        :href="route('teacher-registration.create')"
                        class="rounded-lg bg-[#0f4c5c] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#0a3642] sm:px-5 sm:py-2.5"
                    >
                        Join as mentor
                    </Link>
                </template>
            </div>
        </header>

        <main class="relative z-10">
            <!-- Hero: brand + one idea + CTA + dominant product visual -->
            <section class="relative mx-auto max-w-6xl px-5 pb-6 pt-4 sm:px-8 sm:pt-8">
                <p class="mm-rise inline-flex items-center gap-2 rounded-full border border-teal-700/20 bg-white/70 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-teal-900 backdrop-blur">
                    Soft launch · Early access
                </p>

                <h1 class="mm-rise-delay mt-5 font-['Fraunces',Georgia,serif] text-4xl font-bold tracking-tight text-[#0f4c5c] sm:text-6xl sm:leading-[1.05]">
                    Mentor Maths
                </h1>

                <p class="mm-rise-delay mt-4 max-w-2xl text-xl font-medium leading-snug text-slate-800 sm:text-2xl">
                    Planned practice. A living scorecard. Maths that improves when you show up.
                </p>

                <p class="mm-rise-late mt-4 max-w-2xl text-base leading-relaxed text-slate-600 sm:text-lg">
                    For coaching classes, tuition groups, and individual students — follow the school syllabus,
                    drill the concepts, and watch completion and score move chapter by chapter.
                    Learning holds when work is done regularly and logged in.
                </p>

                <div class="mm-rise-late mt-7 flex flex-wrap gap-3">
                    <Link
                        v-if="!$page.props.auth?.user"
                        :href="route('registration.create')"
                        class="rounded-lg bg-[#0f4c5c] px-7 py-3 text-base font-bold text-white transition hover:bg-[#0a3642]"
                    >
                        Request early access
                    </Link>
                    <Link
                        :href="route('login')"
                        class="rounded-lg border border-slate-300 bg-white/90 px-7 py-3 text-base font-semibold text-slate-800 transition hover:bg-white"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="!$page.props.auth?.user"
                        :href="route('teacher-registration.create')"
                        class="rounded-lg border border-teal-800/30 bg-teal-50/80 px-7 py-3 text-base font-semibold text-teal-950 transition hover:bg-teal-100"
                    >
                        Mentors — join
                    </Link>
                </div>

                <div class="mt-8 flex flex-wrap gap-2">
                    <span
                        v-for="klass in classes"
                        :key="klass"
                        class="rounded-md border border-slate-200 bg-white/80 px-3 py-1 text-xs font-semibold text-slate-700"
                    >
                        {{ klass }}
                    </span>
                    <span class="rounded-md border border-teal-200 bg-teal-50/90 px-3 py-1 text-xs font-semibold text-teal-900">
                        CBSE · ICSE
                    </span>
                </div>
            </section>

            <!-- Dominant product plane: study-plan scorecard preview -->
            <section class="mm-rise-late relative border-y border-slate-300/60 bg-[#0f4c5c]">
                <div class="mx-auto max-w-6xl px-5 py-8 sm:px-8 sm:py-10">
                    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-teal-200/90">What mentors and students see</p>
                            <h2 class="mt-1 font-['Fraunces',Georgia,serif] text-2xl font-semibold text-white sm:text-3xl">
                                Study plan scorecard
                            </h2>
                        </div>
                        <p class="max-w-sm text-sm leading-relaxed text-teal-100/90">
                            Comp % · Score % · Revise — the same language for home, tuition, and coaching.
                        </p>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-white/15 bg-[#f8faf9] shadow-2xl shadow-black/25">
                        <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-100 px-3 py-2">
                            <span class="h-2.5 w-2.5 rounded-full bg-rose-400" />
                            <span class="h-2.5 w-2.5 rounded-full bg-amber-400" />
                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-400" />
                            <span class="ml-2 text-[11px] font-semibold text-slate-500">mentormaths.in · Student home</span>
                        </div>

                        <div class="grid gap-0 lg:grid-cols-[1.15fr_0.85fr]">
                            <div class="border-b border-slate-200 p-3 sm:p-4 lg:border-b-0 lg:border-r">
                                <div class="mb-3 grid grid-cols-3 gap-2">
                                    <div class="rounded-md border border-sky-200 bg-sky-50 px-2.5 py-2">
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-sky-800">Completion</p>
                                        <p class="text-2xl font-extrabold tabular-nums text-sky-950">26%</p>
                                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-sky-200">
                                            <div class="mm-bar h-full rounded-full bg-sky-600" style="--mm-w: 26%" />
                                        </div>
                                    </div>
                                    <div class="rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-2">
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-emerald-800">Score</p>
                                        <p class="text-2xl font-extrabold tabular-nums text-emerald-950">78%</p>
                                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-emerald-200">
                                            <div class="mm-bar h-full rounded-full bg-emerald-600" style="--mm-w: 78%" />
                                        </div>
                                    </div>
                                    <div class="rounded-md border border-orange-200 bg-orange-50 px-2.5 py-2">
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-orange-900">Revise</p>
                                        <p class="text-lg font-extrabold leading-tight text-orange-950">
                                            <span class="text-emerald-700">0</span>
                                            <span class="text-slate-400"> · </span>
                                            <span class="text-rose-700">8</span>
                                        </p>
                                        <p class="text-[10px] font-medium text-orange-800/80">pending redo</p>
                                    </div>
                                </div>

                                <table class="w-full border-collapse text-left text-[11px] sm:text-xs">
                                    <thead>
                                        <tr class="bg-[#0b2a5b] text-white">
                                            <th class="px-2 py-1.5 font-semibold">Ch No</th>
                                            <th class="px-2 py-1.5 font-semibold">Chapter</th>
                                            <th class="bg-sky-800 px-2 py-1.5 text-center font-bold">Comp %</th>
                                            <th class="bg-violet-800 px-2 py-1.5 text-center font-bold">Score %</th>
                                            <th class="bg-orange-700 px-2 py-1.5 text-center font-bold">Revise</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="(row, i) in mockChapters"
                                            :key="row.no"
                                            :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50'"
                                        >
                                            <td class="whitespace-nowrap px-2 py-1.5 font-semibold text-slate-800">{{ row.no }}</td>
                                            <td class="max-w-[9rem] truncate px-2 py-1.5 text-slate-700 sm:max-w-none">
                                                {{ row.name }}
                                                <span
                                                    v-if="row.under"
                                                    class="ml-1 rounded bg-amber-100 px-1 py-px text-[9px] font-bold uppercase text-amber-900"
                                                >Under study</span>
                                            </td>
                                            <td class="px-2 py-1.5 text-center font-bold tabular-nums text-sky-800">{{ row.comp }}</td>
                                            <td class="px-2 py-1.5 text-center font-bold tabular-nums text-emerald-800">{{ row.score }}</td>
                                            <td class="px-2 py-1.5 text-center font-bold tabular-nums text-orange-800">{{ row.revise }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="space-y-3 bg-slate-50/80 p-3 sm:p-4">
                                <div class="rounded-md border border-slate-200 bg-white p-3">
                                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Daily formula drill</p>
                                    <p class="mt-1 text-sm font-semibold text-slate-900">Algebra · identities &amp; forms</p>
                                    <p class="mt-2 text-xs leading-relaxed text-slate-600">
                                        Short, regular drills so formulas stay automatic — designed the way a classroom mentor
                                        would warm up a batch before harder sets.
                                    </p>
                                    <div class="mt-3 flex items-center justify-between text-xs">
                                        <span class="font-semibold text-teal-800">Today’s streak ready</span>
                                        <span class="rounded bg-teal-700 px-2 py-1 font-bold text-white">Start drill</span>
                                    </div>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-white p-3">
                                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Mentor view</p>
                                    <p class="mt-1 text-sm font-semibold text-slate-900">Who is on track</p>
                                    <ul class="mt-2 space-y-1.5 text-xs text-slate-700">
                                        <li class="flex justify-between gap-2"><span>Studied chapters marked</span><span class="font-bold text-emerald-700">clear</span></li>
                                        <li class="flex justify-between gap-2"><span>Sets due today</span><span class="font-bold text-sky-800">auto</span></li>
                                        <li class="flex justify-between gap-2"><span>Wrong sums to revise</span><span class="font-bold text-rose-700">queued</span></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Plan · Practice · Perform -->
            <section class="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                <h2 class="font-['Fraunces',Georgia,serif] text-3xl font-semibold text-[#0f4c5c]">
                    Plan · Practice · Perform
                </h2>
                <p class="mt-2 max-w-2xl text-base text-slate-600">
                    The same loop on the site that parents and mentors use on paper — except every set is scored,
                    dated, and visible.
                </p>
                <div class="mt-8 grid gap-6 md:grid-cols-3">
                    <article
                        v-for="(pillar, index) in pillars"
                        :key="pillar.title"
                        class="border-t-4 border-teal-700 pt-4"
                    >
                        <p class="font-mono text-xs font-bold text-slate-400">0{{ index + 1 }}</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-900">{{ pillar.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ pillar.text }}</p>
                    </article>
                </div>
            </section>

            <!-- Who can use it -->
            <section class="border-y border-slate-200/80 bg-white/60">
                <div class="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                    <h2 class="font-['Fraunces',Georgia,serif] text-3xl font-semibold text-[#0f4c5c]">
                        Coaching · Tuition · Individual
                    </h2>
                    <p class="mt-2 max-w-2xl text-base text-slate-600">
                        One system that scales from a single child at home to a full coaching batch —
                        because the study plan is the classroom wall chart, digitised.
                    </p>
                    <div class="mt-8 grid gap-8 md:grid-cols-3">
                        <article
                            v-for="item in audiences"
                            :key="item.title"
                        >
                            <h3 class="text-lg font-bold text-slate-900">{{ item.title }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ item.text }}</p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Guarantee framing + teacher -->
            <section class="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                <div class="grid gap-10 lg:grid-cols-2 lg:items-start">
                    <div>
                        <h2 class="font-['Fraunces',Georgia,serif] text-3xl font-semibold text-[#0f4c5c]">
                            Improvement is mathematical — and visible
                        </h2>
                        <p class="mt-4 text-base leading-relaxed text-slate-700">
                            When a student marks a chapter <span class="font-semibold">Studied</span> or
                            <span class="font-semibold">Under study</span>, work becomes due that day.
                            Scores, completion, and revise counts update on the scorecard.
                            Mentors and students both see the same truth.
                        </p>
                        <p class="mt-4 text-base leading-relaxed text-slate-700">
                            <span class="font-semibold text-[#0f4c5c]">If the work is done — regularly, logged in, with drills and sets — learning is not left to chance.</span>
                            Skip days and the numbers stall. Show up and the scorecard moves.
                        </p>
                        <ul class="mt-6 space-y-2 text-sm text-slate-700">
                            <li class="flex gap-2"><span class="font-bold text-teal-800">→</span> Formula &amp; basics drills for automatic fluency</li>
                            <li class="flex gap-2"><span class="font-bold text-teal-800">→</span> Chapter practice in Starter · Builder · Champion tiers</li>
                            <li class="flex gap-2"><span class="font-bold text-teal-800">→</span> Corrections and revise until weak sums are cleared</li>
                            <li class="flex gap-2"><span class="font-bold text-teal-800">→</span> Exam chapters highlighted on the study plan</li>
                        </ul>
                    </div>
                    <aside class="rounded-xl border border-slate-200 bg-[#0f4c5c] px-6 py-7 text-teal-50 sm:px-8">
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-teal-200">Designed in the classroom</p>
                        <p class="mt-3 font-['Fraunces',Georgia,serif] text-2xl font-semibold leading-snug text-white">
                            Built by a teacher who has been teaching maths since his own Class 11 days.
                        </p>
                        <p class="mt-4 text-sm leading-relaxed text-teal-100/95">
                            The drills, tiers, and study-plan rhythm come from years of watching what actually sticks —
                            not from a generic worksheet dump. Soft launch means we are opening carefully while commercial
                            packaging continues; the learning loop is already live.
                        </p>
                    </aside>
                </div>
            </section>

            <!-- Soft launch CTA -->
            <section class="border-t border-slate-200 bg-white/70">
                <div class="mx-auto max-w-6xl px-5 py-14 text-center sm:px-8">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-teal-800">Facebook soft launch</p>
                    <h2 class="mt-3 font-['Fraunces',Georgia,serif] text-3xl font-semibold text-[#0f4c5c] sm:text-4xl">
                        Try the planned way early
                    </h2>
                    <p class="mx-auto mt-4 max-w-xl text-base leading-relaxed text-slate-600">
                        Mentormaths.in is in soft launch — not a finished “product brochure” yet.
                        If you run a coaching class, tuition, or want structured practice for one child, request access
                        and start on the study plan.
                    </p>
                    <div class="mt-8 flex flex-wrap justify-center gap-3">
                        <Link
                            v-if="!$page.props.auth?.user"
                            :href="route('registration.create')"
                            class="rounded-lg bg-[#0f4c5c] px-8 py-3.5 text-base font-bold text-white transition hover:bg-[#0a3642]"
                        >
                            Request student access
                        </Link>
                        <Link
                            v-if="!$page.props.auth?.user"
                            :href="route('teacher-registration.create')"
                            class="rounded-lg border border-[#0f4c5c]/40 bg-teal-50 px-8 py-3.5 text-base font-bold text-[#0f4c5c] transition hover:bg-teal-100"
                        >
                            Request mentor access
                        </Link>
                        <Link
                            :href="route('login')"
                            class="rounded-lg border border-slate-300 bg-white px-8 py-3.5 text-base font-semibold text-slate-800 transition hover:bg-slate-50"
                        >
                            Log in
                        </Link>
                    </div>
                    <p class="mt-5 text-sm text-slate-500">
                        mentormaths.in · Access after a short approval · Soft launch
                    </p>
                </div>
            </section>
        </main>

        <footer class="relative z-10 border-t border-slate-200 bg-white/80 py-8 text-center">
            <MentorMathsLogo size-class="mx-auto h-9 w-auto opacity-90" />
            <p class="mt-3 text-sm text-slate-500">
                <a href="https://mentormaths.in" class="font-medium text-[#0f4c5c] hover:underline">mentormaths.in</a>
                · CBSE &amp; ICSE Mathematics · Class 4–10
            </p>
            <p class="mt-2 text-xs text-slate-400">
                <Link :href="route('privacy')" class="hover:text-slate-600 hover:underline">Privacy policy</Link>
                · Plan · Practice · Perform
            </p>
        </footer>
    </div>
</template>
