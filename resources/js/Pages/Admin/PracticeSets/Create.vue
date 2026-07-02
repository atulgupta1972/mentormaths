<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    chapters: Array,
    chapterTopics: Array,
    scope: { type: String, default: 'topic' },
    selectedChapterId: [Number, String, null],
    selectedTopicId: [Number, String, null],
    selectedSourceSetIds: Array,
    sourceSets: Array,
    topicBank: Object,
    nextSetNumber: [Number, null],
    tiers: Array,
    questions: Array,
    selectedGrade: Object,
});

const page = usePage();
const scopeMode = ref(props.scope || 'topic');
const chapterFilter = ref(props.selectedChapterId || '');
const topicFilter = ref(props.selectedTopicId || '');
const sourceSetIds = ref([...(props.selectedSourceSetIds || [])]);

const form = useForm({
    scope: props.scope || 'topic',
    syllabus_chapter_id: props.selectedChapterId || '',
    syllabus_topic_id: props.selectedTopicId || '',
    tier: 'starter',
    notes: '',
    status: 'draft',
    question_ids: [],
});

const packageForm = useForm({ tier: 'starter' });

const isChapterMode = computed(() => scopeMode.value === 'chapter');

const groupedQuestions = computed(() => {
    if (!isChapterMode.value || !props.questions?.length) {
        return null;
    }

    const map = {};
    for (const q of props.questions) {
        const key = q.set_code || 'Other';
        if (!map[key]) {
            map[key] = [];
        }
        map[key].push(q);
    }

    return map;
});

const previewTitle = computed(() => {
    if (!props.nextSetNumber) {
        return '';
    }

    if (isChapterMode.value) {
        return `Chapter test ${props.nextSetNumber} (${form.question_ids.length} sums)`;
    }

    const tier = props.tiers.find((t) => t.value === packageForm.tier);
    return `Set ${props.nextSetNumber} — ${tier?.label ?? ''}`;
});

const canSubmitChapterTest = computed(() => {
    return chapterFilter.value
        && sourceSetIds.value.length > 0
        && form.question_ids.length > 0;
});

const canPackageTopic = computed(() => {
    return topicFilter.value
        && props.topicBank?.questions_count > 0
        && !props.topicBank?.existing_sets?.length;
});

const addMcqsUrl = computed(() => {
    const params = {};
    if (chapterFilter.value) {
        params.syllabus_chapter_id = chapterFilter.value;
    }
    if (topicFilter.value) {
        params.syllabus_topic_id = topicFilter.value;
    }
    return route('admin.questions.create', params);
});

const reloadCreate = () => {
    const params = {
        scope: scopeMode.value,
        syllabus_chapter_id: chapterFilter.value || undefined,
    };

    if (!isChapterMode.value) {
        params.syllabus_topic_id = topicFilter.value || undefined;
    } else if (sourceSetIds.value.length) {
        params.source_set_ids = sourceSetIds.value;
    }

    router.get(route('admin.practice-sets.create', params), {}, { preserveState: false });
};

watch(scopeMode, () => {
    form.question_ids = [];
    sourceSetIds.value = [];
    if (isChapterMode.value) {
        topicFilter.value = '';
    }
    reloadCreate();
});

watch(chapterFilter, (id, oldId) => {
    if (id === oldId) {
        return;
    }
    form.question_ids = [];
    sourceSetIds.value = [];
    topicFilter.value = '';
    reloadCreate();
});

watch(topicFilter, (id, oldId) => {
    if (isChapterMode.value || id === oldId) {
        return;
    }
    reloadCreate();
});

watch(sourceSetIds, (ids, oldIds) => {
    if (JSON.stringify(ids) === JSON.stringify(oldIds)) {
        return;
    }
    form.question_ids = [];
    reloadCreate();
}, { deep: true });

const toggleSourceSet = (id) => {
    const index = sourceSetIds.value.indexOf(id);
    if (index >= 0) {
        sourceSetIds.value.splice(index, 1);
    } else {
        sourceSetIds.value.push(id);
    }
};

const toggleQuestion = (id) => {
    const index = form.question_ids.indexOf(id);
    if (index >= 0) {
        form.question_ids.splice(index, 1);
    } else {
        form.question_ids.push(id);
    }
};

