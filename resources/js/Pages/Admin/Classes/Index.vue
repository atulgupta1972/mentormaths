<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BrowseModeNotice from '@/Components/BrowseModeNotice.vue';
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    classes: Array,
    activeYear: Object,
});

const isAdmin = computed(() => usePage().props.auth?.isAdmin ?? false);
</script>

<template>
    <Head title="Classes" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Classes</h2>
                <p v-if="activeYear" class="text-sm text-gray-500">{{ activeYear.name }} · CBSE Mathematics</p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl sm:px-6 lg:px-8">
                <BrowseModeNotice class="mb-6" />
                <p class="mb-6 text-sm text-gray-600">
                    <template v-if="isAdmin">
                        Select a class to manage syllabus topics, question bank, practice sets, and student assignments.
                    </template>
                    <template v-else>
                        Browse all classes — see syllabus coverage, topics, questions, and practice sets available on the platform.
                    </template>
                </p>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="klass in classes"
                        :key="klass.id"
                        :href="route('admin.classes.show', klass.id)"
                        class="rounded-xl border bg-white p-6 shadow-sm transition hover:border-indigo-300 hover:shadow-md"
                    >
                        <h3 class="text-xl font-bold text-gray-900">{{ klass.name }}</h3>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-gray-500">Students</dt>
                                <dd class="font-semibold text-gray-900">{{ klass.students_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Topics</dt>
                                <dd class="font-semibold text-gray-900">{{ klass.topics_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Questions</dt>
                                <dd class="font-semibold text-gray-900">{{ klass.questions_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Practice sets</dt>
                                <dd class="font-semibold text-gray-900">{{ klass.practice_sets_count }}</dd>
                            </div>
                        </dl>
                        <p v-if="!klass.has_syllabus" class="mt-4 text-xs text-amber-700">
                            Syllabus not imported yet
                        </p>
                        <p v-else class="mt-4 text-xs text-green-700">
                            {{ klass.chapters_count }} chapters in syllabus
                        </p>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
