<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    classCoverage: {
        type: Object,
        default: () => ({
            chapters: [],
            under_study_chapter_id: null,
            availability_columns: [],
        }),
    },
    updateRouteName: {
        type: String,
        default: 'student.class-coverage.update',
    },
    updateRouteParams: {
        type: Object,
        default: () => ({}),
    },
});

const savingId = ref(null);
const saveError = ref('');
const assigningWorksheetId = ref(null);
const expandedChapterIds = ref(new Set());

const chapters = computed(() => props.classCoverage?.chapters ?? []);
const availabilityColumns = computed(() => props.classCoverage?.availability_columns ?? []);
const isStudentView = computed(() => String(props.updateRouteName).startsWith('student.'));
const columnCount = computed(() => 4 + availabilityColumns.value.length);

const mark = (chapter, status) => {
    if (savingId.value) {
        return;
    }

    if (!route().has(props.updateRouteName)) {
        saveError.value = 'Save route is not available. Try refreshing the page.';

        return;
    }

    let nextStatus = status;
    if (status === 'studied' && chapter.studied) {
        nextStatus = 'none';
    } else if (status === 'under_study' && chapter.under_study) {
        nextStatus = 'none';
    } else if (status === 'studied' && chapter.under_study) {
        nextStatus = 'studied';
    } else if (status === 'under_study' && chapter.studied) {
        nextStatus = 'under_study';
    }

    savingId.value = chapter.id;
    saveError.value = '';

    const params = {
        ...props.updateRouteParams,
        syllabusChapter: chapter.id,
    };

    router.put(route(props.updateRouteName, params), {
        status: nextStatus,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            saveError.value = '';
        },
        onError: (errors) => {
            const first = Object.values(errors ?? {})[0];

            saveError.value = Array.isArray(first) ? first[0] : (first || 'Could not save. Please try again.');
        },
        onFinish: () => {
            savingId.value = null;
        },
    });
};

const toggleChapter = (chapterId) => {
    const next = new Set(expandedChapterIds.value);

    if (next.has(chapterId)) {
        next.delete(chapterId);
    } else {
        next.add(chapterId);
    }

    expandedChapterIds.value = next;
};

const isExpanded = (chapterId) => expandedChapterIds.value.has(chapterId);

const chapterTitle = (chapter) => {
    const number = String(chapter.chapter_number ?? '').trim();
    const name = chapter.name || '';

    if (! number) {
        return name || chapter.label || 'Chapter';
    }

    const prefix = number.toLowerCase().startsWith('ch') ? number : `Ch ${number}`;

    return name ? `${prefix} — ${name}` : prefix;
};

const availabilityCount = (chapter, key) => Number(chapter.availability?.[key] ?? 0);

const questionSuffix = (item) => {
    const count = Number(item.question_count ?? 0);

    return count > 0 ? ` (${count})` : '';
};

const statusClass = (status) => ({
    done: 'bg-emerald-100 text-emerald-900',
    checking: 'bg-amber-100 text-amber-900',
    overdue: 'bg-rose-100 text-rose-900',
    in_progress: 'bg-sky-100 text-sky-900',
    pending: 'bg-slate-100 text-slate-700',
    not_assigned: 'bg-slate-100 text-slate-600',
}[status] ?? 'bg-slate-100 text-slate-700');

const itemHref = (item) => {
    if (! isStudentView.value || ! item.can_open) {
        return null;
    }

    if (item.latest_attempt_id && route().has('student.attempts.show')) {
        return route('student.attempts.show', item.latest_attempt_id);
    }

    if (item.assignment_id && item.delivery_mode === 'written' && route().has('student.written-assignments.show')) {
        return route('student.written-assignments.show', item.assignment_id);
    }

    if (item.assignment_id && route().has('student.assignments.show')) {
        return route('student.assignments.show', item.assignment_id);
    }

    return null;
};

const selfAssign = (item) => {
    if (! isStudentView.value || ! item.worksheet_id || assigningWorksheetId.value) {
        return;
    }

    assigningWorksheetId.value = item.worksheet_id;

    router.post(route('student.worksheets.self-assign', item.worksheet_id), {}, {
        preserveScroll: true,
        onFinish: () => {
            assigningWorksheetId.value = null;
        },
    });
};
</script>

