<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    chapterHead: Object,
    topics: Array,
    topicsByClass: Array,
    activeYear: Object,
});
</script>

<template>
    <Head :title="chapterHead.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="route('admin.chapter-heads.index')" class="text-sm text-indigo-600">← Chapter heads</Link>
                    <h2 class="mt-1 text-xl font-semibold text-gray-800">{{ chapterHead.name }}</h2>
                    <p class="text-sm text-gray-500">
                        All topics tagged “{{ chapterHead.name }}” across classes
                        <span v-if="activeYear"> · {{ activeYear.name }}</span>
                    </p>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-8 sm:px-6 lg:px-8">
                <p v-if="topics.length === 0" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
                    No topics linked yet. In the syllabus editor, set the <strong>Chapter head</strong> column when adding chapters.
                </p>

                <section v-for="group in topicsByClass" :key="group.class_name" class="space-y-3">
                    <h3 class="text-lg font-semibold text-gray-900">{{ group.class_name }}</h3>
                    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Ch</th>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapter</th>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Topic</th>
                                    <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Difficulty</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="topic in group.topics" :key="topic.id">
                                    <td class="px-4 py-3 text-gray-500">{{ topic.chapter_number || '—' }}</td>
                                    <td class="px-4 py-3">{{ topic.chapter_name }}</td>
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ topic.name }}</td>
                                    <td class="px-4 py-3">{{ topic.difficulty || '—' }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <Link
                                            :href="route('admin.practice-sets.topics.show', topic.id)"
                                            class="text-indigo-600 hover:text-indigo-800"
                                        >
                                            Sets &amp; assign
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
