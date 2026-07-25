<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({
    chapter: { type: Object, required: true },
    grade: { type: Object, required: true },
    board: { type: Object, required: true },
    topics: { type: Array, default: () => [] },
    formulas_count: { type: Number, default: 0 },
    sets_count: { type: Number, default: 0 },
});

const page = usePage();
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

                <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <p class="text-sm text-gray-600">
                        Pick a topic, then create Set 1 / Set 2 and import formula / concept MCQs (JSON from Cursor).
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
                            <p class="text-xs text-gray-500">Open to add formula sets and import MCQs</p>
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
