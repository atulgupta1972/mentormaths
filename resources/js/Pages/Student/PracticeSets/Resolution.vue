<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    item: Object,
});

const page = usePage();
const form = useForm({ option_id: null });

const submitAnswer = (optionId) => {
    form.option_id = optionId;
    form.post(route('student.resolutions.answer', props.item.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Resolve sum" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Resolution practice</p>
                    <h2 class="text-xl font-semibold text-gray-800">
                        <span v-if="item.set_code" class="font-mono text-indigo-600">{{ item.set_code }}</span>
                        <span v-else>Clear this sum</span>
                    </h2>
                </div>
                <Link :href="route('dashboard')" class="text-sm text-indigo-600">Dashboard</Link>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-3xl space-y-5 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    Your teacher should have explained this sum. Try again now — when you get it right, it leaves your resolution list.
                </div>

                <div v-if="page.props.flash?.warning" class="rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                    {{ page.props.flash.warning }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 p-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <QuestionBody
                        :question-text="item.question_text"
                        :diagram-url="item.diagram_url"
                        use-html
                    />

                    <div class="mt-4 space-y-2">
                        <button
                            v-for="(opt, optIndex) in item.options"
                            :key="opt.id"
                            type="button"
                            class="flex w-full items-start gap-3 rounded-lg border border-gray-200 px-4 py-3 text-left text-sm transition hover:border-indigo-300 hover:bg-indigo-50"
                            :disabled="form.processing"
                            @click="submitAnswer(opt.id)"
                        >
                            <McqOptionLine :index="optIndex" :text="opt.option_text" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
