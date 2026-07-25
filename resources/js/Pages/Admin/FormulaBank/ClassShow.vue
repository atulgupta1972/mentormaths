<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    grade: { type: Object, required: true },
    board: { type: Object, required: true },
    boards: { type: Array, default: () => [] },
    activeYear: { type: Object, default: null },
    chapters: { type: Array, default: () => [] },
});

const page = usePage();

const switchBoard = (boardId) => {
    router.get(route('admin.formula-bank.classes.show', props.grade.id), { board_id: boardId }, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Formula bank · ${grade.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-indigo-600">Formula bank</p>
                    <h2 class="text-xl font-semibold text-gray-800">{{ grade.name }} · {{ board.code }}</h2>
                    <p v-if="activeYear" class="text-sm text-gray-500">{{ activeYear.name }}</p>
                </div>
                <Link
                    :href="route('admin.formula-bank.index', { board_id: board.id, grade_id: grade.id })"
                    class="text-sm font-medium text-amber-800 hover:underline"
                >
                    ← Formula summary
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
                >
                    {{ page.props.flash.success }}
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="b in boards"
                        :key="b.id"
                        type="button"
                        class="rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset"
                        :class="b.id === board.id
                            ? 'bg-indigo-600 text-white ring-indigo-600'
                            : 'bg-white text-gray-700 ring-gray-300'"
                        @click="switchBoard(b.id)"
                    >
                        {{ b.code }}
                    </button>
                </div>

                <div v-if="!chapters.length" class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
                    No chapters in syllabus for this class / board.
                </div>

                <div v-for="chapter in chapters" :key="chapter.id" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-gray-900">{{ chapter.name }}</h3>
                            <p class="text-xs text-gray-500">
                                {{ chapter.formulas_count }} cards · {{ chapter.sets_count }} sets · {{ chapter.topics_count }} topics
                            </p>
                        </div>
                    </div>

                <div class="divide-y divide-gray-100 rounded-md border border-gray-100">
                        <Link
                            v-for="topic in chapter.topics"
                            :key="topic.id"
                            :href="route('admin.formula-bank.topics.show', topic.id)"
                            class="flex items-center justify-between gap-3 px-3 py-2.5 text-sm hover:bg-indigo-50/50"
                        >
                            <span class="font-medium text-gray-800">{{ topic.name }}</span>
                            <span class="shrink-0 text-xs text-gray-500">
                                {{ topic.formulas_count }} cards · {{ topic.sets_count }} sets
                            </span>
                        </Link>
                    </div>
                    <div class="mt-3">
                        <Link
                            :href="route('admin.formula-bank.chapters.show', chapter.id)"
                            class="text-xs font-medium text-indigo-600 hover:underline"
                        >
                            Open chapter formula bank →
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
