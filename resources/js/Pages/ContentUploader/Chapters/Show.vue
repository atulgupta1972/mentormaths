<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    chapter: { type: Object, required: true },
    task: { type: Object, required: true },
    questions: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');
const showAdd = ref(false);
const requestIndex = ref(null);

const jsonForm = useForm({ json: '' });
const zipForm = useForm({ pack: null });
const deleteForm = useForm({ item_index: null });
const requestForm = useForm({ item_index: null, reason: '' });

const filteredQuestions = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) {
        return props.questions;
    }

    return props.questions.filter((item) => {
        const hay = [
            item.question_text,
            item.topic,
            item.label,
            item.correct_answer,
            ...(item.options || []).map((option) => option.text),
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        return hay.includes(q);
    });
});

const submitJson = () => {
    jsonForm.post(route('content.chapters.append-mcq', props.chapter.id), {
        preserveScroll: true,
        onSuccess: () => {
            jsonForm.reset();
            showAdd.value = false;
        },
    });
};

const submitZip = () => {
    zipForm.post(route('content.chapters.append-mcq-zip', props.chapter.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            zipForm.reset();
            showAdd.value = false;
        },
    });
};

const deleteQuestion = (index) => {
    if (!window.confirm('Delete this question? This cannot be undone.')) {
        return;
    }

    deleteForm.item_index = index;
    deleteForm.post(route('content.chapters.delete-question', props.chapter.id), {
        preserveScroll: true,
    });
};

const openRequest = (index) => {
    requestIndex.value = index;
    requestForm.item_index = index;
    requestForm.reason = '';
};

const submitRequest = () => {
    requestForm.post(route('content.chapters.request-delete', props.chapter.id), {
        preserveScroll: true,
        onSuccess: () => {
            requestIndex.value = null;
            requestForm.reset();
        },
    });
};
</script>

