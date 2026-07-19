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
import { assignToClassPath, safeRoute } from '@/utils/routes';

const showingNavigationDropdown = ref(false);
const page = usePage();
const isAdmin = computed(() => page.props.auth?.isAdmin ?? false);

const peopleGroup = computed(() => ({
    label: 'People',
    active:
        route().current('admin.users.*')
        || route().current('admin.groups.*')
        || route().current('admin.registration-requests.*')
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
        || route().current('admin.practice-sets.*')
        || route().current('admin.catch-up.*')
        || route().current('admin.written-sheets.*'),
    items: [
        {
            label: 'Classes',
            href: route('admin.classes.index'),
            active: route().current('admin.classes.index') || route().current('admin.classes.show'),
            show: true,
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
            active: route().current('admin.practice-sets.*'),
            show: isAdmin.value,
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
    ],
}));

const contentGroup = computed(() => ({
    label: 'Question bank',
    active:
        route().current('admin.questions.*')
        || route().current('admin.question-audit.*')
        || route().current('admin.chapter-heads.*')
        || route().current('admin.syllabus.*'),
    items: [
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
            active: route().current('admin.questions.*'),
            show: true,
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
    ],
}));

const setupGroup = computed(() => ({
    label: 'Setup',
    active: route().current('admin.academic-years.*'),
    items: [
        {
            label: 'Academic years',
            href: route('admin.academic-years.index'),
            active: route().current('admin.academic-years.*'),
            show: isAdmin.value,
        },
    ],
}));

const navGroups = computed(() =>
    [peopleGroup.value, teachingGroup.value, contentGroup.value, setupGroup.value].filter(
        (group) => group.items.some((item) => item.show !== false),
    ),
);
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav class="border-b border-gray-100 bg-white">
                <!-- Horizontal bar (desktop) -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 items-center justify-between gap-4">
                        <div class="flex min-w-0 flex-1 items-center gap-6 lg:gap-8">
                            <Link :href="route('dashboard')" class="flex shrink-0 items-center gap-2.5">
                                <ApplicationLogo />
                                <span class="hidden font-semibold text-slate-800 sm:inline">Mentor Maths</span>
                            </Link>

                            <div class="hidden items-center gap-6 sm:flex lg:gap-8">
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    Dashboard
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

                        <div class="hidden shrink-0 items-center gap-2 sm:flex sm:gap-3">
                            <AdminClassSelector v-if="isAdmin" />
                            <span class="hidden max-w-[7rem] truncate text-sm text-gray-500 lg:inline">
                                {{ $page.props.auth.user.name }}
                            </span>
                            <Link
                                :href="route('profile.edit')"
                                class="hidden rounded-md px-2 py-1.5 text-sm text-gray-600 hover:bg-gray-100 hover:text-gray-900 md:inline"
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

                        <!-- Mobile toggle -->
                        <div class="flex shrink-0 items-center gap-2 sm:hidden">
                            <AdminClassSelector v-if="isAdmin" />
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
