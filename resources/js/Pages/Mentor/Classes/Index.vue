<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    classes: { type: Array, default: () => [] },
    activeYear: { type: Object, default: null },
});
</script>

<template>
    <Head title="Classes" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Classes</h2>
                <p v-if="activeYear" class="text-sm text-gray-500">{{ activeYear.name }} · your students only</p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl sm:px-6 lg:px-8">
                <div class="mb-6 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-950">
                    Students appear here after they enrol and select your coaching institute or mentor.
                    You only see learners linked to you — never other mentors’ classes.
                </div>

                <p class="mb-6 text-sm text-gray-600">
                    Open a class to see student progress, then click a student to go to their study plan.
                </p>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <Link
                        v-for="klass in classes"
                        :key="klass.id"
                        :href="route('mentor.classes.show', klass.id)"
                        class="rounded-xl border bg-white p-6 shadow-sm transition hover:border-teal-400 hover:shadow-md"
                    >
                        <h3 class="text-xl font-bold text-gray-900">{{ klass.name }}</h3>
                        <dl class="mt-4 text-sm">
                            <div>
                                <dt class="text-gray-500">Your students</dt>
                                <dd class="text-2xl font-bold text-teal-800">{{ klass.students_count }}</dd>
                            </div>
                        </dl>
                        <p v-if="klass.has_syllabus" class="mt-3 text-xs text-green-700">
                            {{ klass.chapters_count }} chapters in syllabus
                        </p>
                        <p v-else class="mt-3 text-xs text-amber-700">
                            Syllabus not imported yet
                        </p>
                        <p class="mt-4 text-sm font-semibold text-teal-700">
                            View students →
                        </p>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
