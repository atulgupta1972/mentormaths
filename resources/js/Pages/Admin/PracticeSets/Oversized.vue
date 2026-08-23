<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    sets: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ min_questions: 30, class_id: null, kind: 'all' }),
    },
    classOptions: { type: Array, default: () => [] },
    breakOptions: { type: Array, default: () => [] },
});

const page = usePage();

const filterForm = reactive({
    min_questions: props.filters.min_questions ?? 30,
    class_id: props.filters.class_id ? String(props.filters.class_id) : '',
    kind: props.filters.kind || 'all',
});

const rowModes = reactive({});
const splittingId = ref(null);

props.sets.forEach((set) => {
    rowModes[set.id] = 'half';
});

watch(
    () => props.sets,
    (sets) => {
        sets.forEach((set) => {
            if (!rowModes[set.id]) {
                rowModes[set.id] = 'half';
            }
        });
    },
    { deep: true },
);

const applyFilters = () => {
    router.get(route('admin.practice-sets.oversized'), {
        min_questions: Number(filterForm.min_questions) || 30,
        class_id: filterForm.class_id || undefined,
        kind: filterForm.kind || 'all',
    }, {
        preserveState: true,
        replace: true,
    });
};

const previewLabel = (set) => {
    const mode = rowModes[set.id] || 'half';
    const count = Number(set.questions_count || 0);
    let batch = mode === 'half' ? Math.ceil(count / 2) : Number(mode);
    batch = Math.max(5, batch);
    if (batch >= count) {
        return 'Choose a smaller size';
    }

    return `${Math.ceil(count / batch)} part(s) · up to ${batch} sums each`;
};

const splitSet = (set) => {
    if (!confirm(`Split ${set.set_code || set.title}?\n${previewLabel(set)}`)) {
        return;
    }

    splittingId.value = set.id;
    useForm({
        mode: rowModes[set.id] || 'half',
        min_questions: filterForm.min_questions,
        class_id: filterForm.class_id || null,
        kind: filterForm.kind,
    }).post(route('admin.practice-sets.oversized.split', set.id), {
        preserveScroll: true,
        onFinish: () => {
            splittingId.value = null;
        },
    });
};

const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);
</script>

<template>
    <Head title="Large sets" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <Link :href="route('admin.practice-sets.index')" class="text-sm text-indigo-600">← Practice sets</Link>
                <h2 class="mt-1 text-xl font-semibold text-gray-800">Large sets to split</h2>
                <p class="text-sm text-gray-500">
                    Tests, practice sets, and book-chapter sets with too many sums — break them into parts here.
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-4 sm:px-6 lg:px-8">
                <div v-if="flashSuccess" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {{ flashSuccess }}
                </div>
                <div v-if="flashError" class="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    {{ flashError }}
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
                        <div>
                            <label class="block text-xs font-medium uppercase text-gray-500">Class</label>
                            <select v-model="filterForm.class_id" class="mt-1 rounded-md border-gray-300 text-sm">
                                <option value="">All classes</option>
                                <option v-for="grade in classOptions" :key="grade.id" :value="String(grade.id)">
                                    {{ grade.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium uppercase text-gray-500">Kind</label>
                            <select v-model="filterForm.kind" class="mt-1 rounded-md border-gray-300 text-sm">
                                <option value="all">All sets</option>
                                <option value="test">Chapter tests</option>
                                <option value="practice">Practice (topic + chapter)</option>
                                <option value="topic">Topic sets</option>
                                <option value="chapter">Chapter sets</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium uppercase text-gray-500">Sums more than</label>
                            <input
                                v-model.number="filterForm.min_questions"
                                type="number"
                                min="1"
                                max="200"
                                class="mt-1 w-28 rounded-md border-gray-300 text-sm"
                            >
                        </div>
                        <PrimaryButton type="submit">Apply filters</PrimaryButton>
                    </form>
                    <p class="mt-2 text-xs text-gray-500">
                        {{ sets.length }} set(s) with more than {{ filters.min_questions }} sums, largest first.
                        Default break mode is <strong>split in half</strong>.
                    </p>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Sums</th>
                                <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Set</th>
                                <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Class / chapter</th>
                                <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Kind</th>
                                <th class="px-3 py-3 text-left text-xs uppercase text-gray-500">Break into</th>
                                <th class="px-3 py-3 text-right text-xs uppercase text-gray-500">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="set in sets" :key="set.id" class="align-top">
                                <td class="px-3 py-3">
                                    <span class="text-lg font-bold tabular-nums text-rose-700">{{ set.questions_count }}</span>
                                </td>
                                <td class="px-3 py-3">
                                    <Link
                                        :href="route('admin.practice-sets.show', set.id)"
                                        class="font-mono font-semibold text-indigo-600 hover:underline"
                                    >
                                        {{ set.set_code || '—' }}
                                    </Link>
                                    <p class="mt-0.5 text-xs text-gray-600">{{ set.title }}</p>
                                    <p v-if="set.topic_name" class="text-[11px] text-gray-400">{{ set.topic_name }}</p>
                                </td>
                                <td class="px-3 py-3 text-gray-700">
                                    <p class="font-medium">{{ set.class_name || '—' }}</p>
                                    <p class="text-xs text-gray-500">{{ set.chapter_label || '—' }}</p>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                                        {{ set.kind_label }}
                                    </span>
                                </td>
                                <td class="px-3 py-3">
                                    <select
                                        v-model="rowModes[set.id]"
                                        class="w-full min-w-[11rem] rounded-md border-gray-300 text-xs"
                                    >
                                        <option
                                            v-for="option in breakOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <p class="mt-1 text-[11px] text-indigo-700">{{ previewLabel(set) }}</p>
                                </td>
                                <td class="space-y-1 px-3 py-3 text-right">
                                    <PrimaryButton
                                        type="button"
                                        class="!px-3 !py-1 !text-xs"
                                        :disabled="splittingId === set.id"
                                        @click="splitSet(set)"
                                    >
                                        {{ splittingId === set.id ? 'Splitting…' : 'Split now' }}
                                    </PrimaryButton>
                                    <div>
                                        <Link
                                            :href="route('admin.questions.sets.show', set.id)"
                                            class="text-xs text-indigo-600 hover:underline"
                                        >
                                            Open questions
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="sets.length === 0">
                                <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                                    No sets above this threshold. Try a lower “Sums more than” value or All classes.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
