<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    class_name: { type: String, default: null },
    show_classes: { type: Boolean, default: false },
    groups: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Learn Methods" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Learn Methods</h2>
        </template>

        <div class="mx-auto max-w-4xl space-y-6 p-4 sm:p-6">
            <p class="text-sm text-gray-600">
                <template v-if="class_name">
                    Methods for {{ class_name }}. Open a method and work the sums one card at a time.
                </template>
                <template v-else-if="show_classes">
                    Every published method. A student sees a method when their class is ticked on it.
                </template>
                <template v-else>
                    Your class is not set yet, so no methods are listed.
                </template>
            </p>

            <p
                v-if="!groups.length"
                class="rounded-lg bg-white px-4 py-8 text-center text-sm text-slate-500 shadow-sm ring-1 ring-gray-200"
            >
                No methods for this class yet.
            </p>

            <section v-for="group in groups" :key="group.head" class="space-y-3">
                <h3 class="text-base font-semibold text-slate-900">{{ group.head }}</h3>

                <details
                    v-for="sub in group.subs"
                    :key="`${group.head}-${sub.name}`"
                    open
                    class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200"
                >
                    <summary class="cursor-pointer list-none bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800">
                        <span class="inline-flex items-center gap-2">
                            {{ sub.name }}
                            <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                {{ sub.methods.length }}
                            </span>
                        </span>
                    </summary>

                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                            <tr>
                                <th class="px-3 py-2">Heading</th>
                                <th class="px-3 py-2">About</th>
                                <th v-if="show_classes" class="px-3 py-2">Classes</th>
                                <th class="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="method in sub.methods"
                                :key="method.id"
                                class="border-t border-slate-100 align-top"
                            >
                                <td class="px-3 py-2 font-semibold text-slate-900">{{ method.heading }}</td>
                                <td class="px-3 py-2 text-slate-600">{{ method.about }}</td>
                                <td v-if="show_classes" class="px-3 py-2 text-slate-700">
                                    {{ method.classes?.length ? method.classes.join(', ') : 'None yet' }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <Link :href="method.open_url" class="text-sm font-semibold text-teal-700 hover:underline">
                                        Open
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </details>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
