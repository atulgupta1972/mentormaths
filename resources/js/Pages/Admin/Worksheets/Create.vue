<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    topics: Array,
    selectedTopicId: [Number, String, null],
    questions: Array,
});

const topicFilter = ref(props.selectedTopicId || '');

const form = useForm({
    title: '',
    syllabus_topic_id: props.selectedTopicId || '',
    notes: '',
    status: 'draft',
    question_ids: [],
});

watch(topicFilter, (id) => {
    router.get(route('admin.worksheets.create', { syllabus_topic_id: id || undefined }), {}, { preserveState: false });
});

const toggleQuestion = (id) => {
    const index = form.question_ids.indexOf(id);
    if (index >= 0) {
        form.question_ids.splice(index, 1);
    } else {
        form.question_ids.push(id);
    }
};

const submit = () => {
    form.syllabus_topic_id = topicFilter.value || null;
    form.post(route('admin.worksheets.store'));
};
</script>

<template>
    <Head title="Create worksheet" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">Create worksheet</h2>
                <Link :href="route('admin.worksheets.index')" class="text-sm text-indigo-600">Back</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="submit">
                    <div>
                        <InputLabel value="Worksheet title" />
                        <TextInput v-model="form.title" class="mt-1 block w-full" required />
                    </div>

                    <div>
                        <InputLabel value="Filter questions by topic" />
                        <select v-model="topicFilter" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            <option value="">All topics</option>
                            <option v-for="t in topics" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>

                    <div>
                        <InputLabel :value="`Select questions (${form.question_ids.length} selected)`" />
                        <div class="mt-2 max-h-96 space-y-2 overflow-y-auto rounded border p-3">
                            <label
                                v-for="q in questions"
                                :key="q.id"
                                class="flex cursor-pointer gap-3 rounded border p-3 hover:bg-gray-50"
                            >
                                <input
                                    type="checkbox"
                                    :checked="form.question_ids.includes(q.id)"
                                    class="mt-1 rounded border-gray-300"
                                    @change="toggleQuestion(q.id)"
                                />
                                <span class="text-sm">
                                    <span class="font-medium">{{ q.question_text }}</span>
                                    <span class="mt-1 block text-xs text-gray-500">{{ q.options?.length }} options · {{ q.difficulty || '—' }}</span>
                                </span>
                            </label>
                            <p v-if="questions.length === 0" class="text-sm text-gray-500">
                                No questions in bank for this topic.
                                <Link :href="route('admin.questions.create')" class="text-indigo-600">Add MCQs first</Link>
                            </p>
                        </div>
                    </div>

                    <div>
                        <InputLabel value="Status" />
                        <select v-model="form.status" class="mt-1 rounded-md border-gray-300 text-sm">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>

                    <PrimaryButton :disabled="form.processing || form.question_ids.length === 0">
                        Create worksheet
                    </PrimaryButton>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
