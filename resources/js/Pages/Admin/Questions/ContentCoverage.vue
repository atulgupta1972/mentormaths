<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AdminContentCoveragePanel from '@/Components/AdminContentCoveragePanel.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    activeYear: { type: Object, default: null },
    coverage: {
        type: Object,
        default: () => ({ book_columns: [], chapters: [], context: {} }),
    },
    coverageFilters: {
        type: Object,
        default: () => ({
            grade_levels: [],
            boards_by_grade: {},
            selected_grade_level_id: null,
            selected_board_id: null,
        }),
    },
});
</script>

<template>
    <Head title="Content coverage" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Content coverage</h2>
                <p class="text-sm text-gray-500">
                    Class × chapter matrix — practice, tests, written, fill-in-blank, formula, books
                    <span v-if="activeYear"> · {{ activeYear.name }}</span>
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <BrowseModeNotice />

                <p class="text-sm text-gray-600">
                    Same view students see on their dashboard, plus draft sets.
                    <Link :href="route('admin.questions.index')" class="font-medium text-indigo-600 hover:underline">
                        Browse question bank
                    </Link>
                    ·
                    <Link :href="route('admin.formula-bank.index')" class="font-medium text-indigo-600 hover:underline">
                        Formula bank matrix
                    </Link>
                </p>

                <div
                    v-if="!activeYear"
                    class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900"
                >
                    No active academic year. Set one from
                    <Link :href="route('admin.academic-years.index')" class="font-medium text-indigo-600 hover:underline">
                        Academic years
                    </Link>.
                </div>

                <AdminContentCoveragePanel
                    v-else
                    :coverage="coverage"
                    :coverage-filters="coverageFilters"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
