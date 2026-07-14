<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    gradeLevel: Object,
    board: Object,
    activeYear: Object,
    syllabusVersion: Object,
    chapters: { type: Array, default: () => [] },
    stats: Object,
});

const chaptersUrl = (chapterId) => route('admin.question-audit.chapters.show', chapterId);
const classesUrl = `${route('admin.question-audit.classes.show', props.gradeLevel.id)}?board_id=${props.board?.id}`;
</script>

<template>
    <Head :title="`${gradeLevel.name} — Answer audit`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <Link :href="route('admin.question-audit.index')" class="text-sm text-indigo-600">← Answer audit</Link>
                <h2 class="mt-1 text-xl font-semibold text-gray-800">
                    {{ board?.code }} · {{ gradeLevel.name }} — Chapters
                </h2>
                <p v-if="activeYear" class="text-sm text-gray-500">
                    Pick a chapter to audit its practice sets and chapter tests.
                </p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.chapters_count }}</p>
                        <p class="text-xs text-gray-500">Chapters</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.total_sets }}</p>
                        <p class="text-xs text-gray-500">Sets / tests</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-amber-700">{{ stats.not_audited }}</p>
                        <p class="text-xs text-gray-500">Not audited</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-red-700">{{ stats.issues }}</p>
                        <p class="text-xs text-gray-500">With issues</p>
                    </div>
                </div>

                <div v-if="!syllabusVersion" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    No {{ board?.code }} syllabus for {{ gradeLevel.name }} yet.
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="chapter in chapters"
                        :key="chapter.id"
                        :href="chaptersUrl(chapter.id)"
                        class="rounded-xl border bg-white p-5 shadow-sm transition hover:border-indigo-300 hover:shadow-md"
                    >
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Chapter {{ chapter.chapter_number }}
                        </p>
                        <h3 class="mt-1 text-lg font-semibold text-gray-900">{{ chapter.name }}</h3>
                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <p class="text-gray-500">Sets / tests</p>
                                <p class="font-semibold text-gray-900">{{ chapter.total_sets }}</p>
                            </div>
                            <div>
                                <p class="text-gray-500">Not audited</p>
                                <p class="font-semibold text-amber-700">{{ chapter.not_audited }}</p>
                            </div>
                            <div>
                                <p class="text-gray-500">Clean</p>
                                <p class="font-semibold text-green-700">{{ chapter.clean }}</p>
                            </div>
                            <div>
                                <p class="text-gray-500">Issues</p>
                                <p class="font-semibold text-red-700">{{ chapter.issues }}</p>
                            </div>
                        </div>
                    </Link>
                </div>

                <p v-if="syllabusVersion && chapters.length === 0" class="text-center text-sm text-gray-500">
                    No chapters in syllabus yet.
                </p>

                <Link :href="classesUrl" class="inline-block text-sm text-indigo-600 hover:underline">
                    ← Back to {{ gradeLevel.name }}
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
