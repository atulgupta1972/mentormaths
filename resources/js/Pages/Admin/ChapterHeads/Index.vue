<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    chapterHeads: Array,
    activeYear: Object,
});

const form = useForm({ name: '' });

const submit = () => {
    form.post(route('admin.chapter-heads.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Chapter heads" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Chapter heads</h2>
                    <p class="text-sm text-gray-500">
                        Group chapters across classes — e.g. Integer, Coordinate Geometry, Trigonometry
                        <span v-if="activeYear"> · {{ activeYear.name }}</span>
                    </p>
                </div>
                <Link :href="route('admin.syllabus.index')" class="text-sm text-indigo-600">← Syllabus</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="font-medium text-gray-900">Add chapter head</h3>
                    <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="submit">
                        <div class="min-w-[240px] flex-1">
                            <InputLabel value="Name" />
                            <input
                                v-model="form.name"
                                type="text"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                placeholder="e.g. Integers"
                                required
                            />
                        </div>
                        <PrimaryButton :disabled="form.processing">Add</PrimaryButton>
                    </form>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapter head</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Topics (this year)</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="head in chapterHeads" :key="head.id">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ head.name }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ head.topics_count }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        :href="route('admin.chapter-heads.show', head.id)"
                                        class="text-indigo-600 hover:text-indigo-800"
                                    >
                                        View all classes →
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="chapterHeads.length === 0">
                                <td colspan="3" class="px-4 py-8 text-center text-gray-500">
                                    No chapter heads yet. Add names like Integers, Trigonometry, then tag chapters in the syllabus editor.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