<template>
    <Head :title="`Ch ${chapter.chapter_number} questions`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        {{ chapter.grade_name }} · Ch {{ chapter.chapter_number }} — {{ chapter.title }}
                    </h2>
                    <p class="text-sm text-gray-500">
                        {{ chapter.textbook_name }} · {{ chapter.question_count }} question{{ chapter.question_count === 1 ? '' : 's' }} · {{ task.status_label }}
                    </p>
                </div>
                <Link
                    :href="route('content.chapters.index', { grade_level_id: chapter.grade_id })"
                    class="text-sm text-indigo-600 hover:underline"
                >
                    ← All chapters
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-950">
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700">
                    <p>
                        Scroll the full list before adding more so you do not upload a duplicate.
                        You can add questions after publish.
                        <span v-if="task.can_delete">Delete a wrong or duplicate question here.</span>
                        <span v-else>After publish, send a delete request to admin — you cannot delete it yourself.</span>
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
                            @click="showAdd = !showAdd"
                        >
                            {{ showAdd ? 'Hide add form' : 'Add more questions' }}
                        </button>
                        <Link
                            :href="route('content.textbooks.show', chapter.id)"
                            class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                        >
                            Open chapter editor
                        </Link>
                    </div>
                </div>

                <div v-if="showAdd" class="space-y-4 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <h3 class="font-semibold text-gray-900">Add more questions</h3>
                    <p class="text-sm text-gray-500">Paste AI JSON or upload a zip. Existing questions stay — new ones are appended.</p>

                    <form class="space-y-2" @submit.prevent="submitJson">
                        <label class="text-sm font-medium text-gray-700">Paste questions JSON</label>
                        <textarea
                            v-model="jsonForm.json"
                            rows="8"
                            class="w-full rounded-md border-gray-300 text-sm"
                            placeholder='{"questions":[{"question":"...","options":["A","B","C","D"],"correct_index":0}]}'
                        />
                        <p v-if="jsonForm.errors.json" class="text-sm text-rose-600">{{ jsonForm.errors.json }}</p>
                        <button
                            type="submit"
                            class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                            :disabled="jsonForm.processing || !jsonForm.json.trim()"
                        >
                            {{ jsonForm.processing ? 'Adding…' : 'Add from JSON' }}
                        </button>
                    </form>

                    <form class="space-y-2 border-t border-gray-100 pt-4" @submit.prevent="submitZip">
                        <label class="text-sm font-medium text-gray-700">Or upload zip (questions.json + charts)</label>
                        <input
                            type="file"
                            accept=".zip"
                            class="block text-sm"
                            @change="zipForm.pack = $event.target.files?.[0] || null"
                        >
                        <p v-if="zipForm.errors.pack" class="text-sm text-rose-600">{{ zipForm.errors.pack }}</p>
                        <button
                            type="submit"
                            class="rounded-md border border-indigo-300 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-50"
                            :disabled="zipForm.processing || !zipForm.pack"
                        >
                            {{ zipForm.processing ? 'Adding…' : 'Add from zip' }}
                        </button>
                    </form>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search questions to check for duplicates…"
                        class="w-full max-w-md rounded-md border-gray-300 text-sm"
                    >
                    <p class="text-sm text-gray-500">
                        Showing {{ filteredQuestions.length }} of {{ questions.length }}
                    </p>
                </div>

                <div v-if="filteredQuestions.length" class="space-y-3">
                    <article
                        v-for="item in filteredQuestions"
                        :key="item.index"
                        class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">
                                    Q{{ item.number }}<span v-if="item.topic"> · {{ item.topic }}</span>
                                </p>
                                <p class="mt-1 whitespace-pre-wrap font-medium text-gray-900">{{ item.question_text }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-if="task.can_delete"
                                    type="button"
                                    class="rounded-md border border-rose-200 px-2.5 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50"
                                    :disabled="deleteForm.processing"
                                    @click="deleteQuestion(item.index)"
                                >
                                    Delete
                                </button>
                                <button
                                    v-else-if="!item.delete_request"
                                    type="button"
                                    class="rounded-md border border-amber-200 px-2.5 py-1 text-xs font-medium text-amber-800 hover:bg-amber-50"
                                    @click="openRequest(item.index)"
                                >
                                    Ask admin to delete
                                </button>
                                <span
                                    v-else
                                    class="rounded-md bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800"
                                >
                                    Delete requested
                                </span>
                            </div>
                        </div>

                        <img
                            v-if="item.diagram_preview_url"
                            :src="item.diagram_preview_url"
                            alt=""
                            class="mt-3 max-h-48 rounded-md border border-gray-200"
                        >

                        <ol v-if="item.options?.length" class="mt-3 space-y-1 text-sm">
                            <li
                                v-for="option in item.options"
                                :key="option.letter"
                                :class="option.is_correct ? 'font-semibold text-emerald-800' : 'text-gray-700'"
                            >
                                {{ option.letter }}. {{ option.text }}
                                <span v-if="option.is_correct" class="text-xs font-medium text-emerald-700"> (correct)</span>
                            </li>
                        </ol>

                        <p v-if="item.explanation" class="mt-2 text-sm text-gray-500">
                            {{ item.explanation }}
                        </p>

                        <form
                            v-if="requestIndex === item.index"
                            class="mt-3 space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3"
                            @submit.prevent="submitRequest"
                        >
                            <label class="text-sm font-medium text-amber-950">Why should admin delete this?</label>
                            <textarea
                                v-model="requestForm.reason"
                                rows="3"
                                class="w-full rounded-md border-amber-200 text-sm"
                                placeholder="Duplicate of Q12 / wrong figure / not in this chapter…"
                            />
                            <p v-if="requestForm.errors.reason" class="text-sm text-rose-600">{{ requestForm.errors.reason }}</p>
                            <div class="flex gap-2">
                                <button
                                    type="submit"
                                    class="rounded-md bg-amber-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-800 disabled:opacity-50"
                                    :disabled="requestForm.processing || requestForm.reason.trim().length < 8"
                                >
                                    {{ requestForm.processing ? 'Sending…' : 'Send request' }}
                                </button>
                                <button type="button" class="text-sm text-gray-600 hover:underline" @click="requestIndex = null">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </article>
                </div>

                <p v-else class="rounded-lg bg-white p-8 text-center text-gray-500 shadow-sm ring-1 ring-gray-200">
                    {{ questions.length ? 'No questions match that search.' : 'No questions uploaded yet. Add the first batch from the chapter editor.' }}
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
