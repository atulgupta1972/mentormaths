<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    chapter: { type: Object, required: true },
    grade: { type: Object, required: true },
    board: { type: Object, required: true },
    topics: { type: Array, default: () => [] },
    formulas_count: { type: Number, default: 0 },
    sets_count: { type: Number, default: 0 },
    cursorPrompt: { type: String, default: null },
    promptDefaults: { type: Object, default: () => ({}) },
});

const page = usePage();
const copied = ref(false);
const promptBox = ref(null);

const cursorPromptText = computed(() => props.cursorPrompt || page.props.flash?.formula_bank_chapter_prompt || '');

const promptForm = useForm({
    total: props.promptDefaults?.total || 12,
    focus: props.promptDefaults?.focus || '',
    style: props.promptDefaults?.style || 'mixed',
    topic_ids: props.promptDefaults?.topic_ids?.length
        ? [...props.promptDefaults.topic_ids]
        : props.topics.map((topic) => topic.id),
});

watch(
    () => cursorPromptText.value,
    (value) => {
        if (value) {
            copied.value = false;
        }
    },
);

const toggleTopic = (topicId) => {
    const id = Number(topicId);
    if (promptForm.topic_ids.includes(id)) {
        promptForm.topic_ids = promptForm.topic_ids.filter((item) => item !== id);
    } else {
        promptForm.topic_ids = [...promptForm.topic_ids, id];
    }
};

const generatePrompt = () => {
    promptForm.post(route('admin.formula-bank.chapters.prompt', props.chapter.id), {
        preserveScroll: true,
    });
};

const copyPrompt = async () => {
    if (!cursorPromptText.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(cursorPromptText.value);
        copied.value = true;
    } catch {
        promptBox.value?.select();
        document.execCommand('copy');
        copied.value = true;
    }
};
</script>

<template>
    <Head :title="`Formulas · ${chapter.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-amber-700">Formula bank</p>
                    <h2 class="text-xl font-semibold text-gray-800">
                        Ch {{ chapter.chapter_number }} · {{ chapter.name }}
                    </h2>
                    <p class="text-sm text-gray-500">
                        {{ grade.name }} · {{ board.code }} · {{ formulas_count }} cards · {{ sets_count }} sets
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 text-sm">
                    <Link
                        :href="route('admin.questions.chapters.show', chapter.id)"
                        class="text-indigo-600 hover:underline"
                    >
                        ← Question bank chapter
                    </Link>
                    <Link
                        :href="`${route('admin.formula-bank.classes.show', grade.id)}?board_id=${board.id}`"
                        class="text-gray-600 hover:underline"
                    >
                        All chapters
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

                <div class="rounded-lg border border-amber-200 bg-amber-50/50 p-5 shadow-sm">
                    <h3 class="font-medium text-amber-950">Generate Cursor prompt for this chapter</h3>
                    <p class="mt-1 text-sm text-amber-900">
                        Describe the formulas / concepts, choose topics, copy the prompt into Cursor, then open a topic to import the JSON.
                    </p>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="How many cards?" class="!text-xs" />
                            <input
                                v-model.number="promptForm.total"
                                type="number"
                                min="1"
                                max="60"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            >
                            <InputError :message="promptForm.errors.total" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Card style" class="!text-xs" />
                            <select v-model="promptForm.style" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                <option value="mixed">Mixed formulas + concepts</option>
                                <option value="formula_recall">Mostly formula recall</option>
                                <option value="concept">Mostly concepts / definitions</option>
                                <option value="identify">Mostly “which formula?”</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <InputLabel value="Describe formulas / concepts to cover" class="!text-xs" />
                        <textarea
                            v-model="promptForm.focus"
                            rows="4"
                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            placeholder="e.g. Integer: sign rules, addition/subtraction of integers · Absolute value · Number line concepts as MCQs"
                        />
                        <InputError :message="promptForm.errors.focus" class="mt-1" />
                    </div>

                    <div class="mt-3">
                        <InputLabel value="Topics to include" class="!text-xs" />
                        <div class="mt-2 flex flex-wrap gap-2">
                            <label
                                v-for="topic in topics"
                                :key="topic.id"
                                class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs"
                                :class="promptForm.topic_ids.includes(topic.id)
                                    ? 'border-amber-400 bg-amber-100 text-amber-950'
                                    : 'border-gray-200 bg-white text-gray-600'"
                            >
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300"
                                    :checked="promptForm.topic_ids.includes(topic.id)"
                                    @change="toggleTopic(topic.id)"
                                >
                                {{ topic.name }}
                            </label>
                        </div>
                        <InputError :message="promptForm.errors.topic_ids" class="mt-1" />
                    </div>

                    <PrimaryButton
                        type="button"
                        class="mt-4 !bg-amber-700 hover:!bg-amber-800"
                        :disabled="promptForm.processing || !promptForm.topic_ids.length"
                        @click="generatePrompt"
                    >
                        {{ promptForm.processing ? 'Building…' : 'Generate Cursor prompt' }}
                    </PrimaryButton>

                    <div v-if="cursorPromptText" class="mt-4 rounded-md border border-amber-200 bg-white p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Copy → paste in Cursor</p>
                            <SecondaryButton type="button" class="!py-1 !text-xs" @click="copyPrompt">
                                {{ copied ? 'Copied!' : 'Copy prompt' }}
                            </SecondaryButton>
                        </div>
                        <textarea
                            ref="promptBox"
                            :value="cursorPromptText"
                            readonly
                            rows="14"
                            class="mt-2 w-full rounded-md border-gray-300 font-mono text-xs"
                            @focus="$event.target.select()"
                        />
                        <p class="mt-2 text-xs text-gray-600">
                            After Cursor returns JSON, open a topic below and use <strong>Import JSON</strong>.
                        </p>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-600">
                        Pick a topic to import cards into Set 1 / Set 2.
                    </p>
                </div>

                <div v-if="!topics.length" class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
                    No topics in this chapter yet.
                </div>

                <div class="divide-y divide-gray-100 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <Link
                        v-for="topic in topics"
                        :key="topic.id"
                        :href="route('admin.formula-bank.topics.show', topic.id)"
                        class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-amber-50/60"
                    >
                        <div>
                            <p class="font-medium text-gray-900">{{ topic.name }}</p>
                            <p class="text-xs text-gray-500">Open to import JSON / create formula sets</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-900 ring-1 ring-amber-200">
                            {{ topic.formulas_count }} cards · {{ topic.sets_count }} sets
                        </span>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
