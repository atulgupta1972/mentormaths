<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    practiceSets: Array,
    selectedGrade: Object,
});

const setCode = ref('');

const lookupSet = () => {
    if (!setCode.value.trim()) {
        return;
    }

    router.get(route('admin.questions.set-code'), {
        code: setCode.value.trim().toUpperCase(),
    });
};
</script>

<template>
    <Head title="Practice Sets" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Practice Sets</h2>
                    <p v-if="selectedGrade" class="text-sm text-indigo-600">Showing: {{ selectedGrade.name }}</p>
                    <p v-else class="text-sm text-gray-500">All classes · use the class selector in the nav bar to filter</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="route('admin.practice-sets.create')"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        Create practice set
                    </Link>
                    <Link
                        :href="route('admin.catch-up.index')"
                        class="rounded-md border border-indigo-300 bg-white px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50"
                    >
                        Catch-up sets
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl sm:px-6 lg:px-8">
                <div class="mb-4 flex flex-wrap items-end gap-3">
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="lookupSet">
                        <div>
                            <label class="block text-xs font-medium uppercase text-gray-500">Look up set code</label>
                            <input
                                v-model="setCode"
                                type="text"
                                placeholder="SF121"
                                class="mt-1 rounded-md border-gray-300 font-mono uppercase text-sm shadow-sm"
                            >
                        </div>
                        <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            Review Q&amp;A
                        </button>
                    </form>
                    <Link :href="route('admin.classes.index')" class="text-sm text-indigo-600 hover:underline">
                        Browse by class →
                    </Link>
                </div>
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Code</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Class</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Set</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Topic</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Tier</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Sums</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="set in practiceSets" :key="set.id">
                                <td class="px-4 py-3 font-mono font-semibold text-indigo-600">
                                    <Link :href="route('admin.questions.sets.show', set.id)" class="hover:underline">
                                        {{ set.set_code || '—' }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    {{ set.topic?.chapter?.syllabus_version?.grade_level?.name || '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <Link :href="route('admin.practice-sets.show', set.id)" class="font-medium text-indigo-600">
                                        Set {{ set.set_number }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <Link
                                        v-if="set.topic"
                                        :href="route('admin.practice-sets.topics.show', set.syllabus_topic_id)"
                                        class="text-indigo-600 hover:underline"
                                    >
                                        {{ set.topic.chapter?.name }} — {{ set.topic.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-medium">{{ set.tier_label }}</span>
                                </td>
                                <td class="px-4 py-3">{{ set.questions_count }}</td>
                                <td class="px-4 py-3 capitalize">{{ set.status }}</td>
                            </tr>
                            <tr v-if="practiceSets.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                    No practice sets for this class yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
