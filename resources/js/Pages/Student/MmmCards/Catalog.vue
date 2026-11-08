<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    class_name: { type: String, default: null },
    show_classes: { type: Boolean, default: false },
    groups: { type: Array, default: () => [] },
});

const openKeys = ref({});

const keyFor = (head, sub) => `${head}\0${sub}`;
const isOpen = (head, sub) => Boolean(openKeys.value[keyFor(head, sub)]);
const toggle = (head, sub) => {
    const key = keyFor(head, sub);
    openKeys.value = {
        ...openKeys.value,
        [key]: !openKeys.value[key],
    };
};

const visibleMethods = (head, sub) => (
    sub.methods.length > 1 ? (isOpen(head, sub.name) ? sub.methods : []) : sub.methods
);

const classList = (methods) => {
    const names = [];
    methods.forEach((method) => {
        (method.classes || []).forEach((name) => {
            if (!names.includes(name)) {
                names.push(name);
            }
        });
    });
    return names.join(', ');
};
</script>

<template>
    <Head title="Learn Methods" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Learn Methods</h2>
        </template>

        <div class="mx-auto max-w-5xl space-y-4 p-4 sm:p-6">
            <p class="text-sm text-gray-600">
                <template v-if="class_name">Methods for {{ class_name }}. Open a sub heading to see its parts.</template>
                <template v-else-if="show_classes">Every published method, grouped by sub heading.</template>
                <template v-else>Your class is not set yet, so no methods are listed.</template>
            </p>

            <p
                v-if="!groups.length"
                class="rounded-lg bg-white px-4 py-8 text-center text-sm text-slate-500 shadow-sm ring-1 ring-gray-200"
            >
                No methods for this class yet.
            </p>

            <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-3 py-2">Chapter head</th>
                            <th class="px-3 py-2">Sub heading</th>
                            <th class="px-3 py-2">Method</th>
                            <th v-if="show_classes" class="px-3 py-2">Classes</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="group in groups" :key="group.head">
                            <template v-for="sub in group.subs" :key="`${group.head}-${sub.name}`">
                                <tr
                                    v-if="sub.methods.length > 1"
                                    class="cursor-pointer border-t border-slate-200 bg-slate-50 hover:bg-slate-100"
                                    @click="toggle(group.head, sub.name)"
                                >
                                    <td class="px-3 py-1.5 font-semibold text-slate-900">{{ group.head }}</td>
                                    <td class="px-3 py-1.5 font-medium text-indigo-800">{{ sub.name }}</td>
                                    <td class="px-3 py-1.5 text-slate-700">{{ sub.methods.length }} parts</td>
                                    <td v-if="show_classes" class="px-3 py-1.5 text-xs text-teal-800">
                                        {{ classList(sub.methods) }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right text-xs font-semibold text-slate-500">
                                        {{ isOpen(group.head, sub.name) ? 'Hide' : 'Show' }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="method in visibleMethods(group.head, sub)"
                                    :key="method.id"
                                    class="border-t border-slate-100"
                                >
                                    <td class="px-3 py-1.5 text-slate-500">{{ sub.methods.length > 1 ? '' : group.head }}</td>
                                    <td class="px-3 py-1.5 text-xs text-indigo-800">{{ sub.methods.length > 1 ? '' : sub.name }}</td>
                                    <td class="px-3 py-1.5" :class="sub.methods.length > 1 ? 'pl-6' : ''">
                                        <p class="font-medium text-slate-900">{{ method.heading }}</p>
                                        <p v-if="method.about" class="text-xs text-slate-500">{{ method.about }}</p>
                                    </td>
                                    <td v-if="show_classes" class="px-3 py-1.5 text-xs text-teal-800">
                                        {{ sub.methods.length > 1 ? '' : (method.classes?.join(', ') || 'None yet') }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right">
                                        <Link :href="method.open_url" class="text-sm font-semibold text-teal-700 hover:underline">
                                            Open
                                        </Link>
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
