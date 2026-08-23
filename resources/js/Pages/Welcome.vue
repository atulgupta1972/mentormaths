<script setup>
import MentorMathsLogo from '@/Components/MentorMathsLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

const classes = ['Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10'];

const metrics = [
    {
        code: 'Completion %',
        title: 'How much is done',
        text: 'Share of planned chapter work completed — visible for one student, a school class, or a coaching batch.',
    },
    {
        code: 'Score %',
        title: 'How well it is done',
        text: 'Average accuracy on scored sets. Same score language for individual, school class, and coaching class views.',
    },
    {
        code: 'Revision status',
        title: 'Weakness queue',
        text: 'Done vs pending corrections. Wrong sums stay visible until revised — at student, class, or batch level.',
    },
];

const audiences = [
    {
        title: 'Individual',
        text: 'One student sees their own study-plan scorecard: Completion %, Score %, Revision status — chapter by chapter.',
    },
    {
        title: 'School class',
        text: 'Teachers manage the whole class professionally — same scorecard per student, clear who needs help on completion, score, or revision.',
    },
    {
        title: 'Coaching class',
        text: 'Run batches with one syllabus map. Completion %, Score %, and Revision status stay comparable across the coaching class.',
    },
    {
        title: 'Tuition / home',
        text: 'Small groups or home learners use the same measured study plan — mark Under study and work becomes due that day.',
    },
];

