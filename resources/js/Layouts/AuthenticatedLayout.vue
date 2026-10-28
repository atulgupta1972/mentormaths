<script setup>
import { ref, computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import AdminClassSelector from '@/Components/AdminClassSelector.vue';
import NavDropdown from '@/Components/NavDropdown.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavGroup from '@/Components/ResponsiveNavGroup.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import AssignmentWhatsAppPrompt from '@/Components/AssignmentWhatsAppPrompt.vue';
import { assignToClassPath, hasRoute, safeRoute } from '@/utils/routes';

const showingNavigationDropdown = ref(false);
const page = usePage();
const isAdmin = computed(() => page.props.auth?.isAdmin ?? false);
const isMentor = computed(() => page.props.auth?.isMentor ?? false);
const isContentUploader = computed(() => page.props.auth?.isContentUploader ?? false);
const isStudent = computed(() => page.props.auth?.isStudent ?? false);

const peopleGroup = computed(() => ({
    label: 'People',
    active:
        route().current('admin.users.*')
        || route().current('admin.groups.*')
        || route().current('admin.registration-requests.*')
        || route().current('admin.access-codes.*')
        || route().current('admin.teacher-registrations.*')
        || route().current('admin.students.*'),
    items: [
        {
            label: 'Users',
            href: route('admin.users.index'),
            active: route().current('admin.users.*') || route().current('admin.groups.*'),
            show: isAdmin.value,
        },
        {
            label: 'Registrations',
            href: route('admin.registration-requests.index'),
            active: route().current('admin.registration-requests.*'),
            show: isAdmin.value,
        },
        {
            label: 'Access codes (tcode)',
            href: route('admin.access-codes.index'),
            active: route().current('admin.access-codes.*'),
            show: isAdmin.value,
        },
        {
            label: 'Mentor applications',
            href: route('admin.teacher-registrations.index'),
            active: route().current('admin.teacher-registrations.*'),
            show: isAdmin.value,
        },
        {
            label: 'Students',
            href: route('admin.students.index'),
            active: route().current('admin.students.*'),
            show: isAdmin.value,
        },
    ],
}));

const teachingGroup = computed(() => ({
    label: 'Teaching',
    active:
        route().current('admin.classes.*')
        || route().current('mentor.classes.*')
        || route().current('admin.practice-sets.*')
        || route().current('admin.catch-up.*')
        || route().current('admin.written-sheets.*')
        || route().current('admin.written-review.*')
        || route().current('admin.student-work-report.*')
        || route().current('admin.school-study-plan.*'),
    items: [
        {
            label: 'Classes',
            href: isMentor.value && !isAdmin.value
                ? route('mentor.classes.index')
                : route('admin.classes.index'),
            active: route().current('admin.classes.index')
                || route().current('admin.classes.show')
                || route().current('mentor.classes.*'),
            show: isAdmin.value || isMentor.value,
        },
        {
            label: 'Assign to class',
            href: page.props.gradeContext?.selected?.id
                ? safeRoute(
                    'admin.classes.assign',
                    page.props.gradeContext.selected.id,
                    assignToClassPath(page.props.gradeContext.selected.id),
                )
                : route('admin.classes.index'),
            active: page.url.includes('/admin/classes/') && page.url.endsWith('/assign'),
            show: isAdmin.value,
        },
        {
            label: 'Practice sets',
            href: route('admin.practice-sets.index'),
            active: route().current('admin.practice-sets.*') && !route().current('admin.practice-sets.oversized'),
            show: isAdmin.value,
        },
        {
            label: 'Large sets',
            href: safeRoute('admin.practice-sets.oversized', undefined, '/admin/practice-sets/oversized'),
            active: route().current('admin.practice-sets.oversized'),
            show: isAdmin.value && hasRoute('admin.practice-sets.oversized'),
        },
        {
            label: 'Catch-up sets',
            href: route('admin.catch-up.index'),
            active: route().current('admin.catch-up.*'),
            show: isAdmin.value,
        },
        {
            label: 'Written sheets',
            href: route('admin.written-sheets.index'),
            active: route().current('admin.written-sheets.*'),
            show: isAdmin.value,
        },
        {
            label: 'Written review',
            href: route('admin.written-review.index'),
            active: route().current('admin.written-review.*'),
            show: isAdmin.value && route().has('admin.written-review.index'),
        },
        {
            label: 'Student work report',
            href: route('admin.student-work-report.index'),
            active: route().current('admin.student-work-report.*'),
            show: isAdmin.value && route().has('admin.student-work-report.index'),
        },
        {
            label: 'School study plans',
            href: route('admin.school-study-plan.index'),
            active: route().current('admin.school-study-plan.*'),
            show: (isAdmin.value || isMentor.value) && route().has('admin.school-study-plan.index'),
        },
    ],
}));

const contentGroup = computed(() => ({
    label: 'Question bank',
    active:
        route().current('admin.questions.*')
        || route().current('admin.question-audit.*')
        || route().current('admin.chapter-heads.*')
        || route().current('admin.concept-overview.*')
        || route().current('admin.syllabus.*')
        || route().current('admin.textbooks.*')
        || route().current('admin.concept-builder.*')
        || route().current('admin.mmm-cards.*')
        || route().current('admin.content-tasks.*')
        || route().current('admin.content-rate-cards.*')
        || route().current('admin.formula-bank.*')
        || route().current('admin.basics-drill.*')
        || route().current('admin.mensuration-match.*')
        || route().current('admin.algebra-cards.*'),
    items: [
        {
            label: 'Algebra card',
            href: safeRoute('admin.algebra-cards.index', undefined, '/admin/algebra-cards'),
            active: route().current('admin.algebra-cards.*'),
            show: isAdmin.value && route().has('admin.algebra-cards.index'),
        },
        {
            label: 'Content coverage',
            href: route('admin.questions.coverage'),
            active: route().current('admin.questions.coverage'),
            show: isAdmin.value || isMentor.value,
        },
        {
            label: 'Look up set code',
            href: route('admin.questions.set-code'),
            active: route().current('admin.questions.set-code'),
            show: isAdmin.value,
        },
        {
            label: 'Answer audit',
            href: route('admin.question-audit.index'),
            active: route().current('admin.question-audit.*'),
            show: isAdmin.value,
        },
        {
            label: 'Questions',
            href: route('admin.questions.index'),
            active: route().current('admin.questions.*')
                && !route().current('admin.questions.coverage')
                && !route().current('admin.questions.set-code'),
            show: true,
        },
        {
            label: 'Concept overview',
            href: route().has('admin.concept-overview.index')
                ? route('admin.concept-overview.index')
                : '/admin/concept-overview',
            active: route().current('admin.concept-overview.*')
                || route().current('concepts.play'),
            show: route().has('admin.concept-overview.index'),
        },
        {
            label: 'Chapter heads',
            href: route('admin.chapter-heads.index'),
            active: route().current('admin.chapter-heads.*'),
            show: isAdmin.value && route().has('admin.chapter-heads.index'),
        },
        {
            label: 'Syllabus',
            href: route('admin.syllabus.index'),
            active: route().current('admin.syllabus.*'),
            show: isAdmin.value,
        },
        {
            label: 'Textbook content',
            href: route('admin.textbooks.index'),
            active: route().current('admin.textbooks.*'),
            show: isAdmin.value,
        },
        {
            label: 'MentorMaths queue',
            href: route('admin.mentormaths-conversion.index'),
            active: route().current('admin.mentormaths-conversion.*'),
            show: isAdmin.value && route().has('admin.mentormaths-conversion.index'),
        },
        {
            label: 'Concept builder',
            href: route('admin.concept-builder.index'),
            active: route().current('admin.concept-builder.*'),
            show: isAdmin.value && route().has('admin.concept-builder.index'),
        },
        {
            label: 'MMM methods',
            href: route('admin.mmm-cards.index'),
            active: route().current('admin.mmm-cards.*'),
            show: isAdmin.value && route().has('admin.mmm-cards.index'),
        },
        {
            label: 'Content upload tasks',
            href: route('admin.content-tasks.index'),
            active: route().current('admin.content-tasks.*'),
            show: isAdmin.value && route().has('admin.content-tasks.index'),
        },
        {
            label: 'Content rate cards',
            href: route('admin.content-rate-cards.index'),
            active: route().current('admin.content-rate-cards.*'),
            show: isAdmin.value && route().has('admin.content-rate-cards.index'),
        },
        {
            label: 'Formula bank',
            href: route('admin.formula-bank.index'),
            active: route().current('admin.formula-bank.*'),
            show: isAdmin.value,
        },
        {
            label: 'Basics drill',
            href: safeRoute('admin.basics-drill.index', undefined, '/admin/basics-drill'),
            active: route().current('admin.basics-drill.*'),
            show: isAdmin.value,
        },
        {
            label: 'Mensuration match',
            href: safeRoute('admin.mensuration-match.index', undefined, '/admin/mensuration-match'),
            active: route().current('admin.mensuration-match.*'),
            show: isAdmin.value && route().has('admin.mensuration-match.index'),
        },
    ],
}));

const setupGroup = computed(() => ({
    label: 'Setup',
    active: route().current('admin.academic-years.*')
        || route().current('admin.notifications.*')
        || route().current('admin.coaching-classes.*')
        || route().current('admin.coaching-class-teachers.*'),
    items: [
        {
            label: 'Academic years',
            href: route('admin.academic-years.index'),
            active: route().current('admin.academic-years.*'),
            show: isAdmin.value,
        },
        {
            label: 'Coaching classes',
            href: route().has('admin.coaching-classes.index')
                ? route('admin.coaching-classes.index')
                : '/admin/coaching-classes',
            active: route().current('admin.coaching-classes.*') || route().current('admin.coaching-class-teachers.*'),
            show: isAdmin.value,
        },
        {
            label: 'Email & notifications',
            href: safeRoute('admin.notifications.index', undefined, '/admin/notifications'),
            active: route().current('admin.notifications.*'),
            show: isAdmin.value,
        },
    ],
}));

const accountsGroup = computed(() => ({
    label: 'Accounts',
    active: route().current('admin.finance.*'),
    items: [
        {
            label: 'Finance',
            href: route().has('admin.finance.index') ? route('admin.finance.index') : '/admin/finance',
            active: route().current('admin.finance.*'),
            show: isAdmin.value,
        },
    ],
}));

const resourcesGroup = computed(() => ({
    label: 'Resources',
    active: route().current('student.resources.*')
        || route().current('student.written-assignments.*')
        || route().current('student.mensuration-match.*')
        || route().current('student.algebra-cards.*')
        || route().current('student.series-practice.*'),
    items: [
        {
            label: 'Formulas',
            href: route().has('student.resources.formulas.index')
                ? route('student.resources.formulas.index')
                : '/student/resources/formulas',
            active: route().current('student.resources.formulas.*'),
            show: isStudent.value && route().has('student.resources.formulas.index'),
        },
        {
            label: 'Match / Algebra',
            href: route().has('student.series-practice.show')
                ? route('student.series-practice.show')
                : (route().has('student.mensuration-match.show')
                    ? route('student.mensuration-match.show')
                    : '/student/series-practice'),
            active: route().current('student.mensuration-match.*')
                || route().current('student.algebra-cards.*')
                || route().current('student.series-practice.*'),
            show: isStudent.value && (
                route().has('student.series-practice.show')
                || route().has('student.mensuration-match.show')
            ),
        },
        {
            label: 'Written tests',
            href: route().has('student.written-assignments.index')
                ? route('student.written-assignments.index')
                : '/student/written-assignments',
            active: route().current('student.written-assignments.*'),
            show: isStudent.value && route().has('student.written-assignments.index'),
        },
    ],
}));

const learnGroup = computed(() => ({
    label: 'Learn Methods',
    active: route().current('learn-methods.*') || route().current('student.mmm-cards.*'),
    items: [
        {
            label: 'All methods',
            href: route().has('learn-methods.index') ? route('learn-methods.index') : '/learn-methods',
            active: route().current('learn-methods.*') || route().current('student.mmm-cards.*'),
            show: route().has('learn-methods.index'),
        },
    ],
}));

const navGroups = computed(() =>
    [
        learnGroup.value,
        peopleGroup.value,
        teachingGroup.value,
        contentGroup.value,
        accountsGroup.value,
        setupGroup.value,
        resourcesGroup.value,
    ].filter((group) => group.items.some((item) => item.show !== false)),
);
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav class="border-b border-gray-100 bg-white">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-14 w-full min-w-0 items-center justify-between gap-3">
                        <Link :href="route('dashboard')" class="flex shrink-0 items-center">
                            <ApplicationLogo size-class="!h-10 !w-auto !max-w-[12rem] sm:!h-10 sm:!max-w-[12rem]" />
                        </Link>

                        <div class="ml-auto hidden min-w-0 shrink-0 items-center gap-3 sm:flex">
                            <AdminClassSelector v-if="isAdmin || isMentor || isContentUploader" />
                            <span class="hidden max-w-[8rem] truncate text-sm text-gray-500 xl:inline">
                                {{ $page.props.auth.user.name }}
                            </span>
                            <Link
                                :href="route('profile.edit')"
                                class="rounded-md px-2 py-1.5 text-sm text-gray-600 hover:bg-gray-100 hover:text-gray-900"
                            >
                                Profile
                            </Link>
                            <Link
                                :href="route('logout')"
                                method="post"
                                as="button"
                                class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                            >
                                Log out
                            </Link>
                        </div>

                        <div class="flex shrink-0 items-center gap-2 sm:hidden">
                            <AdminClassSelector v-if="isAdmin || isMentor || isContentUploader" />
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-500 hover:bg-gray-100"
                                aria-label="Open menu"
                                @click="showingNavigationDropdown = !showingNavigationDropdown"
                            >
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path
                                        v-if="!showingNavigationDropdown"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        v-else
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="hidden flex-wrap items-center gap-x-1 overflow-visible border-t border-gray-100 sm:flex">
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    Dashboard
                                </NavLink>

                                <NavLink
                                    v-if="isStudent && !isAdmin && route().has('student.school-study-plan.show')"
                                    :href="route('student.school-study-plan.show')"
                                    :active="route().current('student.school-study-plan.*')"
                                >
                                    My School Study Plan
                                </NavLink>

                                <NavLink
                                    v-if="isStudent && !isAdmin && route().has('student.exams.index')"
                                    :href="route('student.exams.index')"
                                    :active="route().current('student.exams.*')"
                                >
                                    My Exams
                                </NavLink>

                                <NavLink
                                    v-if="isContentUploader && route().has('content.tasks.index')"
                                    :href="route('content.tasks.index')"
                                    :active="route().current('content.tasks.*')"
                                >
                                    My content tasks
                                </NavLink>

                                <NavLink
                                    v-if="isContentUploader && route().has('content.chapters.index')"
                                    :href="route('content.chapters.index')"
                                    :active="route().current('content.chapters.*')"
                                >
                                    My chapters
                                </NavLink>

                                <NavLink
                                    v-if="isContentUploader && route().has('content.concept-builder.index')"
                                    :href="route('content.concept-builder.index')"
                                    :active="route().current('content.concept-builder.*')"
                                >
                                    Concept builder
                                </NavLink>

                                <NavLink
                                    v-if="isMentor && !isAdmin && route().has('admin.school-study-plan.index')"
                                    :href="route('admin.school-study-plan.index')"
                                    :active="route().current('admin.school-study-plan.*')"
                                >
                                    School study plans
                                </NavLink>

                                <NavDropdown
                                    v-for="group in navGroups"
                                    :key="group.label"
                                    :label="group.label"
                                    :active="group.active"
                                    :items="group.items"
                                />
                    </div>
                </div>

                <!-- Vertical menu (mobile) -->
                <div
                    class="border-t border-gray-100 sm:hidden"
                    :class="showingNavigationDropdown ? 'block' : 'hidden'"
                >
                    <div class="mx-auto max-w-7xl px-2 pb-4 pt-2">
                        <ResponsiveNavLink
                            :href="route('dashboard')"
                            :active="route().current('dashboard')"
                        >
                            Dashboard
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="isStudent && !isAdmin && route().has('student.school-study-plan.show')"
                            :href="route('student.school-study-plan.show')"
                            :active="route().current('student.school-study-plan.*')"
                        >
                            My School Study Plan
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="isStudent && !isAdmin && route().has('student.exams.index')"
                            :href="route('student.exams.index')"
                            :active="route().current('student.exams.*')"
                        >
                            My Exams
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="isContentUploader && route().has('content.tasks.index')"
                            :href="route('content.tasks.index')"
                            :active="route().current('content.tasks.*')"
                        >
                            My content tasks
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="isContentUploader && route().has('content.chapters.index')"
                            :href="route('content.chapters.index')"
                            :active="route().current('content.chapters.*')"
                        >
                            My chapters
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="isContentUploader && route().has('content.concept-builder.index')"
                            :href="route('content.concept-builder.index')"
                            :active="route().current('content.concept-builder.*')"
                        >
                            Concept builder
                        </ResponsiveNavLink>

                        <ResponsiveNavLink
                            v-if="isMentor && !isAdmin && route().has('admin.school-study-plan.index')"
                            :href="route('admin.school-study-plan.index')"
                            :active="route().current('admin.school-study-plan.*')"
                        >
                            School study plans
                        </ResponsiveNavLink>

                        <ResponsiveNavGroup
                            v-for="group in navGroups"
                            :key="`mobile-${group.label}`"
                            :label="group.label"
                            :items="group.items"
                        />

                        <div class="mt-3 border-t border-gray-200 pt-3">
                            <p class="px-4 text-xs font-semibold uppercase tracking-wide text-gray-400">Account</p>
                            <p class="px-4 pt-1 text-sm font-medium text-gray-800">{{ $page.props.auth.user.name }}</p>
                            <p class="px-4 text-sm text-gray-500">{{ $page.props.auth.user.email }}</p>
                            <ResponsiveNavLink :href="route('profile.edit')" class="mt-2">
                                Profile
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Log out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <AssignmentWhatsAppPrompt />

            <header v-if="$slots.header" class="bg-white shadow">
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
