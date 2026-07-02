<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    worksheet: Object,
});

const form = useForm({});

const destroy = () => {
    if (confirm('Delete this worksheet?')) {
        form.delete(route('admin.worksheets.destroy', props.worksheet.id));
    }
};
</script>

<template>
    <Head :title="worksheet.title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">{{ worksheet.title }}</h2>
                <Link :href="route('admin.worksheets.index')" class="text-sm text-indigo-600">Back</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-4 shadow-sm text-sm text-gray-600">
                    Status: <strong class="capitalize">{{ worksheet.status }}</strong>
                    · {{ worksheet.questions.length }} questions
                    <span v-if="worksheet.topic"> · Topic: {{ worksheet.topic.name }}</span>
                </div>

                <div class="space-y-4">
                    <div
                        v-for="(q, index) in worksheet.questions"
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
                    Delete worksheet
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