const selectAllFromSet = (setCode) => {
    const ids = (groupedQuestions.value?.[setCode] || []).map((q) => q.id);
    const merged = new Set([...form.question_ids, ...ids]);
    form.question_ids = [...merged];
};

const submitChapterTest = () => {
    form.scope = 'chapter';
    form.syllabus_chapter_id = chapterFilter.value;
    form.syllabus_topic_id = '';
    form.post(route('admin.practice-sets.store'));
};

const packageTopicSet = () => {
    packageForm.tier = form.tier;
    packageForm.post(route('admin.practice-sets.from-topic', topicFilter.value));
};
</script>

<template>
    <Head title="Create practice set" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Create practice set / chapter test</h2>
                    <p class="mt-1 text-xs text-gray-500">A set and a test are the same — you assign it to students when ready.</p>
                    <p v-if="selectedGrade" class="text-sm text-gray-500">{{ selectedGrade.name }}</p>
                </div>
                <Link :href="route('admin.practice-sets.index')" class="text-sm text-indigo-600">Back</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
                    <div>
                        <InputLabel value="Create at" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <button
                                type="button"
                                class="rounded-lg border p-4 text-left transition"
                                :class="!isChapterMode
                                    ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
                                    : 'border-gray-200 hover:border-gray-300'"
                                @click="scopeMode = 'topic'"
                            >
                                <p class="font-medium text-gray-900">Topic practice set</p>
                                <p class="mt-1 text-xs text-gray-500">Package questions from topic bank (S711…)</p>
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border p-4 text-left transition"
                                :class="isChapterMode
                                    ? 'border-sky-500 bg-sky-50 ring-1 ring-sky-500'
                                    : 'border-gray-200 hover:border-gray-300'"
                                @click="scopeMode = 'chapter'"
                            >
                                <p class="font-medium text-gray-900">Chapter test</p>
                                <p class="mt-1 text-xs text-gray-500">Pick sums from existing topic sets (T711…)</p>
                            </button>
                        </div>
                    </div>

                    <div>
                        <InputLabel value="Chapter" />
                        <select v-model="chapterFilter" class="mt-1 block w-full rounded-md border-gray-300 text-sm" required>
                            <option value="">Select chapter</option>
                            <option v-for="ch in chapters" :key="ch.id" :value="ch.id">{{ ch.label }}</option>
                        </select>
                    </div>

                    <!-- Topic practice set: package from bank -->
                    <template v-if="!isChapterMode && chapterFilter">
                        <div>
                            <InputLabel value="Topic" />
                            <select v-model="topicFilter" class="mt-1 block w-full rounded-md border-gray-300 text-sm" required>
                                <option value="">Select topic</option>
                                <option v-for="t in chapterTopics" :key="t.id" :value="t.id">
                                    {{ t.name }} ({{ t.questions_count }} in bank)
                                </option>
                            </select>
                        </div>

                        <div v-if="topicFilter && topicBank" class="rounded-md bg-indigo-50 p-4 text-sm text-indigo-900">
                            <p>
                                <strong>{{ topicBank.questions_count }}</strong> question(s) in topic bank.
                                <Link :href="addMcqsUrl" class="ml-1 text-indigo-700 underline">Add more via AI / PDF</Link>
                            </p>

                            <div v-if="topicBank.existing_sets?.length" class="mt-3 space-y-1">
                                <p class="font-medium">Sets already packaged for this topic:</p>
                                <p v-for="s in topicBank.existing_sets" :key="s.id" class="text-indigo-800">
                                    {{ s.set_code }} — {{ s.questions_count }} questions
                                    <Link :href="route('admin.questions.sets.show', s.id)" class="ml-1 underline">View</Link>
                                </p>
                            </div>

                            <div v-else-if="topicBank.questions_count > 0" class="mt-4 space-y-3">
                                <div v-if="nextSetNumber">
                                    <p>Next set: <strong>{{ previewTitle }}</strong></p>
                                </div>
                                <div>
                                    <InputLabel value="Tier" />
                                    <select v-model="form.tier" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                        <option v-for="tier in tiers" :key="tier.value" :value="tier.value">
                                            {{ tier.label }} — {{ tier.tagline }}
                                        </option>
                                    </select>
                                </div>
                                <PrimaryButton
                                    type="button"
                                    :disabled="packageForm.processing || !canPackageTopic"
                                    @click="packageTopicSet"
                                >
                                    Package all bank questions as practice set
                                </PrimaryButton>
                            </div>

                            <p v-else class="mt-2 text-indigo-800">
                                No questions in bank yet.
                                <Link :href="addMcqsUrl" class="font-medium underline">Add MCQs first</Link>
                            </p>
                        </div>
                    </template>

                    <!-- Chapter test: pick from existing sets -->
                    <template v-if="isChapterMode && chapterFilter">
                        <div v-if="nextSetNumber" class="rounded-md bg-sky-50 p-3 text-sm text-sky-900">
                            Next chapter test: <strong>Test {{ nextSetNumber }}</strong>
                            <p v-if="previewTitle" class="mt-1 opacity-90">{{ previewTitle }}</p>
                        </div>

                        <div>
                            <InputLabel value="Pick from existing topic sets" />
                            <p class="mt-1 text-xs text-gray-500">Select S711, S712, etc. — then choose sums from those sets.</p>

                            <div v-if="sourceSets.length" class="mt-2 space-y-2">
                                <label
                                    v-for="set in sourceSets"
                                    :key="set.id"
                                    class="flex cursor-pointer items-center gap-3 rounded border p-3 hover:bg-gray-50"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="sourceSetIds.includes(set.id)"
                                        class="rounded border-gray-300"
                                        @change="toggleSourceSet(set.id)"
                                    />
                                    <span class="text-sm">
                                        <span class="font-mono font-bold">{{ set.set_code }}</span>
                                        <span class="mx-1 text-gray-400">·</span>
                                        {{ set.tier_label }}
                                        <span class="mx-1 text-gray-400">·</span>
                                        {{ set.topic_name }}
                                        <span class="block text-xs text-gray-500">{{ set.questions_count }} questions</span>
                                    </span>
                                </label>
                            </div>

                            <p v-else class="mt-2 text-sm text-amber-800">
                                No topic sets in this chapter yet. Create topic practice sets first (S711…), then build a chapter test from them.
                                <Link
                                    v-if="chapterFilter"
                                    :href="route('admin.questions.chapters.show', chapterFilter)"
                                    class="ml-1 font-medium text-indigo-600 underline"
                                >
                                    Open chapter hub
                                </Link>
                            </p>
                        </div>

                        <div v-if="sourceSetIds.length && groupedQuestions">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <InputLabel :value="`Select sums (${form.question_ids.length} selected)`" />
                            </div>

                            <div class="mt-2 max-h-96 space-y-3 overflow-y-auto rounded border p-3">
                                <div v-for="(setQuestions, setCode) in groupedQuestions" :key="setCode">
                                    <div class="flex items-center justify-between">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ setCode }}</p>
                                        <button
                                            type="button"
                                            class="text-xs text-indigo-600 hover:underline"
                                            @click="selectAllFromSet(setCode)"
                                        >
                                            Select all
                                        </button>
                                    </div>
                                    <label
                                        v-for="q in setQuestions"
                                        :key="q.id"
                                        class="mt-1 flex cursor-pointer gap-3 rounded border p-3 hover:bg-gray-50"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="form.question_ids.includes(q.id)"
                                            class="mt-1 rounded border-gray-300"
                                            @change="toggleQuestion(q.id)"
                                        />
                                        <span class="text-sm">
                                            <span class="font-medium">{{ q.question_text }}</span>
                                            <span class="mt-1 block text-xs text-gray-500">{{ q.difficulty || '—' }}</span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <p v-if="!questions?.length" class="mt-2 text-sm text-gray-500">No questions in selected sets.</p>
                        </div>

                        <div v-if="sourceSetIds.length">
                            <InputLabel value="Notes (optional)" />
                            <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded-md border-gray-300 text-sm" />

                            <div class="mt-3">
                                <InputLabel value="Status" />
                                <select v-model="form.status" class="mt-1 rounded-md border-gray-300 text-sm">
                                    <option value="draft">Draft</option>
                                    <option value="published">Published</option>
                                </select>
                            </div>

                            <PrimaryButton
                                type="button"
                                class="mt-4"
                                :disabled="form.processing || !canSubmitChapterTest"
                                @click="submitChapterTest"
                            >
                                Create chapter test
                            </PrimaryButton>

                            <p v-if="!canSubmitChapterTest" class="mt-2 text-xs text-gray-500">
                                Select at least one set and pick sums to enable create.
                            </p>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