const pillars = [
    {
        title: 'Plan',
        text: 'CBSE & ICSE chapters on one professional study plan. Mark Studied / Under study. Exam chapters sit in plain sight.',
    },
    {
        title: 'Practice',
        text: 'Tiered sets, written work, fill-blanks, formula and basics drills — assigned when learning starts, due the same day.',
    },
    {
        title: 'Perform',
        text: 'Completion % · Score % · Revision status — numbers that tell whether study is working before the school test.',
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
    <Head title="Mentor Maths — The maths of studying maths.">
        <link rel="icon" type="image/svg+xml" href="/logo.svg" />
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link href="https://fonts.bunny.net/css?family=fraunces:600,700&family=source-sans-3:400,500,600,700&display=swap" rel="stylesheet" />
        <meta
            name="description"
            content="Mentor Maths — professional CBSE & ICSE maths for individual, school class, and coaching class. Study-plan scorecard: Completion %, Score %, Revision status. Soft launch."
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
            .mm-pattern {
                background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill='%230f766e' fill-opacity='0.04' d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/svg%3E");
            }
        </style>

        <!-- Soft atmosphere (not a flat wash) -->
        <div class="pointer-events-none fixed inset-0 overflow-hidden" aria-hidden="true">
            <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_#dce8e2_0%,_#f3f6f4_55%,_#eef2f7_100%)]"></div>
            <div class="absolute -left-20 top-24 h-72 w-72 rounded-full bg-teal-400/15 blur-3xl"></div>
            <div class="absolute right-0 top-0 h-[28rem] w-[28rem] rounded-full bg-sky-400/10 blur-3xl"></div>
            <div class="mm-pattern absolute inset-0 opacity-[0.35]"></div>
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
            <!-- Hero: compact copy (left) + scorecard preview (right) -->
            <section class="relative mx-auto max-w-7xl px-5 pb-10 pt-4 sm:px-8 sm:pt-8">
                <div class="grid items-start gap-8 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
                    <div class="mm-rise max-w-md lg:max-w-none">
                        <p class="inline-flex items-center gap-2 rounded-full border border-teal-700/20 bg-white/70 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-teal-900 backdrop-blur">
                            Soft launch · Schools · Coaching · Home
                        </p>

                        <h1 class="mm-rise-delay mt-4 font-['Fraunces',Georgia,serif] text-3xl font-bold tracking-tight text-[#0f4c5c] sm:text-4xl sm:leading-[1.08]">
                            Mentor Maths
                        </h1>

                        <p class="mm-rise-delay mt-3 text-base font-semibold leading-snug text-slate-800 sm:text-lg">
                            Study plan scorecard — Completion %, Score %, Revision status.
                        </p>

                        <p class="mm-rise-late mt-3 text-sm leading-relaxed text-slate-600">
                            The maths of studying maths for
                            <span class="font-semibold text-slate-800">individual</span>,
                            <span class="font-semibold text-slate-800">school class</span>, and
                            <span class="font-semibold text-slate-800">coaching class</span>.
                        </p>

                        <div class="mm-rise-late mt-5 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                            <Link
                                v-if="!$page.props.auth?.user"
                                :href="route('registration.create')"
                                class="rounded-lg bg-[#0f4c5c] px-5 py-2.5 text-center text-sm font-bold text-white transition hover:bg-[#0a3642]"
                            >
                                Request early access
                            </Link>
                            <Link
                                :href="route('login')"
                                class="rounded-lg border border-slate-300 bg-white/90 px-5 py-2.5 text-center text-sm font-semibold text-slate-800 transition hover:bg-white"
                            >
                                Log in
                            </Link>
                            <Link
                                v-if="!$page.props.auth?.user"
                                :href="route('teacher-registration.create')"
                                class="rounded-lg border border-teal-800/30 bg-teal-50/80 px-5 py-2.5 text-center text-sm font-semibold text-teal-950 transition hover:bg-teal-100"
                            >
                                Mentors — join
                            </Link>
                        </div>

                        <div class="mt-5 flex flex-wrap gap-1.5">
                            <span
                                v-for="klass in classes"
                                :key="klass"
                                class="rounded border border-slate-200 bg-white/80 px-2 py-0.5 text-[11px] font-semibold text-slate-700"
                            >
                                {{ klass }}
                            </span>
                            <span class="rounded border border-teal-200 bg-teal-50/90 px-2 py-0.5 text-[11px] font-semibold text-teal-900">
                                CBSE · ICSE
                            </span>
                        </div>
                    </div>

                    <div class="mm-rise-late min-w-0">
                        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-[#0f4c5c]">
                                Study plan scorecard
                            </p>
                            <p class="text-[11px] font-medium text-slate-500">
                                Individual · School · Coaching
                            </p>
                        </div>

                        <div class="overflow-hidden rounded-lg border border-slate-300/80 bg-[#f8faf9] shadow-xl shadow-slate-900/10">
                            <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-100 px-3 py-1.5">
                                <span class="h-2 w-2 rounded-full bg-rose-400"></span>
                                <span class="h-2 w-2 rounded-full bg-amber-400"></span>
                                <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                                <span class="ml-1.5 text-[10px] font-semibold text-slate-500">mentormaths.in · Student home</span>
                            </div>

                            <div class="grid gap-0 xl:grid-cols-[1.2fr_0.8fr]">
                                <div class="border-b border-slate-200 p-2.5 sm:p-3 xl:border-b-0 xl:border-r">
                                    <div class="mb-2 grid grid-cols-3 gap-1.5">
                                        <div class="rounded border border-sky-200 bg-sky-50 px-2 py-1.5">
                                            <p class="text-[9px] font-bold uppercase tracking-wide text-sky-800">Completion %</p>
                                            <p class="text-lg font-extrabold tabular-nums text-sky-950">26%</p>
                                            <div class="mt-1 h-1 overflow-hidden rounded-full bg-sky-200">
                                                <div class="mm-bar h-full rounded-full bg-sky-600" style="--mm-w: 26%"></div>
                                            </div>
                                        </div>
                                        <div class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1.5">
                                            <p class="text-[9px] font-bold uppercase tracking-wide text-emerald-800">Score %</p>
                                            <p class="text-lg font-extrabold tabular-nums text-emerald-950">78%</p>
                                            <div class="mt-1 h-1 overflow-hidden rounded-full bg-emerald-200">
                                                <div class="mm-bar h-full rounded-full bg-emerald-600" style="--mm-w: 78%"></div>
                                            </div>
                                        </div>
                                        <div class="rounded border border-orange-200 bg-orange-50 px-2 py-1.5">
                                            <p class="text-[9px] font-bold uppercase tracking-wide text-orange-900">Revision</p>
                                            <p class="text-sm font-extrabold leading-tight text-orange-950">
                                                <span class="text-emerald-700">0</span>
                                                <span class="text-slate-400"> · </span>
                                                <span class="text-rose-700">8</span>
                                            </p>
                                            <p class="text-[9px] font-medium text-orange-800/80">pending</p>
                                        </div>
                                    </div>

                                    <table class="w-full border-collapse text-left text-[10px] sm:text-[11px]">
                                        <thead>
                                            <tr class="bg-[#0b2a5b] text-white">
                                                <th class="px-1.5 py-1 font-semibold">Ch</th>
                                                <th class="px-1.5 py-1 font-semibold">Chapter</th>
                                                <th class="bg-sky-800 px-1.5 py-1 text-center font-bold">Comp %</th>
                                                <th class="bg-violet-800 px-1.5 py-1 text-center font-bold">Score %</th>
                                                <th class="bg-orange-700 px-1.5 py-1 text-center font-bold">Rev.</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="(row, i) in mockChapters"
                                                :key="row.no"
                                                :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50'"
                                            >
                                                <td class="whitespace-nowrap px-1.5 py-1 font-semibold text-slate-800">{{ row.no }}</td>
                                                <td class="max-w-[7rem] truncate px-1.5 py-1 text-slate-700 sm:max-w-[10rem]">
                                                    {{ row.name }}
                                                    <span
                                                        v-if="row.under"
                                                        class="ml-0.5 rounded bg-amber-100 px-1 py-px text-[8px] font-bold uppercase text-amber-900"
                                                    >Under study</span>
                                                </td>
                                                <td class="px-1.5 py-1 text-center font-bold tabular-nums text-sky-800">{{ row.comp }}</td>
                                                <td class="px-1.5 py-1 text-center font-bold tabular-nums text-emerald-800">{{ row.score }}</td>
                                                <td class="px-1.5 py-1 text-center font-bold tabular-nums text-orange-800">{{ row.revise }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="space-y-2 bg-slate-50/80 p-2.5 sm:p-3">
                                    <div class="rounded border border-slate-200 bg-white p-2.5">
                                        <p class="text-[9px] font-bold uppercase tracking-wide text-slate-500">Daily formula drill</p>
                                        <p class="mt-0.5 text-xs font-semibold text-slate-900">Algebra · identities</p>
                                        <p class="mt-1 text-[11px] leading-snug text-slate-600">
                                            Short drills so formulas stay automatic.
                                        </p>
                                        <div class="mt-2 flex items-center justify-between text-[11px]">
                                            <span class="font-semibold text-teal-800">Streak ready</span>
                                            <span class="rounded bg-teal-700 px-2 py-0.5 font-bold text-white">Start</span>
                                        </div>
                                    </div>
                                    <div class="rounded border border-slate-200 bg-white p-2.5">
                                        <p class="text-[9px] font-bold uppercase tracking-wide text-slate-500">Teacher · whole class</p>
                                        <p class="mt-0.5 text-xs font-semibold text-slate-900">Manage professionally</p>
                                        <ul class="mt-1.5 space-y-1 text-[11px] text-slate-700">
                                            <li class="flex justify-between gap-2"><span>Class study plans</span><span class="font-bold text-emerald-700">one view</span></li>
                                            <li class="flex justify-between gap-2"><span>Scorecard</span><span class="font-bold text-sky-800">per student</span></li>
                                            <li class="flex justify-between gap-2"><span>Weak sums</span><span class="font-bold text-rose-700">queued</span></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Three numbers students see -->
            <section class="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                <h2 class="font-['Fraunces',Georgia,serif] text-3xl font-semibold text-[#0f4c5c]">
                    The maths of studying maths
                </h2>
                <p class="mt-2 max-w-2xl text-base text-slate-600">
                    Studying is treated like a measurable process — not hope, not “I finished the chapter in school”.
                    Individual, school class, and coaching class all read the same three signals.
                </p>
                <div class="mt-8 grid gap-6 md:grid-cols-3">
                    <article
                        v-for="metric in metrics"
                        :key="metric.code"
                        class="border-t-4 border-teal-700 pt-4"
                    >
                        <p class="font-mono text-xs font-bold uppercase tracking-wide text-teal-800">{{ metric.code }}</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-900">{{ metric.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ metric.text }}</p>
                    </article>
                </div>
            </section>

            <!-- Plan · Practice · Perform -->
            <section class="border-y border-slate-200/80 bg-white/60">
                <div class="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                    <h2 class="font-['Fraunces',Georgia,serif] text-3xl font-semibold text-[#0f4c5c]">
                        Plan · Practice · Perform
                    </h2>
                    <p class="mt-2 max-w-2xl text-base text-slate-600">
                        A professional loop for schools and mentors — every set scored, dated, and visible on the scorecard.
                    </p>
                    <div class="mt-8 grid gap-6 md:grid-cols-3">
                        <article
                            v-for="(pillar, index) in pillars"
                            :key="pillar.title"
                            class="border-t-4 border-[#0f4c5c] pt-4"
                        >
                            <p class="font-mono text-xs font-bold text-slate-400">0{{ index + 1 }}</p>
                            <h3 class="mt-1 text-xl font-bold text-slate-900">{{ pillar.title }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ pillar.text }}</p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Who can use it -->
            <section class="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                <h2 class="font-['Fraunces',Georgia,serif] text-3xl font-semibold text-[#0f4c5c]">
                    Individual · School class · Coaching class
                </h2>
                <p class="mt-2 max-w-2xl text-base text-slate-600">
                    Same study-plan scorecard at every scale — so a teacher can run a full class professionally,
                    and one student still sees honest Completion %, Score %, and Revision status.
                </p>
                <div class="mt-8 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    <article
                        v-for="item in audiences"
                        :key="item.title"
                    >
                        <h3 class="text-lg font-bold text-slate-900">{{ item.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ item.text }}</p>
                    </article>
                </div>
            </section>

            <!-- Guarantee framing + teacher -->
            <section class="border-y border-slate-200/80 bg-white/60">
                <div class="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                    <div class="grid gap-10 lg:grid-cols-2 lg:items-start">
                        <div>
                            <h2 class="font-['Fraunces',Georgia,serif] text-3xl font-semibold text-[#0f4c5c]">
                                Professional maths practice — numbers you can act on
                            </h2>
                            <p class="mt-4 text-base leading-relaxed text-slate-700">
                                When a student marks a chapter <span class="font-semibold">Studied</span> or
                                <span class="font-semibold">Under study</span>, work becomes due that day.
                                <span class="font-semibold">Completion %</span>, <span class="font-semibold">Score %</span>, and
                                <span class="font-semibold">Revision status</span> update on the scorecard —
                                for the individual student and for the teacher viewing school or coaching class.
                            </p>
                            <p class="mt-4 text-base leading-relaxed text-slate-700">
                                <span class="font-semibold text-[#0f4c5c]">If work is done regularly — logged in, with drills and sets — learning is not left to chance.</span>
                                Skip days and the numbers stall. Show up and the scorecard moves.
                            </p>
                            <ul class="mt-6 space-y-2 text-sm text-slate-700">
                                <li class="flex gap-2"><span class="font-bold text-teal-800">→</span> Completion % of planned chapter work</li>
                                <li class="flex gap-2"><span class="font-bold text-teal-800">→</span> Score % on practised sets</li>
                                <li class="flex gap-2"><span class="font-bold text-teal-800">→</span> Revision status for weak / wrong sums</li>
                                <li class="flex gap-2"><span class="font-bold text-teal-800">→</span> Individual, school class, and coaching class views</li>
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
                                packaging continues; the professional learning loop is already live.
                            </p>
                        </aside>
                    </div>
                </div>
            </section>

            <!-- Soft launch CTA -->
            <section class="bg-white/70">
                <div class="mx-auto max-w-6xl px-5 py-14 text-center sm:px-8">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-teal-800">Facebook soft launch</p>
                    <h2 class="mt-3 font-['Fraunces',Georgia,serif] text-3xl font-semibold text-[#0f4c5c] sm:text-4xl">
                        Bring measured maths to your class
                    </h2>
                    <p class="mx-auto mt-4 max-w-xl text-base leading-relaxed text-slate-600">
                        Soft launch for individual learners, school classes, and coaching classes.
                        Request access and start on the study-plan scorecard — Completion %, Score %, Revision status.
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