<template>
    <section class="w-full max-w-6xl">
        <h3 class="mb-1 text-sm font-semibold text-slate-800">Class coverage & available content</h3>
        <p class="mb-2 text-xs leading-snug text-slate-500">
            Click a chapter to see set details and scores. Tick each chapter independently — click the same box again to clear it.
        </p>
        <p v-if="saveError" class="mb-2 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-800">
            {{ saveError }}
        </p>

        <div v-if="!chapters.length" class="rounded border border-dashed border-slate-300 px-3 py-3 text-xs text-slate-600">
            No syllabus chapters for your class / board yet.
        </div>

        <div v-else class="overflow-x-auto rounded border border-slate-300">
            <table class="w-full min-w-[52rem] border-collapse text-xs leading-snug">
                <thead>
                    <tr class="bg-[#0b2a5b] text-white">
                        <th class="px-2 py-1.5 text-left font-semibold whitespace-nowrap">Chapter</th>
                        <th class="px-2 py-1.5 text-left font-semibold">Topics</th>
                        <th class="px-1.5 py-1.5 text-center font-semibold whitespace-nowrap">Studied</th>
                        <th class="px-1.5 py-1.5 text-center font-semibold whitespace-nowrap">Under study</th>
                        <th
                            v-for="column in availabilityColumns"
                            :key="column.key"
                            class="px-1 py-1.5 text-center font-semibold whitespace-nowrap"
                            :title="column.label"
                        >
                            {{ column.short }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="(chapter, index) in chapters" :key="chapter.id">
                        <tr
                            :class="[
                                index % 2 === 0 ? 'bg-white' : 'bg-slate-100',
                                isExpanded(chapter.id) ? 'bg-sky-50' : '',
                                savingId === chapter.id ? 'opacity-60' : '',
                            ]"
                        >
                            <td class="px-2 py-1 align-middle font-medium text-slate-900 whitespace-nowrap">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-left hover:text-sky-800"
                                    @click="toggleChapter(chapter.id)"
                                >
                                    <span class="text-[10px] text-sky-700">{{ isExpanded(chapter.id) ? '▼' : '▶' }}</span>
                                    <span>{{ chapterTitle(chapter) }}</span>
                                </button>
                            </td>
                            <td class="px-2 py-1 align-middle text-[13px] text-slate-700">
                                <span v-if="chapter.topics_label">{{ chapter.topics_label }}</span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-1.5 py-1 text-center align-middle">
                                <button
                                    type="button"
                                    class="inline-flex h-5 w-5 items-center justify-center rounded border-2 text-[11px] font-bold leading-none"
                                    :class="chapter.studied
                                        ? 'border-emerald-700 bg-emerald-600 text-white'
                                        : 'border-slate-400 bg-white hover:border-emerald-500'"
                                    :title="chapter.studied ? 'Marked studied — click to undo' : 'Mark as studied'"
                                    :aria-pressed="chapter.studied ? 'true' : 'false'"
                                    :disabled="savingId === chapter.id"
                                    @click.stop="mark(chapter, 'studied')"
                                >
                                    <span v-if="chapter.studied">✓</span>
                                </button>
                            </td>
                            <td class="px-1.5 py-1 text-center align-middle">
                                <button
                                    type="button"
                                    class="inline-flex h-5 w-5 items-center justify-center rounded border-2 text-[11px] font-bold leading-none"
                                    :class="chapter.under_study
                                        ? 'border-amber-600 bg-amber-500 text-white'
                                        : 'border-slate-400 bg-white hover:border-amber-500'"
                                    :title="chapter.under_study ? 'Currently under study — click to undo' : 'Mark as under study'"
                                    :aria-pressed="chapter.under_study ? 'true' : 'false'"
                                    :disabled="savingId === chapter.id"
                                    @click.stop="mark(chapter, 'under_study')"
                                >
                                    <span v-if="chapter.under_study">✓</span>
                                </button>
                            </td>
                            <td
                                v-for="column in availabilityColumns"
                                :key="`${chapter.id}-${column.key}`"
                                class="px-1 py-1 text-center align-middle"
                            >
                                <button
                                    type="button"
                                    class="inline-flex h-6 w-6 items-center justify-center rounded border text-[10px] font-semibold tabular-nums"
                                    :class="availabilityCount(chapter, column.key) > 0
                                        ? 'border-sky-600 bg-sky-500 text-white'
                                        : 'border-slate-300 bg-white text-slate-300'"
                                    :title="`${column.label}: ${availabilityCount(chapter, column.key) || 'none'}`"
                                    @click="toggleChapter(chapter.id)"
                                >
                                    {{ availabilityCount(chapter, column.key) > 0 ? availabilityCount(chapter, column.key) : '' }}
                                </button>
                            </td>
                        </tr>

                        <tr
                            v-if="isExpanded(chapter.id)"
                            :class="index % 2 === 0 ? 'bg-sky-50/80' : 'bg-sky-50'"
                        >
                            <td :colspan="columnCount" class="px-3 py-2">
                                <div v-if="!(chapter.items?.length)" class="text-[11px] text-slate-500">
                                    No practice / test content listed for this chapter yet.
                                </div>
                                <div v-else class="space-y-2">
                                    <div
                                        v-for="group in chapter.items"
                                        :key="`${chapter.id}-${group.key}`"
                                    >
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-700">
                                            {{ group.label }}
                                        </p>
                                        <div class="mt-0.5 flex flex-wrap gap-1.5">
                                            <div
                                                v-for="item in group.items"
                                                :key="`${group.key}-${item.worksheet_id}`"
                                                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-2 py-1 shadow-sm"
                                            >
                                                <span class="font-mono text-[11px] font-bold text-slate-900">
                                                    {{ item.short_label }}<span class="font-semibold text-slate-500">{{ questionSuffix(item) }}</span>
                                                </span>
                                                <span
                                                    class="rounded px-1.5 py-px text-[10px] font-bold uppercase"
                                                    :class="statusClass(item.status)"
                                                >
                                                    {{ item.status_label }}
                                                </span>
                                                <template v-if="isStudentView">
                                                    <button
                                                        v-if="item.can_assign"
                                                        type="button"
                                                        class="rounded bg-sky-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-sky-800 disabled:opacity-50"
                                                        :disabled="assigningWorksheetId === item.worksheet_id"
                                                        @click.stop="selfAssign(item)"
                                                    >
                                                        {{ item.status === 'done' ? 'Redo' : 'Assign me' }}
                                                    </button>
                                                    <Link
                                                        v-else-if="itemHref(item)"
                                                        :href="itemHref(item)"
                                                        class="rounded bg-emerald-700 px-1.5 py-px text-[9px] font-bold text-white hover:bg-emerald-800"
                                                        @click.stop
                                                    >
                                                        Open
                                                    </Link>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </section>
</template>
