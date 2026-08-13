<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    grades: { type: Array, default: () => [] },
    selected_grade_id: { type: [Number, null], default: null },
    chapters: { type: Array, default: () => [] },
});

const selectGrade = (gradeId) => {
    router.get(route('content.chapters.index'), { grade_level_id: gradeId }, { preserveState: true });
};
</script>

<template>
    <Head title="My chapters" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">My chapters</h2>
                    <p class="text-sm text-gray-500">Pick a class, open a chapter, see every question, then add more if something is missing.</p>
                </div>
                <Link :href="route('content.tasks.index')" class="text-sm text-indigo-600 hover:underline">← My content tasks</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6">
                <div v-if="grades.length" class="flex flex-wrap gap-2">
                    <button
                        v-for="grade in grades"
                        :key="grade.id"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-sm font-medium"
                        :class="grade.id === selected_grade_id
                            ? 'bg-indigo-600 text-white'
                            : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50'"
                        @click="selectGrade(grade.id)"
                    >
                        {{ grade.name }}
                    </button>
                </div>

                <div v-if="chapters.length" class="space-y-2">
                    <div
                        v-for="chapter in chapters"
                        :key="chapter.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200"
                    >
                        <div>
                            <p class="font-semibold text-gray-900">
                                Ch {{ chapter.chapter_number }} — {{ chapter.title }}
                            </p>
                            <p class="text-sm text-gray-500">
                                {{ chapter.textbook_name }} · {{ chapter.question_count }} question{{ chapter.question_count === 1 ? '' : 's' }} · {{ chapter.task_status_label }}
                            </p>
                        </div>
                        <Link
                            :href="route('content.chapters.show', chapter.id)"
                            class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
                        >
                            View questions →
                        </Link>
                    </div>
                </div>

                <p v-else class="rounded-lg bg-white p-8 text-center text-gray-500 shadow-sm ring-1 ring-gray-200">
                    No chapters assigned for this class yet.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
