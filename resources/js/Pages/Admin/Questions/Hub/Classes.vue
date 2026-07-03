<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    classes: Array,
    activeYear: Object,
});
</script>

<template>
    <Head title="Question Bank" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Question Bank</h2>
                <p v-if="activeYear" class="text-sm text-gray-500">{{ activeYear.name }} · Select a class to browse by chapter</p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl sm:px-6 lg:px-8">
                <BrowseModeNotice class="mb-6" />
                <p class="mb-6 text-sm text-gray-600">
                    Class → Chapter → Topic → Questions. Start by choosing a class.
                </p>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="klass in classes"
                        :key="klass.id"
                        :href="route('admin.questions.classes.show', klass.id)"
                        class="rounded-xl border bg-white p-6 shadow-sm transition hover:border-indigo-300 hover:shadow-md"
                    >
                        <h3 class="text-xl font-bold text-gray-900">{{ klass.name }}</h3>
                        <dl class="mt-4 grid grid-cols-3 gap-3 text-sm">
                            <div>
                                <dt class="text-gray-500">Chapters</dt>
                                <dd class="font-semibold text-gray-900">{{ klass.chapters_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Topics</dt>
                                <dd class="font-semibold text-gray-900">{{ klass.topics_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Questions</dt>
                                <dd class="font-semibold text-indigo-600">{{ klass.questions_count }}</dd>
                            </div>
                        </dl>
                        <p v-if="!klass.has_syllabus" class="mt-4 text-xs text-amber-700">Syllabus not imported</p>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
