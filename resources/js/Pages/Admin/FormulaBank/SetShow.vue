<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    set: { type: Object, required: true },
    sampleJson: { type: String, default: '' },
});

const page = usePage();
const showImport = ref(false);
const importForm = useForm({ json: props.sampleJson || '' });

const submitImport = () => {
    importForm.post(route('admin.formula-bank.sets.import', props.set.id), {
        preserveScroll: true,
        onSuccess: () => {
            showImport.value = false;
        },
    });
};
</script>

<template>
    <Head :title="set.set_code || `Formula set ${set.set_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-indigo-600">Formula bank</p>
                    <h2 class="text-xl font-semibold text-gray-800">
                        {{ set.set_code }} · Set {{ set.set_number }}
                    </h2>
                    <p class="text-sm text-gray-500">
                        {{ set.grade?.name }} · {{ set.board?.code }} · {{ set.topic?.chapter_name }} · {{ set.topic?.name }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 text-sm">
                    <Link
                        v-if="set.grade?.id && set.board?.id"
                        :href="route('admin.formula-bank.index', { board_id: set.board.id, grade_id: set.grade.id })"
                        class="font-medium text-amber-800 hover:underline"
                    >
                        ← Formula summary
                    </Link>
                    <Link
                        v-if="set.topic?.id"
                        :href="route('admin.formula-bank.topics.show', set.topic.id)"
                        class="text-gray-600 hover:underline"
                    >
                        Topic
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
                >
                    {{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash?.error"
                    class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
                >
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-gray-900">{{ set.title }}</h3>
                            <p class="text-xs text-gray-500">{{ set.questions_count }} formula / concept cards</p>
                        </div>
                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="showImport = !showImport">
                            {{ showImport ? 'Hide import' : 'Add more MCQs' }}
                        </SecondaryButton>
                    </div>

                    <div v-if="showImport" class="mt-4 space-y-3 rounded-md border border-indigo-100 bg-indigo-50/40 p-4">
                        <textarea
                            v-model="importForm.json"
                            rows="12"
                            class="w-full rounded-md border-gray-300 font-mono text-xs"
                        />
                        <InputError :message="importForm.errors.json" />
                        <PrimaryButton type="button" :disabled="importForm.processing" @click="submitImport">
                            {{ importForm.processing ? 'Importing…' : 'Import into this set' }}
                        </PrimaryButton>
                    </div>
                </div>

                <div class="space-y-3">
                    <div
                        v-for="(question, index) in set.questions"
                        :key="question.id"
                        class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200"
                    >
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Card {{ index + 1 }}</p>
                        <p class="mt-1 font-medium text-gray-900" v-html="question.question_text" />
                        <ul class="mt-3 space-y-1.5 text-sm">
                            <li
                                v-for="option in question.options"
                                :key="option.id"
                                class="rounded-md px-3 py-1.5"
                                :class="option.is_correct
                                    ? 'bg-emerald-50 font-medium text-emerald-900 ring-1 ring-emerald-200'
                                    : 'bg-gray-50 text-gray-700'"
                            >
                                {{ option.option_text }}
                                <span v-if="option.is_correct" class="ml-1 text-xs">✓</span>
                            </li>
                        </ul>
                        <p v-if="question.explanation" class="mt-2 text-xs text-gray-500">
                            {{ question.explanation }}
                        </p>
                    </div>

                    <p v-if="!set.questions?.length" class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
                        No cards yet — use “Add more MCQs” to paste JSON from Cursor.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
