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
    const indexByHead = new Map();

    props.cards.forEach((card) => {
        const head = card.chapter_head || 'Not classified';
        if (!indexByHead.has(head)) {
            indexByHead.set(head, buckets.length);
            buckets.push({
                head,
                subs: [],
                classes: [],
            });
        }
        const group = buckets[indexByHead.get(head)];
        const subName = card.sub_heading || '';
        let sub = group.subs.find((item) => item.name === subName);
        if (!sub) {
            sub = { name: subName, methods: [], classes: [] };
            group.subs.push(sub);
        }
        sub.methods.push(card);
        (card.classes || []).forEach((name) => {
            if (!group.classes.includes(name)) {
                group.classes.push(name);
            }
            if (!sub.classes.includes(name)) {
                sub.classes.push(name);
            }
        });
    });

    return buckets;
});

const manySubs = (group) => group.subs.length > 1;
const headKey = (group) => `head\0${group.head}`;
const subKey = (group, sub) => `sub\0${group.head}\0${sub.name}`;
const isOpen = (key) => Boolean(openKeys.value[key]);
const toggle = (key) => {
    openKeys.value = {
        ...openKeys.value,
        [key]: !openKeys.value[key],
    };
};
const showSub = (group) => !manySubs(group) || isOpen(headKey(group));
const showCards = (group, sub) => showSub(group) && (sub.methods.length < 2 || isOpen(subKey(group, sub)));
const partCount = (methods) => methods.length;
const sumCount = (methods) => methods.reduce((total, card) => total + (card.exercises_count || 0), 0);
const headParts = (group) => group.subs.reduce((total, sub) => total + sub.methods.length, 0);
const headSums = (group) => group.subs.reduce((total, sub) => total + sumCount(sub.methods), 0);
</script>

<template>
    <Head title="MentorMaths methods (MMM)" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">MentorMaths methods (MMM)</h2>
        </template>

        <div class="mx-auto max-w-5xl space-y-4 p-4 sm:p-6">
            <p class="text-sm text-gray-600">
                Open a chapter head to see its sub headings. Open a sub heading to see each part.
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
                        <template v-for="group in groups" :key="group.head">
                            <tr
                                v-if="manySubs(group)"
                                class="cursor-pointer border-t border-slate-200 bg-slate-50 hover:bg-slate-100"
                                @click="toggle(headKey(group))"
                            >
                                <td class="px-3 py-1.5 font-semibold text-slate-900">{{ group.head }}</td>
                                <td class="px-3 py-1.5 text-slate-500">{{ group.subs.length }} sub headings</td>
                                <td class="px-3 py-1.5 text-slate-700">{{ headParts(group) }} parts</td>
                                <td class="px-3 py-1.5 text-xs text-teal-800">{{ group.classes.join(', ') }}</td>
                                <td class="px-3 py-1.5 tabular-nums text-slate-600">{{ headSums(group) }}</td>
                                <td class="px-3 py-1.5 text-right text-xs font-semibold text-slate-500">
                                    {{ isOpen(headKey(group)) ? 'Hide' : 'Show' }}
                                </td>
                            </tr>
                            <template v-for="sub in group.subs" :key="`${group.head}-${sub.name}`">
                                <tr
                                    v-if="showSub(group) && sub.methods.length > 1"
                                    class="cursor-pointer border-t border-slate-200 hover:bg-slate-100"
                                    :class="manySubs(group) ? 'bg-white' : 'bg-slate-50'"
                                    @click.stop="toggle(subKey(group, sub))"
                                >
                                    <td class="px-3 py-1.5" :class="manySubs(group) ? 'pl-6 text-slate-400' : 'font-semibold text-slate-900'">
                                        {{ manySubs(group) ? '' : group.head }}
                                    </td>
                                    <td class="px-3 py-1.5 font-medium text-indigo-800" :class="manySubs(group) ? 'pl-6' : ''">
                                        {{ sub.name || 'General' }}
                                    </td>
                                    <td class="px-3 py-1.5 text-slate-700">{{ partCount(sub.methods) }} parts</td>
                                    <td class="px-3 py-1.5 text-xs text-teal-800">
                                        {{ manySubs(group) ? '' : sub.classes.join(', ') }}
                                    </td>
                                    <td class="px-3 py-1.5 tabular-nums text-slate-600">{{ sumCount(sub.methods) }}</td>
                                    <td class="px-3 py-1.5 text-right text-xs font-semibold text-slate-500">
                                        {{ isOpen(subKey(group, sub)) ? 'Hide' : 'Show' }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="card in (showCards(group, sub) ? sub.methods : [])"
                                    :key="card.id"
                                    class="border-t border-slate-100"
                                >
                                    <td class="px-3 py-1.5 text-slate-500">
                                        {{ manySubs(group) || sub.methods.length > 1 ? '' : group.head }}
                                    </td>
                                    <td class="px-3 py-1.5 text-xs text-indigo-800" :class="manySubs(group) && sub.methods.length === 1 ? 'pl-6' : ''">
                                        {{ sub.methods.length > 1 ? '' : (sub.name || 'General') }}
                                    </td>
                                    <td
                                        class="px-3 py-1.5 font-medium text-slate-900"
                                        :class="sub.methods.length > 1 ? (manySubs(group) ? 'pl-10' : 'pl-6') : (manySubs(group) ? 'pl-6' : '')"
                                    >
                                        {{ card.heading }}
                                    </td>
                                    <td class="px-3 py-1.5 text-xs text-teal-800">
                                        {{ manySubs(group) || sub.methods.length > 1 ? '' : card.classes?.join(', ') }}
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
