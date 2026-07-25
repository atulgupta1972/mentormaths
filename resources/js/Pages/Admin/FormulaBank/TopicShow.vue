<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    topic: { type: Object, required: true },
    sampleJson: { type: String, default: '' },
});

const page = usePage();
const showImport = ref(false);

const setForm = useForm({ title: '' });
const importForm = useForm({
    json: props.sampleJson || '',
    create_set: true,
    worksheet_id: null,
});
const packageForm = useForm({});

const createSet = () => {
    setForm.post(route('admin.formula-bank.topics.sets.store', props.topic.id), {
        preserveScroll: true,
        onSuccess: () => setForm.reset(),
    });
};

const submitImport = () => {
    importForm.post(route('admin.formula-bank.topics.import', props.topic.id), {
        preserveScroll: true,
    });
};

const packageUnpacked = () => {
    packageForm.post(route('admin.formula-bank.topics.package', props.topic.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Formula · ${topic.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-indigo-600">Formula bank</p>
                    <h2 class="text-xl font-semibold text-gray-800">{{ topic.name }}</h2>
                    <p class="text-sm text-gray-500">
                        {{ topic.grade?.name }} · {{ topic.board?.code }} · {{ topic.chapter?.name }}
                    </p>
                </div>
                <Link
                    v-if="topic.grade?.id"
                    :href="`${route('admin.formula-bank.classes.show', topic.grade.id)}?board_id=${topic.board?.id}`"
                    class="text-sm text-indigo-600 hover:underline"
                >
                    ← Chapters
                </Link>
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
                            <h3 class="font-medium text-gray-900">Formula sets</h3>
                            <p class="text-xs text-gray-500">{{ topic.formulas_count }} cards in this topic · Set 1, Set 2, …</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="showImport = !showImport">
                                {{ showImport ? 'Hide import' : 'Import MCQs (JSON)' }}
                            </SecondaryButton>
                            <PrimaryButton type="button" class="!py-1.5 !text-xs" :disabled="setForm.processing" @click="createSet">
                                New empty set
                            </PrimaryButton>
                        </div>
                    </div>

                    <div v-if="showImport" class="mt-4 space-y-3 rounded-md border border-indigo-100 bg-indigo-50/40 p-4">
                        <p class="text-sm text-indigo-950">
                            Paste formula / concept MCQs as JSON (question + options + correct_index). Tick “create set” to attach them to a new set.
                        </p>
                        <label class="flex items-center gap-2 text-sm text-gray-800">
                            <input v-model="importForm.create_set" type="checkbox" class="rounded border-gray-300">
                            Create a new set and attach these cards
                        </label>
                        <textarea
                            v-model="importForm.json"
                            rows="14"
                            class="w-full rounded-md border-gray-300 font-mono text-xs"
                        />
                        <InputError :message="importForm.errors.json" />
                        <PrimaryButton type="button" :disabled="importForm.processing" @click="submitImport">
                            {{ importForm.processing ? 'Importing…' : 'Import formulas' }}
                        </PrimaryButton>
                    </div>

                    <div v-if="topic.sets?.length" class="mt-4 divide-y divide-gray-100 overflow-hidden rounded-md border border-gray-200">
                        <Link
                            v-for="set in topic.sets"
                            :key="set.id"
                            :href="route('admin.formula-bank.sets.show', set.id)"
                            class="flex items-center justify-between gap-3 bg-white px-4 py-3 text-sm hover:bg-gray-50"
                        >
                            <div>
                                <p class="font-semibold text-gray-900">{{ set.set_code }} · Set {{ set.set_number }}</p>
                                <p class="text-xs text-gray-500">{{ set.title }}</p>
                            </div>
                            <span class="text-xs text-gray-500">{{ set.questions_count }} cards</span>
                        </Link>
                    </div>
                    <p v-else class="mt-4 text-sm text-gray-500">No formula sets yet — create one or import JSON with “create set”.</p>
                </div>

                <div
                    v-if="topic.unpacked_formulas?.length"
                    class="rounded-lg border border-amber-200 bg-amber-50/50 p-5"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-amber-950">Unpacked cards ({{ topic.unpacked_formulas.length }})</h3>
                            <p class="text-xs text-amber-900">Imported but not in a set yet.</p>
                        </div>
                        <PrimaryButton type="button" class="!py-1.5 !text-xs" :disabled="packageForm.processing" @click="packageUnpacked">
                            Package into new set
                        </PrimaryButton>
                    </div>
                    <ul class="mt-3 space-y-2 text-sm text-gray-800">
                        <li v-for="q in topic.unpacked_formulas" :key="q.id" class="rounded-md bg-white px-3 py-2 ring-1 ring-amber-100">
                            {{ q.question_text }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
