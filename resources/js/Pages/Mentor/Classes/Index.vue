<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    classes: { type: Array, default: () => [] },
    activeYear: { type: Object, default: null },
});
</script>

<template>
    <Head title="My classes" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">My classes</h2>
                <p v-if="activeYear" class="text-sm text-gray-500">
                    {{ activeYear.name }} · only students enrolled under you
                </p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl sm:px-6 lg:px-8">
                <p class="mb-6 text-sm text-gray-600">
                    Select a class to see student progress (completion %, score %, revision, login days).
                    You cannot see students from other mentors or classes.
                </p>

                <div v-if="!classes.length" class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600">
                    No classes or students linked to your mentor account yet.
                    Students who register under your coaching class will appear here.
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="klass in classes"
                        :key="`${klass.type}-${klass.id}`"
                        :href="route('mentor.classes.show', klass.id)"
                        class="rounded-xl border bg-white p-6 shadow-sm transition hover:border-teal-400 hover:shadow-md"
                    >
                        <h3 class="text-xl font-bold text-gray-900">{{ klass.name }}</h3>
                        <p v-if="klass.city" class="mt-1 text-sm text-gray-500">{{ klass.city }}</p>
                        <p v-if="klass.teacher_names?.length" class="mt-1 text-xs text-gray-500">
                            {{ klass.teacher_names.join(', ') }}
                        </p>
                        <dl class="mt-4 text-sm">
                            <div>
                                <dt class="text-gray-500">Students</dt>
                                <dd class="text-2xl font-bold text-teal-800">{{ klass.students_count }}</dd>
                            </div>
                        </dl>
                        <p class="mt-4 text-xs font-semibold text-teal-700">
                            Open student list →
                        </p>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
