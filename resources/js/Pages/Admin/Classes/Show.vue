<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    gradeLevel: Object,
    activeYear: Object,
    syllabusVersion: Object,
    view: { type: String, default: 'topic' },
    selectedChapterId: [Number, String, null],
    selectedTopicId: [Number, String, null],
    chapters: Array,
    chapterTopics: Array,
    chapterRows: Array,
    topics: Array,
    stats: Object,
});

const viewMode = ref(props.view || 'topic');
const chapterFilter = ref(props.selectedChapterId || '');
const topicFilter = ref(props.selectedTopicId || '');

const isChapterView = computed(() => viewMode.value === 'chapter');

const reload = () => {
    const params = {
        view: viewMode.value,
        syllabus_chapter_id: chapterFilter.value || undefined,
    };

    if (!isChapterView.value) {
        params.syllabus_topic_id = topicFilter.value || undefined;
    }

    router.get(route('admin.classes.show', props.gradeLevel.id), params, { preserveState: false });
};

watch(viewMode, () => {
    if (isChapterView.value) {
        topicFilter.value = '';
    }
    reload();
});

watch(chapterFilter, (id, oldId) => {
    if (id === oldId) {
        return;
    }
    topicFilter.value = '';
    reload();
});

watch(topicFilter, (id, oldId) => {
    if (isChapterView.value || id === oldId) {
        return;
    }
    reload();
});
</script>

<template>
    <Head :title="gradeLevel.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link :href="route('admin.classes.index')" class="text-sm text-indigo-600">← All classes</Link>
                    <h2 class="mt-1 text-xl font-semibold text-gray-800">{{ gradeLevel.name }}</h2>
                    <p v-if="activeYear" class="text-sm text-gray-500">{{ activeYear.name }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-if="syllabusVersion"
                        :href="route('admin.syllabus.show', syllabusVersion.id)"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        Syllabus
                    </Link>
                    <Link
                        :href="route('admin.questions.classes.show', gradeLevel.id)"
                        class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        Questions
                    </Link>
                    <Link
                        :href="route('admin.practice-sets.index')"
                        class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        Practice sets
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.students_count }}</p>
                        <p class="text-xs text-gray-500">Students</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.topics_count }}</p>
                        <p class="text-xs text-gray-500">{{ isChapterView ? 'Topics (in view)' : 'Topics' }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.questions_count }}</p>
                        <p class="text-xs text-gray-500">Questions</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.practice_sets_count }}</p>
                        <p class="text-xs text-gray-500">Sets / tests</p>
                    </div>
                </div>

                <div v-if="!syllabusVersion" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    No syllabus imported for {{ gradeLevel.name }} yet.
                    <Link :href="route('admin.syllabus.index')" class="font-medium text-indigo-600">Import syllabus</Link>
                </div>

                <div v-else class="rounded-lg bg-white p-4 shadow-sm space-y-4">
                    <div>
                        <InputLabel value="View" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <button
                                type="button"
                                class="rounded-lg border p-3 text-left text-sm transition"
                                :class="!isChapterView
                                    ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
                                    : 'border-gray-200 hover:border-gray-300'"
                                @click="viewMode = 'topic'"
                            >
                                <p class="font-medium text-gray-900">Topic wise</p>
                                <p class="mt-0.5 text-xs text-gray-500">List topics — filter by chapter and topic</p>
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border p-3 text-left text-sm transition"
                                :class="isChapterView
                                    ? 'border-sky-500 bg-sky-50 ring-1 ring-sky-500'
                                    : 'border-gray-200 hover:border-gray-300'"
                                @click="viewMode = 'chapter'"
                            >
                                <p class="font-medium text-gray-900">Chapter wise</p>
                                <p class="mt-0.5 text-xs text-gray-500">Summary per chapter — optional chapter filter</p>
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Chapter" />
                            <select v-model="chapterFilter" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option value="">All chapters</option>
                                <option v-for="ch in chapters" :key="ch.id" :value="ch.id">{{ ch.label }}</option>
                            </select>
                        </div>
                        <div v-if="!isChapterView">
                            <InputLabel value="Topic (optional)" />
                            <select
                                v-model="topicFilter"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                :disabled="!chapterFilter"
                            >
                                <option value="">All topics{{ chapterFilter ? ' in chapter' : '' }}</option>
                                <option v-for="t in chapterTopics" :key="t.id" :value="t.id">
                                    {{ t.name }} ({{ t.questions_count }} Q)
                                </option>
                            </select>
                            <p v-if="!chapterFilter" class="mt-1 text-xs text-gray-500">Select a chapter first to filter by topic.</p>
                        </div>
                    </div>
                </div>

                <!-- Chapter wise table -->
                <div v-if="isChapterView && syllabusVersion" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapter</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Topics</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Questions</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Topic sets</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapter tests</th>
                                <th class="px-4 py-3 text-right text-xs uppercase text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="chapter in chapterRows" :key="chapter.id">
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    Ch {{ chapter.chapter_number }} — {{ chapter.name }}
                                </td>
                                <td class="px-4 py-3">{{ chapter.topics_count }}</td>
                                <td class="px-4 py-3">{{ chapter.questions_count }}</td>
                                <td class="px-4 py-3">{{ chapter.topic_sets_count }}</td>
                                <td class="px-4 py-3">{{ chapter.chapter_tests_count }}</td>
                                <td class="px-4 py-3 text-right space-x-3">
                                    <Link
                                        :href="route('admin.questions.chapters.show', chapter.id)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        Question bank
                                    </Link>
                                    <Link
                                        :href="route('admin.practice-sets.chapters.show', chapter.id)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        Chapter tests
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="chapterRows.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">No chapters match this filter.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Topic wise table -->
                <div v-if="!isChapterView && syllabusVersion" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapter</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Topic</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Questions</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Sets</th>
                                <th class="px-4 py-3 text-right text-xs uppercase text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="topic in topics" :key="topic.id">
                                <td class="px-4 py-3 text-gray-600">
                                    {{ topic.chapter_number }} {{ topic.chapter_name }}
                                </td>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ topic.name }}</td>
                                <td class="px-4 py-3">{{ topic.questions_count }}</td>
                                <td class="px-4 py-3">{{ topic.practice_sets_count }}</td>
                                <td class="px-4 py-3 text-right space-x-3">
                                    <Link
                                        :href="route('admin.questions.create', {
                                            syllabus_chapter_id: topic.chapter_id,
                                            syllabus_topic_id: topic.id,
                                        })"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        Add MCQs
                                    </Link>
                                    <Link
                                        :href="route('admin.practice-sets.topics.show', topic.id)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        Sets & assign
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="topics.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                    No topics match this filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
