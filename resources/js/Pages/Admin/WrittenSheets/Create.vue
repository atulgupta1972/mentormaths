<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    gradeLevel: { type: Object, default: null },
    chapters: { type: Array, default: () => [] },
    topics: { type: Array, default: () => [] },
    questions: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const form = useForm({
    sheet_kind: props.filters.sheet_kind || 'practice',
    chapter_id: props.filters.chapter_id || '',
    topic_id: props.filters.topic_id || '',
    question_ids: [],
    notes: '',
});

watch(
    () => [form.chapter_id, form.topic_id, form.sheet_kind],
    () => {
        router.get(route('admin.written-sheets.create'), {
            chapter_id: form.chapter_id || undefined,
            topic_id: form.topic_id || undefined,
            sheet_kind: form.sheet_kind,
        }, { preserveState: true, replace: true, preserveScroll: true });
    },
);

const showTopicPicker = computed(() => form.sheet_kind === 'practice');

const toggleQuestion = (id) => {
    const ids = new Set(form.question_ids);

    if (ids.has(id)) {
        ids.delete(id);
    } else {
        ids.add(id);
    }

    form.question_ids = [...ids];
};

const submit = () => {
    form.post(route('admin.written-sheets.store'));
};
</script>

<template>
    <Head title="Create written sheet" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">Create written sheet</h2>
                <Link :href="route('admin.written-sheets.index')" class="text-sm text-indigo-600">Back</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <form class="space-y-6 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200" @submit.prevent="submit">
                    <p class="text-sm text-gray-600">
                        Step 1: pick chapter/topic and questions. A printable PDF is generated for admin review before assigning.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Sheet type" />
                            <select v-model="form.sheet_kind" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option value="practice">Practice</option>
                                <option value="test">Test</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Chapter" />
                            <select v-model="form.chapter_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option value="">Select chapter</option>
                                <option v-for="chapter in chapters" :key="chapter.id" :value="String(chapter.id)">
                                    {{ chapter.label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.chapter_id" class="mt-1" />
                        </div>
                        <div v-if="showTopicPicker">
                            <InputLabel value="Topic (practice)" />
                            <select v-model="form.topic_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm" :disabled="!form.chapter_id">
                                <option value="">Select topic</option>
                                <option v-for="topic in topics" :key="topic.id" :value="String(topic.id)">
                                    {{ topic.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.topic_id" class="mt-1" />
                        </div>
                    </div>

                    <div v-if="questions.length">
                        <InputLabel :value="`Select questions (${form.question_ids.length} selected)`" />
                        <div class="mt-2 max-h-96 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3">
                            <label
                                v-for="question in questions"
                                :key="question.id"
                                class="flex cursor-pointer gap-3 rounded-md border border-transparent p-2 hover:bg-gray-50"
                            >
                                <input
                                    type="checkbox"
                                    :checked="form.question_ids.includes(question.id)"
                                    class="mt-1 rounded border-gray-300 text-indigo-600"
                                    @change="toggleQuestion(question.id)"
                                >
                                <span>
                                    <span class="text-xs font-medium text-gray-500">{{ question.topic_name }} · {{ question.type }}</span>
                                    <span class="block text-sm text-gray-900">{{ question.question_text }}</span>
                                </span>
                            </label>
                        </div>
                        <InputError :message="form.errors.question_ids" class="mt-1" />
                    </div>

                    <div v-else-if="form.chapter_id" class="rounded-md border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                        No questions found for this selection.
                    </div>

                    <div>
                        <InputLabel value="Notes (optional)" />
                        <textarea v-model="form.notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                    </div>

                    <div class="flex gap-2">
                        <PrimaryButton :disabled="form.processing || !form.question_ids.length">
                            Generate PDF for review
                        </PrimaryButton>
                        <Link :href="route('admin.written-sheets.index')">
                            <SecondaryButton type="button">Cancel</SecondaryButton>
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
