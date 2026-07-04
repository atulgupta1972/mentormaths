<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { questionHubChapterUrl, questionHubClassUrl } from '@/utils/questionHub';

const props = defineProps({
    topic: Object,
    questions: Object,
    filters: Object,
    hintStats: Object,
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.isAdmin ?? false);
const generating = ref(false);
const overwrite = ref(false);

const classListUrl = computed(() => questionHubClassUrl(props.topic?.grade_level_id, props.topic?.board_id));
const chapterSetsUrl = computed(() => questionHubChapterUrl(props.topic?.chapter_id));

const applySearch = (event) => {
    const form = event.target;
    router.get(route('admin.questions.topics.show', props.topic.id), {
        search: form.search.value,
    }, { preserveState: true });
};

const generateHints = () => {
    if (generating.value) {
        return;
    }

    const missing = props.hintStats?.missing_hint ?? 0;
    const message = overwrite.value
        ? 'Replace method hints for all questions in this topic using auto-detected theory rules?'
        : missing > 0
            ? `Generate theory-only method hints for ${missing} question${missing === 1 ? '' : 's'} missing hints?`
            : 'All questions already have hints. Generate anyway for any that match sign-rule patterns?';

    if (!window.confirm(message)) {
        return;
    }

    generating.value = true;
    router.post(route('admin.questions.topics.generate-method-hints', props.topic.id), {
        overwrite: overwrite.value,
        sanitize_explanations: true,
    }, {
        preserveScroll: true,
        onFinish: () => {
            generating.value = false;
        },
    });
};
</script>

<template>
    <Head :title="topic.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link
                        :href="chapterSetsUrl"
                        class="text-sm text-indigo-600"
                    >
                        ← Ch {{ topic.chapter_number }} {{ topic.chapter_name }}
                    </Link>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ topic.board_code }} {{ topic.grade_name }}
                        ·
                        <Link :href="classListUrl" class="text-indigo-600 hover:underline">{{ topic.grade_name }} chapters</Link>
                    </p>
                    <h2 class="text-xl font-semibold text-gray-800">{{ topic.name }}</h2>
                    <p v-if="hintStats?.total > 0" class="mt-1 text-xs text-gray-500">
                        Method hints: {{ hintStats.with_hint }}/{{ hintStats.total }}
                        <span v-if="hintStats.missing_hint > 0" class="text-amber-700">
                            · {{ hintStats.missing_hint }} missing
                        </span>
                    </p>
                </div>
                <div v-if="isAdmin" class="flex flex-wrap items-center gap-2">
                    <label v-if="hintStats?.total > 0" class="flex items-center gap-2 text-xs text-gray-600">
                        <input v-model="overwrite" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                        Replace existing
                    </label>
                    <button
                        v-if="hintStats?.total > 0"
                        type="button"
                        class="rounded-md border border-sky-300 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-900 hover:bg-sky-100 disabled:opacity-50"
                        :disabled="generating"
                        @click="generateHints"
                    >
                        {{ generating ? 'Generating…' : 'Generate method hints' }}
                    </button>
                    <Link
                        :href="route('admin.questions.create', { syllabus_topic_id: topic.id })"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        Add MCQs
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <BrowseModeNotice />

                <div
                    v-if="page.props.flash?.success"
                    class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
                >
                    {{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash?.warning"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    {{ page.props.flash.warning }}
                </div>

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
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Hint</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Difficulty</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Source</th>
                                <th v-if="isAdmin" class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="q in questions.data" :key="q.id">
                                <td class="px-4 py-3">
                                    <QuestionBody :question-text="q.question_text" :diagram-url="q.diagram_url" />
                                    <p class="mt-1 text-xs text-gray-500">{{ q.options?.length || 0 }} options</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="q.method_hint"
                                        class="inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800"
                                        :title="q.method_hint"
                                    >
                                        Yes
                                    </span>
                                    <span v-else class="text-xs text-amber-700">Missing</span>
                                </td>
                                <td class="px-4 py-3">{{ q.difficulty || '—' }}</td>
                                <td class="px-4 py-3 capitalize">{{ q.source }}</td>
                                <td v-if="isAdmin" class="px-4 py-3 text-right">
                                    <Link :href="route('admin.questions.edit', q.id)" class="text-indigo-600 hover:text-indigo-800">
                                        Edit
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="questions.data.length === 0">
                                <td :colspan="isAdmin ? 5 : 4" class="px-4 py-8 text-center text-gray-500">
                                    No questions for this topic yet.
                                    <Link
                                        v-if="isAdmin"
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
