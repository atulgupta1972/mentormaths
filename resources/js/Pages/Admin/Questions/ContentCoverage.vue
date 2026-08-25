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
    browseOnly: { type: Boolean, default: false },
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

                <div
                    v-if="browseOnly"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                >
                    Browse-only for mentors: expand chapters to see what is in the bank.
                    You cannot take or start tests from this view.
                </div>

                <p class="text-sm text-gray-600">
                    <template v-if="!browseOnly">
                        Same view students see on their dashboard, plus draft sets.
                        <Link :href="route('admin.questions.index')" class="font-medium text-indigo-600 hover:underline">
                            Browse question bank
                        </Link>
                        ·
                        <Link :href="route('admin.formula-bank.index')" class="font-medium text-indigo-600 hover:underline">
                            Formula bank matrix
                        </Link>
                    </template>
                    <template v-else>
                        Syllabus content available on the platform for each class and board.
                        Counts include practice, tests, written, fill-in-blank, and formula sets.
                    </template>
                </p>

                <div
                    v-if="!activeYear"
                    class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900"
                >
                    No active academic year.
                </div>

                <AdminContentCoveragePanel
                    v-else
                    :coverage="coverage"
                    :coverage-filters="coverageFilters"
                    :browse-only="browseOnly"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
