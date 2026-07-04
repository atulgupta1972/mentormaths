<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { questionHubChapterUrl, questionHubClassUrl } from '@/utils/questionHub';

const props = defineProps({
    practiceSet: Object,
    topic: Object,
    questions: Array,
    isChapterTest: { type: Boolean, default: false },
    hintStats: Object,
    topicHintStats: Object,
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.isAdmin ?? false);
const generating = ref(false);
const overwrite = ref(false);

const classListUrl = computed(() => questionHubClassUrl(props.topic?.grade_level_id, props.topic?.board_id));
const chapterSetsUrl = computed(() => questionHubChapterUrl(props.topic?.chapter_id));

const generateHints = () => {
    if (generating.value || !props.topic?.id) {
        return;
    }

    const topicMissing = props.topicHintStats?.missing_hint ?? 0;
    const setMissing = props.hintStats?.missing_hint ?? 0;
    const message = overwrite.value
        ? `Replace method hints for all ${props.topicHintStats?.total ?? 0} MCQs in “${props.topic.name}”?`
        : topicMissing > 0
            ? `Generate theory-only hints for ${topicMissing} MCQ${topicMissing === 1 ? '' : 's'} in this topic (${setMissing} missing in this set)?`
            : 'All topic MCQs already have hints. Regenerate using sign-rule patterns anyway?';

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
    <Head :title="practiceSet.set_code || 'Practice set'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link
                        v-if="topic"
                        :href="chapterSetsUrl"
                        class="text-sm text-indigo-600"
                    >
                        ← Ch {{ topic.chapter_number }} {{ topic.chapter_name }}
                    </Link>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ topic?.board_code }} {{ topic?.grade_name }}
                        <span v-if="isChapterTest"> · Chapter test (mixed)</span>
                        <span v-else-if="topic"> · {{ topic.name }}</span>
                        <span v-if="topic?.grade_level_id && topic?.board_id">
                            ·
                            <Link :href="classListUrl" class="text-indigo-600 hover:underline">All chapters</Link>
                        </span>
                    </p>
                    <div class="mt-1 flex items-center gap-3">
                        <span class="font-mono text-2xl font-bold tracking-wide text-indigo-600">
                            {{ practiceSet.set_code }}
                        </span>
                        <span class="text-sm text-gray-600">{{ practiceSet.tier_label }} · {{ practiceSet.questions_count }} sums</span>
                    </div>
                    <p v-if="isAdmin && topic?.id && hintStats?.total > 0" class="mt-1 text-xs text-gray-500">
                        Method hints in this set: {{ hintStats.with_hint }}/{{ hintStats.total }}
                        <span v-if="hintStats.missing_hint > 0" class="text-amber-700">
                            · {{ hintStats.missing_hint }} missing
                        </span>
                        <span v-if="topicHintStats && topicHintStats.total > hintStats.total" class="text-gray-400">
                            · {{ topicHintStats.total }} MCQs in topic
                        </span>
                    </p>
                </div>
                <div v-if="isAdmin" class="flex flex-wrap items-center gap-2">
                    <template v-if="topic?.id && !isChapterTest">
                        <label v-if="hintStats?.total > 0" class="flex items-center gap-2 text-xs text-gray-600">
                            <input v-model="overwrite" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            Replace existing
                        </label>
                        <button
                            v-if="topicHintStats?.total > 0"
                            type="button"
                            class="rounded-md border border-sky-300 bg-sky-50 px-3 py-2 text-sm font-medium text-sky-900 hover:bg-sky-100 disabled:opacity-50"
                            :disabled="generating"
                            @click="generateHints"
                        >
                            {{ generating ? 'Generating…' : 'Generate method hints' }}
                        </button>
                        <Link
                            :href="route('admin.questions.topics.show', topic.id)"
                            class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                        >
                            All topic MCQs
                        </Link>
                    </template>
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

                <p class="text-sm text-gray-600">{{ practiceSet.tier_tagline }}</p>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">#</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Question</th>
                                <th v-if="isAdmin" class="px-4 py-3 text-left text-xs uppercase text-gray-500">Hint</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Difficulty</th>
                                <th v-if="isAdmin" class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="(q, index) in questions" :key="q.id">
                                <td class="px-4 py-3 text-gray-500">{{ index + 1 }}</td>
                                <td class="px-4 py-3">
                                    <QuestionBody :question-text="q.question_text" :diagram-url="q.diagram_url" :compact="true" />
                                    <p class="mt-1 text-xs text-gray-500">{{ q.options_count }} options</p>
                                </td>
                                <td v-if="isAdmin" class="px-4 py-3">
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
                                <td v-if="isAdmin" class="px-4 py-3 text-right">
                                    <Link :href="route('admin.questions.edit', q.id)" class="text-indigo-600 hover:text-indigo-800">
                                        Edit
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="questions.length === 0">
                                <td :colspan="isAdmin ? 5 : 3" class="px-4 py-8 text-center text-gray-500">No questions in this set.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
