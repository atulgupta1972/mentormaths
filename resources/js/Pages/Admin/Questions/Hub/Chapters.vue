<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    gradeLevel: Object,
    board: Object,
    activeYear: Object,
    syllabusVersion: Object,
    chapters: Array,
    stats: Object,
});

const isAdmin = computed(() => usePage().props.auth?.isAdmin ?? false);
</script>

<template>
    <Head :title="`${gradeLevel.name} — Questions`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <Link :href="route('admin.questions.index')" class="text-sm text-indigo-600">← All boards</Link>
                <h2 class="mt-1 text-xl font-semibold text-gray-800">
                    {{ board?.code }} · {{ gradeLevel.name }} — Question Bank
                </h2>
                <p v-if="activeYear" class="text-sm text-gray-500">
                    {{ board?.name }} · {{ activeYear.name }} · {{ stats.questions_count }} questions
                </p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <BrowseModeNotice />
                <div
                    v-if="usePage().props.flash?.warning"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    {{ usePage().props.flash.warning }}
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.chapters_count }}</p>
                        <p class="text-xs text-gray-500">Chapters</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.topics_count }}</p>
                        <p class="text-xs text-gray-500">Topics</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ stats.questions_count }}</p>
                        <p class="text-xs text-gray-500">Questions</p>
                    </div>
                </div>

                <div v-if="!syllabusVersion" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    No {{ board?.code }} syllabus for {{ gradeLevel.name }} yet.
                    <Link v-if="isAdmin" :href="route('admin.syllabus.index')" class="font-medium text-indigo-600">Import syllabus</Link>
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="chapter in chapters"
                        :key="chapter.id"
                        :href="route('admin.questions.chapters.show', chapter.id)"
                        class="rounded-xl border bg-white p-5 shadow-sm transition hover:border-indigo-300 hover:shadow-md"
                    >
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Chapter {{ chapter.chapter_number }}
                        </p>
                        <h3 class="mt-1 text-lg font-semibold text-gray-900">{{ chapter.name }}</h3>
                        <div class="mt-4 flex gap-4 text-sm">
                            <span class="text-gray-600">{{ chapter.topics_count }} topics</span>
                            <span class="font-semibold text-indigo-600">{{ chapter.questions_count }} questions</span>
                        </div>
                    </Link>
                </div>

                <p v-if="syllabusVersion && chapters.length === 0" class="text-center text-sm text-gray-500">
                    No chapters in syllabus yet.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
