<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    chapter: Object,
    gradeLevel: Object,
    boardCode: String,
    topics: Array,
    questions: Array,
    nextSetNumber: Number,
    defaultPerTopic: Number,
});

const form = useForm({
    question_ids: [],
    notes: '',
    status: 'published',
});

const grouped = computed(() => {
    const map = {};
    for (const q of props.questions) {
        const key = q.topic_name || 'Other';
        if (!map[key]) map[key] = [];
        map[key].push(q);
    }
    return map;
});

const toggleQuestion = (id) => {
    const index = form.question_ids.indexOf(id);
    if (index >= 0) {
        form.question_ids.splice(index, 1);
    } else {
        form.question_ids.push(id);
    }
};

const selectPerTopic = (count) => {
    const picked = [];
    for (const topicQuestions of Object.values(grouped.value)) {
        picked.push(...topicQuestions.slice(0, count).map((q) => q.id));
    }
    form.question_ids = picked;
};

const submit = () => {
    form.post(route('admin.practice-sets.chapters.store', props.chapter.id));
};
</script>

<template>
    <Head title="Create chapter test" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <Link :href="route('admin.practice-sets.chapters.show', chapter.id)" class="text-sm text-indigo-600">
                        ← Chapter tests
                    </Link>
                    <h2 class="mt-1 text-xl font-semibold text-gray-800">
                        Build chapter test — Ch {{ chapter.chapter_number }} {{ chapter.name }}
                    </h2>
                    <p class="text-sm text-gray-500">{{ boardCode }} {{ gradeLevel?.name }} · Test {{ nextSetNumber }}</p>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="submit">
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="rounded-md border px-3 py-1 text-sm hover:bg-gray-50" @click="selectPerTopic(1)">
                            1 per topic
                        </button>
                        <button type="button" class="rounded-md border px-3 py-1 text-sm hover:bg-gray-50" @click="selectPerTopic(2)">
                            2 per topic
                        </button>
                        <button type="button" class="rounded-md border px-3 py-1 text-sm hover:bg-gray-50" @click="selectPerTopic(3)">
                            3 per topic
                        </button>
                        <span class="self-center text-sm text-gray-500">{{ form.question_ids.length }} selected</span>
                    </div>

                    <div v-if="questions.length === 0" class="text-sm text-gray-500">
                        No questions in this chapter yet.
                        <Link :href="route('admin.questions.create')" class="text-indigo-600">Add MCQs</Link>
                    </div>

                    <div v-for="(topicQuestions, topicName) in grouped" :key="topicName" class="rounded-md border p-3">
                        <p class="text-sm font-semibold text-gray-800">{{ topicName }}</p>
                        <div class="mt-2 max-h-48 space-y-2 overflow-y-auto">
                            <label
                                v-for="q in topicQuestions"
                                :key="q.id"
                                class="flex cursor-pointer gap-2 rounded p-2 hover:bg-gray-50"
                            >
                                <input
                                    type="checkbox"
                                    :checked="form.question_ids.includes(q.id)"
                                    class="mt-1"
                                    @change="toggleQuestion(q.id)"
                                />
                                <span class="text-sm text-gray-700">{{ q.question_text }}</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <InputLabel value="Notes (optional)" />
                        <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                    </div>

                    <div>
                        <InputLabel value="Status" />
                        <select v-model="form.status" class="mt-1 rounded-md border-gray-300 text-sm">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>

                    <PrimaryButton type="submit" :disabled="form.processing || form.question_ids.length === 0">
                        Create chapter test ({{ form.question_ids.length }} questions)
                    </PrimaryButton>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
