<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    chapterHeads: Array,
    branchOptions: { type: Array, default: () => [] },
    activeYear: Object,
});

const form = useForm({ name: '', branch: 'algebra' });

const submit = () => {
    form.post(route('admin.chapter-heads.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('name'),
    });
};

const updateBranch = (head, branch) => {
    router.put(route('admin.chapter-heads.update', head.id), {
        name: head.name,
        branch,
    }, {
        preserveScroll: true,
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
                        Tag each head with a main branch (Algebra, Geometry, Mensuration…) for the concept map
                        <span v-if="activeYear"> · {{ activeYear.name }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 text-sm">
                    <Link :href="route('admin.concept-overview.index')" class="font-medium text-teal-700">Concept overview map →</Link>
                    <Link :href="route('admin.syllabus.index')" class="text-indigo-600">← Syllabus</Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="font-medium text-gray-900">Add chapter head</h3>
                    <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="submit">
                        <div class="min-w-[200px] flex-1">
                            <InputLabel value="Name" />
                            <input
                                v-model="form.name"
                                type="text"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                placeholder="e.g. Algebraic Expressions"
                                required
                            />
                        </div>
                        <div class="min-w-[180px]">
                            <InputLabel value="Branch" />
                            <select v-model="form.branch" class="mt-1 block w-full rounded-md border-gray-300">
                                <option v-for="opt in branchOptions" :key="opt.key" :value="opt.key">
                                    {{ opt.label }}
                                </option>
                            </select>
                        </div>
                        <PrimaryButton :disabled="form.processing">Add</PrimaryButton>
                    </form>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Chapter head</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Branch</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Topics</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="head in chapterHeads" :key="head.id">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ head.name }}</td>
                                <td class="px-4 py-3">
                                    <select
                                        class="rounded-md border-gray-300 text-sm"
                                        :value="head.branch"
                                        @change="updateBranch(head, $event.target.value)"
                                    >
                                        <option v-for="opt in branchOptions" :key="opt.key" :value="opt.key">
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ head.topics_count }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        :href="route('admin.chapter-heads.show', head.id)"
                                        class="text-indigo-600 hover:text-indigo-800"
                                    >
                                        View →
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="chapterHeads.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500">
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
