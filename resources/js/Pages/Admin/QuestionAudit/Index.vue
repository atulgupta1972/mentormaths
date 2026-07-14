<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';

defineProps({
    boardSections: { type: Array, default: () => [] },
    activeYear: Object,
});

const classUrl = (boardId, gradeId) =>
    `${route('admin.question-audit.classes.show', gradeId)}?board_id=${boardId}`;

const boardBadgeClass = (code) => {
    if (code === 'ICSE') return 'bg-violet-100 text-violet-800 ring-violet-200';
    if (code === 'CBSE') return 'bg-sky-100 text-sky-800 ring-sky-200';
    return 'bg-gray-100 text-gray-800 ring-gray-200';
};
</script>

<template>
    <Head title="Answer audit" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Answer audit</h2>
                <p v-if="activeYear" class="text-sm text-gray-500">
                    {{ activeYear.name }} · Class → Chapter → Set/Test → Run audit and fix answers
                </p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl sm:px-6 lg:px-8">
                <div
                    v-if="usePage().props.flash?.warning"
                    class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    {{ usePage().props.flash.warning }}
                </div>

                <div v-if="!boardSections.length" class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
                    No syllabus found yet. Import syllabus first from
                    <Link :href="route('admin.syllabus.index')" class="font-medium text-indigo-600 hover:underline">Syllabus</Link>.
                </div>

                <div v-for="section in boardSections" :key="section.id" class="mb-10">
                    <div class="mb-4 flex flex-wrap items-center gap-3">
                        <span
                            class="rounded-full px-3 py-1 text-sm font-semibold ring-1 ring-inset"
                            :class="boardBadgeClass(section.code)"
                        >
                            {{ section.code }}
                        </span>
                        <h3 class="text-lg font-semibold text-gray-900">{{ section.name }}</h3>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="klass in section.classes"
                            :key="`${section.id}-${klass.id}`"
                            :href="classUrl(section.id, klass.id)"
                            class="rounded-xl border bg-white p-6 shadow-sm transition hover:border-indigo-300 hover:shadow-md"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <h4 class="text-xl font-bold text-gray-900">{{ klass.name }}</h4>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-gray-600">
                                    {{ section.code }}
                                </span>
                            </div>
                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt class="text-gray-500">Chapters</dt>
                                    <dd class="font-semibold text-gray-900">{{ klass.chapters_count }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Sets / tests</dt>
                                    <dd class="font-semibold text-indigo-600">{{ klass.sets_count }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Not audited</dt>
                                    <dd class="font-semibold text-amber-700">{{ klass.not_audited }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">With issues</dt>
                                    <dd class="font-semibold text-red-700">{{ klass.issues }}</dd>
                                </div>
                            </dl>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
