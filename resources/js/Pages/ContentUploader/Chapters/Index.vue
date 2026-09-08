<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GeminiCheckRequiredBanner from '@/Components/GeminiCheckRequiredBanner.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    grades: { type: Array, default: () => [] },
    selected_grade_id: { type: [Number, null], default: null },
    chapters: { type: Array, default: () => [] },
    gemini_pending_count: { type: Number, default: 0 },
    gemini_blocked: { type: Boolean, default: false },
    gemini_pending: { type: Array, default: () => [] },
});

const selectGrade = (gradeId) => {
    router.get(route('content.chapters.index'), { grade_level_id: gradeId }, { preserveState: true });
};

const geminiProgressLabel = (chapter) => {
    const progress = chapter.gemini_progress;
    if (!progress?.can_gemini) {
        return null;
    }

    return `${progress.verified}/${progress.total}`;
};
</script>

<template>
    <Head title="My chapters" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">My chapters</h2>
                    <p class="text-sm text-gray-500">Uploaded chapters — Gemini pending must be finished before any new upload.</p>
                </div>
                <Link :href="route('content.tasks.index')" class="text-sm text-indigo-600 hover:underline">← My content tasks</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6">
                <GeminiCheckRequiredBanner
                    v-if="gemini_blocked || gemini_pending_count"
                    :pending-count="gemini_pending_count"
                    :pending-tasks="gemini_pending"
                />

                <div v-if="grades.length" class="flex flex-wrap gap-2">
                    <button
                        v-for="grade in grades"
                        :key="grade.id"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-sm font-medium"
                        :class="grade.id === selected_grade_id
                            ? 'bg-indigo-600 text-white'
                            : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50'"
                        @click="selectGrade(grade.id)"
                    >
                        {{ grade.name }}
                    </button>
                </div>

                <div v-if="chapters.length" class="space-y-2">
                    <div
                        v-for="chapter in chapters"
                        :key="chapter.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white p-4 shadow-sm ring-1"
                        :class="chapter.needs_gemini_check ? 'ring-amber-300 bg-amber-50/40' : 'ring-gray-200'"
                    >
                        <div>
                            <p class="font-semibold text-gray-900">
                                Ch {{ chapter.chapter_number }} — {{ chapter.title }}
                            </p>
                            <p class="text-sm text-gray-500">
                                {{ chapter.textbook_name }} · {{ chapter.question_count }} question{{ chapter.question_count === 1 ? '' : 's' }} · {{ chapter.task_status_label }}
                            </p>
                            <p
                                v-if="chapter.needs_gemini_check"
                                class="mt-1 text-xs font-semibold text-amber-800"
                            >
                                Gemini check pending{{ geminiProgressLabel(chapter) ? ` · ${geminiProgressLabel(chapter)} verified` : '' }}
                            </p>
                            <p
                                v-else-if="geminiProgressLabel(chapter)"
                                class="mt-1 text-xs font-semibold text-emerald-800"
                            >
                                Gemini done · {{ geminiProgressLabel(chapter) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                v-if="chapter.needs_gemini_check && chapter.task_id"
                                :href="route('content.tasks.show', chapter.task_id)"
                                class="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700"
                            >
                                Gemini check →
                            </Link>
                            <Link
                                :href="route('content.chapters.show', chapter.id)"
                                class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
                            >
                                View questions →
                            </Link>
                        </div>
                    </div>
                </div>

                <p v-else class="rounded-lg bg-white p-8 text-center text-gray-500 shadow-sm ring-1 ring-gray-200">
                    No chapters assigned for this class yet.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
