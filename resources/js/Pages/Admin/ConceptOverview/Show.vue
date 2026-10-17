<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    chapter_head: { type: Object, required: true },
    year: { type: Object, default: null },
    years: { type: Array, default: () => [] },
    boards: { type: Array, default: () => [] },
    ladder: { type: Array, default: () => [] },
    compare_hint: { type: String, default: '' },
    can_manage: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({}) },
    back_url: { type: String, required: true },
});

const yearId = ref(props.filters.year || '');

const applyYear = () => {
    router.get(route('admin.concept-overview.show', props.chapter_head.id), {
        year: yearId.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const statusClass = (status) => ({
    // Concept available — dark blue card, white letters
    ready: 'border-[#0b2a5b] bg-[#0b2a5b] text-white',
    draft: 'border-amber-300 bg-amber-50 text-amber-950',
    practice_only: 'border-sky-300 bg-sky-50 text-sky-950',
    to_upload: 'border-rose-200 bg-rose-50 text-rose-950',
    empty: 'border-slate-200 bg-slate-50 text-slate-500',
}[status] || 'border-slate-200 bg-slate-50');
</script>

<template>
    <Head :title="`${chapter_head.name} · Concept map`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link :href="back_url" class="text-sm font-medium text-teal-700 hover:text-teal-900">
                        ← Concept overview
                    </Link>
                    <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ chapter_head.name }}</h2>
                    <p v-if="chapter_head.branch_label" class="mt-1 text-sm font-medium text-teal-800">
                        Branch · {{ chapter_head.branch_label }}
                    </p>
                    <p class="mt-1 max-w-2xl text-sm text-gray-600">
                        {{ compare_hint }}
                    </p>
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Year</label>
                    <select v-model="yearId" class="mt-1 block rounded-lg border-slate-300 text-sm" @change="applyYear">
                        <option v-for="y in years" :key="y.id" :value="y.id">
                            {{ y.name }}{{ y.is_active ? ' · active' : '' }}
                        </option>
                    </select>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-8 px-4 sm:px-6 lg:px-8">
                <p v-if="!ladder.length" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    No syllabus chapters use this chapter head yet.
                </p>

                <ol class="relative space-y-6 border-l-2 border-teal-200 pl-6">
                    <li
                        v-for="(rung, index) in ladder"
                        :key="rung.class_number"
                        class="relative"
                    >
                        <span
                            class="absolute -left-[1.9rem] flex h-7 w-7 items-center justify-center rounded-full border-2 border-teal-600 bg-white text-xs font-bold text-teal-800"
                        >
                            {{ rung.class_number }}
                        </span>

                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-lg font-semibold text-slate-900">
                                    {{ rung.class_name }}
                                    <span v-if="index < ladder.length - 1" class="ml-2 text-sm font-normal text-slate-500">
                                        → next: Class {{ ladder[index + 1].class_number }}
                                    </span>
                                </h3>
                                <span
                                    class="rounded-full border px-2.5 py-0.5 text-xs font-semibold"
                                    :class="statusClass(rung.best_status)"
                                >
                                    {{ rung.best_status === 'to_upload' ? 'To be uploaded' : rung.best_status.replace('_', ' ') }}
                                </span>
                            </div>

                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                <article
                                    v-for="board in rung.boards"
                                    :key="board.board_code + '-' + board.chapter_id"
                                    class="rounded-xl border p-4"
                                    :class="statusClass(board.status)"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-wide opacity-70">
                                                {{ board.board_code }}
                                            </p>
                                            <p class="mt-1 font-semibold">
                                                <span v-if="board.chapter_number">Ch {{ board.chapter_number }} · </span>
                                                {{ board.chapter_name }}
                                            </p>
                                            <p class="mt-1 text-xs opacity-80">{{ board.status_label }}</p>
                                        </div>
                                        <p v-if="board.practice_sets" class="text-xs font-medium">
                                            {{ board.practice_sets }} set{{ board.practice_sets === 1 ? '' : 's' }}
                                        </p>
                                    </div>

                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <a
                                            v-if="board.play_url"
                                            :href="board.play_url"
                                            class="rounded-md px-3 py-1.5 text-xs font-semibold"
                                            :class="board.status === 'ready'
                                                ? 'bg-white text-[#0b2a5b] hover:bg-slate-100'
                                                : 'bg-teal-700 text-white hover:bg-teal-800'"
                                        >
                                            Run concepts
                                        </a>
                                        <a
                                            v-else-if="can_manage && board.concept_url"
                                            :href="board.concept_url"
                                            class="rounded-md bg-amber-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-800"
                                        >
                                            Open concept path
                                        </a>
                                        <span
                                            v-else
                                            class="rounded-md border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800"
                                        >
                                            {{ can_manage ? 'To be uploaded' : 'Not ready yet' }}
                                        </span>
                                        <a
                                            v-if="can_manage && board.syllabus_url"
                                            :href="board.syllabus_url"
                                            class="rounded-md border px-3 py-1.5 text-xs font-medium"
                                            :class="board.status === 'ready'
                                                ? 'border-white/40 bg-white/10 text-white hover:bg-white/20'
                                                : 'border-slate-300 bg-white/80 text-slate-700 hover:bg-white'"
                                        >
                                            Syllabus
                                        </a>
                                        <Link
                                            v-if="can_manage"
                                            :href="route('admin.concept-builder.index')"
                                            class="rounded-md border px-3 py-1.5 text-xs font-medium"
                                            :class="board.status === 'ready'
                                                ? 'border-white/40 bg-white/10 text-white hover:bg-white/20'
                                                : 'border-slate-300 bg-white/80 text-slate-700 hover:bg-white'"
                                        >
                                            Concept builder
                                        </Link>
                                    </div>

                                    <ul
                                        v-if="can_manage && board.topics?.length"
                                        class="mt-3 space-y-1 border-t pt-3"
                                        :class="board.status === 'ready' ? 'border-white/20' : 'border-black/5'"
                                    >
                                        <li
                                            v-for="topic in board.topics"
                                            :key="topic.id"
                                            class="flex items-center justify-between gap-2 text-xs"
                                        >
                                            <span>{{ topic.name }}</span>
                                            <a
                                                :href="topic.practice_url"
                                                class="font-semibold underline"
                                                :class="board.status === 'ready'
                                                    ? 'decoration-white/50 hover:decoration-white'
                                                    : 'decoration-teal-600/40 hover:decoration-teal-700'"
                                            >
                                                Practice sets
                                            </a>
                                        </li>
                                    </ul>
                                </article>
                            </div>
                        </div>
                    </li>
                </ol>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
