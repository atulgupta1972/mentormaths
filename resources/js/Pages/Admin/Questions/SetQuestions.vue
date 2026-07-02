<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    practiceSet: Object,
    topic: Object,
    questions: Array,
    isChapterTest: { type: Boolean, default: false },
});
</script>

<template>
    <Head :title="practiceSet.set_code || 'Practice set'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link
                        v-if="topic"
                        :href="route('admin.questions.chapters.show', topic.chapter_id)"
                        class="text-sm text-indigo-600"
                    >
                        ← Ch {{ topic.chapter_number }} {{ topic.chapter_name }}
                    </Link>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ topic?.board_code }} {{ topic?.grade_name }}
                        <span v-if="isChapterTest"> · Chapter test (mixed)</span>
                        <span v-else-if="topic"> · {{ topic.name }}</span>
                    </p>
                    <div class="mt-1 flex items-center gap-3">
                        <span class="font-mono text-2xl font-bold tracking-wide text-indigo-600">
                            {{ practiceSet.set_code }}
                        </span>
                        <span class="text-sm text-gray-600">{{ practiceSet.tier_label }} · {{ practiceSet.questions_count }} sums</span>
                    </div>
                </div>
                <Link
                    v-if="isChapterTest && topic"
                    :href="route('admin.practice-sets.chapters.show', topic.chapter_id)"
                    class="rounded-md border border-sky-300 bg-sky-50 px-3 py-2 text-sm text-sky-800 hover:bg-sky-100"
                >
                    Chapter tests & assign
                </Link>
                <Link
                    v-else-if="topic?.id"
                    :href="route('admin.practice-sets.topics.show', topic.id)"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                >
                    Sets & assign
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <p class="text-sm text-gray-600">{{ practiceSet.tier_tagline }}</p>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">#</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Question</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Difficulty</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="(q, index) in questions" :key="q.id">
                                <td class="px-4 py-3 text-gray-500">{{ index + 1 }}</td>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-gray-900">{{ q.question_text }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ q.options_count }} options</p>
                                </td>
                                <td class="px-4 py-3">{{ q.difficulty || '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="route('admin.questions.edit', q.id)" class="text-indigo-600 hover:text-indigo-800">
                                        Edit
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="questions.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500">No questions in this set.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
