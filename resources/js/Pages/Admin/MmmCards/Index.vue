<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    cards: { type: Array, default: () => [] },
});

const openKeys = ref({});

const groups = computed(() => {
    const buckets = [];
    const indexByKey = new Map();

    props.cards.forEach((card) => {
        const head = card.chapter_head || 'Not classified';
        const sub = card.sub_heading || '';
        const key = `${head}\0${sub}`;
        if (!indexByKey.has(key)) {
            indexByKey.set(key, buckets.length);
            buckets.push({
                key,
                head,
                sub,
                methods: [],
                classes: [],
            });
        }
        const group = buckets[indexByKey.get(key)];
        group.methods.push(card);
        (card.classes || []).forEach((name) => {
            if (!group.classes.includes(name)) {
                group.classes.push(name);
            }
        });
    });

    return buckets;
});

const isBundle = (group) => group.methods.length > 1;
const isOpen = (group) => Boolean(openKeys.value[group.key]);
const visibleMethods = (group) => (isBundle(group) ? (isOpen(group) ? group.methods : []) : group.methods);
const toggle = (group) => {
    openKeys.value = {
        ...openKeys.value,
        [group.key]: !openKeys.value[group.key],
    };
};
</script>

<template>
    <Head title="MentorMaths methods (MMM)" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">MentorMaths methods (MMM)</h2>
        </template>

        <div class="mx-auto max-w-5xl space-y-4 p-4 sm:p-6">
            <p class="text-sm text-gray-600">
                Parts that share a sub heading sit on one row. Open that row to see each part.
            </p>

            <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-3 py-2">Chapter head</th>
                            <th class="px-3 py-2">Sub heading</th>
                            <th class="px-3 py-2">Method</th>
                            <th class="px-3 py-2">Classes</th>
                            <th class="px-3 py-2">Sums</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="group in groups" :key="group.key">
                            <tr
                                v-if="isBundle(group)"
                                class="cursor-pointer border-t border-slate-200 bg-slate-50 hover:bg-slate-100"
                                @click="toggle(group)"
                            >
                                <td class="px-3 py-1.5 font-semibold text-slate-900">{{ group.head }}</td>
                                <td class="px-3 py-1.5 font-medium text-indigo-800">{{ group.sub || 'General' }}</td>
                                <td class="px-3 py-1.5 text-slate-700">{{ group.methods.length }} parts</td>
                                <td class="px-3 py-1.5 text-xs text-teal-800">{{ group.classes.join(', ') }}</td>
                                <td class="px-3 py-1.5 tabular-nums text-slate-600">
                                    {{ group.methods.reduce((sum, card) => sum + card.exercises_count, 0) }}
                                </td>
                                <td class="px-3 py-1.5 text-right text-xs font-semibold text-slate-500">
                                    {{ isOpen(group) ? 'Hide' : 'Show' }}
                                </td>
                            </tr>
                            <tr
                                v-for="card in visibleMethods(group)"
                                :key="card.id"
                                class="border-t border-slate-100"
                            >
                                <td class="px-3 py-1.5 text-slate-500">{{ isBundle(group) ? '' : group.head }}</td>
                                <td class="px-3 py-1.5 text-xs text-indigo-800">{{ isBundle(group) ? '' : group.sub }}</td>
                                <td class="px-3 py-1.5 font-medium text-slate-900" :class="isBundle(group) ? 'pl-6' : ''">
                                    {{ card.heading }}
                                </td>
                                <td class="px-3 py-1.5 text-xs text-teal-800">
                                    {{ isBundle(group) ? '' : card.classes?.join(', ') }}
                                </td>
                                <td class="px-3 py-1.5 tabular-nums">{{ card.exercises_count }}</td>
                                <td class="px-3 py-1.5 text-right">
                                    <Link
                                        :href="route('admin.mmm-cards.show', card.id)"
                                        class="text-sm font-semibold text-teal-700 hover:underline"
                                        @click.stop
                                    >
                                        Open
                                    </Link>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!groups.length">
                            <td colspan="6" class="px-3 py-6 text-center text-slate-500">
                                No methods yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
