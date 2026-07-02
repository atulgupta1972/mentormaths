<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    topic: Object,
    questions: Object,
    filters: Object,
});

const applySearch = (event) => {
    const form = event.target;
    router.get(route('admin.questions.topics.show', props.topic.id), {
        search: form.search.value,
    }, { preserveState: true });
};
</script>

<template>
    <Head :title="topic.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link
                        :href="route('admin.questions.chapters.show', topic.chapter_id)"
                        class="text-sm text-indigo-600"
                    >
                        ← Ch {{ topic.chapter_number }} {{ topic.chapter_name }}
                    </Link>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ topic.board_code }} {{ topic.grade_name }}
                    </p>
                    <h2 class="text-xl font-semibold text-gray-800">{{ topic.name }}</h2>
                </div>
                <Link
                    :href="route('admin.questions.create', { syllabus_topic_id: topic.id })"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    Add MCQs
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <form class="flex gap-3 rounded-lg bg-white p-4 shadow-sm" @submit.prevent="applySearch">
                    <input
                        name="search"
                        type="search"
                        :value="filters.search"
                        placeholder="Search in this topic..."
                        class="min-w-[200px] flex-1 rounded-md border-gray-300 text-sm"
                    />
                    <button type="submit" class="rounded-md bg-gray-800 px-4 py-2 text-sm text-white">Search</button>
                </form>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Question</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Difficulty</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Source</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="q in questions.data" :key="q.id">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-gray-900">{{ q.question_text }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ q.options?.length || 0 }} options</p>
                                </td>
                                <td class="px-4 py-3">{{ q.difficulty || '—' }}</td>
                                <td class="px-4 py-3 capitalize">{{ q.source }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="route('admin.questions.edit', q.id)" class="text-indigo-600 hover:text-indigo-800">
                                        Edit
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="questions.data.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                    No questions for this topic yet.
                                    <Link
                                        :href="route('admin.questions.create', { syllabus_topic_id: topic.id })"
                                        class="text-indigo-600"
                                    >
                                        Add MCQs
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
