<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    practiceSet: Object,
});

const form = useForm({});

const destroy = () => {
    if (confirm('Delete this practice set?')) {
        form.delete(route('admin.practice-sets.destroy', props.practiceSet.id));
    }
};
</script>

<template>
    <Head :title="practiceSet.display_title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">{{ practiceSet.display_title }}</h2>
                    <p class="text-sm text-gray-500">{{ practiceSet.tier_tagline }}</p>
                </div>
                <Link
                    v-if="practiceSet.syllabus_topic_id"
                    :href="route('admin.practice-sets.topics.show', practiceSet.syllabus_topic_id)"
                    class="text-sm text-indigo-600"
                >
                    Topic hub
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-4 text-sm text-gray-600 shadow-sm">
                    Status: <strong class="capitalize">{{ practiceSet.status }}</strong>
                    · {{ practiceSet.questions.length }} questions
                    <span v-if="practiceSet.topic"> · {{ practiceSet.topic.chapter?.name }} — {{ practiceSet.topic.name }}</span>
                </div>

                <div class="space-y-4">
                    <div
                        v-for="(q, index) in practiceSet.questions"
                        :key="q.id"
                        class="rounded-lg bg-white p-4 shadow-sm"
                    >
                        <p class="text-sm font-medium text-gray-500">Q{{ index + 1 }}</p>
                        <p class="mt-1 font-medium text-gray-900">{{ q.question_text }}</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            <li
                                v-for="(opt, optIndex) in q.options"
                                :key="opt.id"
                                :class="opt.is_correct ? 'font-semibold text-green-700' : 'text-gray-700'"
                            >
                                <McqOptionLine :index="optIndex" :text="opt.option_text" />
                                <span v-if="opt.is_correct"> ✓</span>
                            </li>
                        </ul>
                        <p v-if="q.explanation" class="mt-2 text-xs text-gray-500">{{ q.explanation }}</p>
                    </div>
                </div>

                <button type="button" class="text-sm text-red-600 hover:text-red-800" @click="destroy">
                    Delete practice set
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
