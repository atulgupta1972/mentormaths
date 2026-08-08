<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    classCoverage: {
        type: Object,
        default: () => ({ chapters: [], under_study_chapter_id: null }),
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

const chapters = computed(() => props.classCoverage?.chapters ?? []);

const mark = (chapterId, status) => {
    if (savingId.value) {
        return;
    }

    savingId.value = chapterId;

    const params = {
        ...props.updateRouteParams,
        syllabusChapter: chapterId,
    };

    router.put(route(props.updateRouteName, params), {
        status,
    }, {
        preserveScroll: true,
        onFinish: () => {
            savingId.value = null;
        },
    });
};

const chapterTitle = (chapter) => {
    const number = String(chapter.chapter_number ?? '').trim();
    const name = chapter.name || '';

    if (! number) {
        return name || chapter.label || 'Chapter';
    }

    const prefix = number.toLowerCase().startsWith('ch') ? number : `Ch ${number}`;

    return name ? `${prefix} — ${name}` : prefix;
};
</script>

<template>
    <section class="w-full max-w-3xl">
        <h3 class="mb-1 text-sm font-semibold text-slate-800">Topics already covered in class</h3>
        <p class="mb-2 text-xs leading-snug text-slate-500">
            Tick under study — earlier chapters move to studied.
        </p>

        <div v-if="!chapters.length" class="rounded border border-dashed border-slate-300 px-3 py-3 text-xs text-slate-600">
            No syllabus chapters for your class / board yet.
        </div>

        <div v-else class="overflow-x-auto rounded border border-slate-300">
            <table class="w-full table-fixed border-collapse text-xs leading-snug">
                <colgroup>
                    <col class="w-[28%]">
                    <col class="w-[52%]">
                    <col class="w-[10%]">
                    <col class="w-[10%]">
                </colgroup>
                <thead>
                    <tr class="bg-[#0b2a5b] text-white">
                        <th class="px-2 py-1.5 text-left font-semibold">Chapter</th>
                        <th class="px-2 py-1.5 text-left font-semibold">Topics</th>
                        <th class="px-1.5 py-1.5 text-center font-semibold">Studied</th>
                        <th class="px-1.5 py-1.5 text-center font-semibold">Under study</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(chapter, index) in chapters"
                        :key="chapter.id"
                        :class="[
                            index % 2 === 0 ? 'bg-white' : 'bg-slate-100',
                            savingId === chapter.id ? 'opacity-60' : '',
                        ]"
                    >
                        <td class="px-2 py-1 align-top font-medium text-slate-900">
                            {{ chapterTitle(chapter) }}
                        </td>
                        <td class="px-2 py-1 align-top text-[13px] text-slate-700">
                            <span v-if="chapter.topics_label">{{ chapter.topics_label }}</span>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="px-1.5 py-1 text-center align-top">
                            <button
                                type="button"
                                class="inline-flex h-5 w-5 items-center justify-center rounded border-2 text-[11px] font-bold leading-none"
                                :class="chapter.studied
                                    ? 'border-emerald-700 bg-emerald-600 text-white'
                                    : 'border-slate-400 bg-white text-transparent hover:border-emerald-500'"
                                :title="chapter.studied ? 'Marked studied' : 'Mark as studied'"
                                :aria-pressed="chapter.studied ? 'true' : 'false'"
                                :disabled="savingId !== null"
                                @click="mark(chapter.id, 'studied')"
                            >
                                <span v-if="chapter.studied">✓</span>
                            </button>
                        </td>
                        <td class="px-1.5 py-1 text-center align-top">
                            <button
                                type="button"
                                class="inline-flex h-5 w-5 items-center justify-center rounded border-2 text-[11px] font-bold leading-none"
                                :class="chapter.under_study
                                    ? 'border-amber-600 bg-amber-500 text-white'
                                    : 'border-slate-400 bg-white text-transparent hover:border-amber-500'"
                                :title="chapter.under_study ? 'Currently under study' : 'Mark as under study'"
                                :aria-pressed="chapter.under_study ? 'true' : 'false'"
                                :disabled="savingId !== null"
                                @click="mark(chapter.id, 'under_study')"
                            >
                                <span v-if="chapter.under_study">✓</span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
