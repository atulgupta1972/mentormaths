<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    classCoverage: {
        type: Object,
        default: () => ({ chapters: [], under_study_chapter_id: null }),
    },
});

const savingId = ref(null);

const chapters = computed(() => props.classCoverage?.chapters ?? []);

const mark = (chapterId, status) => {
    if (savingId.value) {
        return;
    }

    savingId.value = chapterId;

    router.put(route('student.class-coverage.update', chapterId), {
        status,
    }, {
        preserveScroll: true,
        onFinish: () => {
            savingId.value = null;
        },
    });
};

const chapterShortLabel = (chapter) => {
    const number = String(chapter.chapter_number ?? '').trim();

    if (number) {
        return number.startsWith('Ch') || number.startsWith('ch') ? number : `Ch ${number}`;
    }

    return chapter.name;
};
</script>

<template>
    <section class="w-fit max-w-full">
        <h3 class="mb-1 text-xs font-semibold text-slate-800">Topics already covered in class</h3>
        <p class="mb-1.5 text-[10px] leading-tight text-slate-500">
            Tick under study — earlier chapters move to studied.
        </p>

        <div v-if="!chapters.length" class="rounded border border-dashed border-slate-300 px-2 py-2 text-[11px] text-slate-600">
            No syllabus chapters for your class / board yet.
        </div>

        <div v-else class="overflow-x-auto rounded border border-slate-300">
            <table class="w-auto border-collapse text-[11px] leading-none">
                <thead>
                    <tr class="bg-[#0b2a5b] text-white">
                        <th class="whitespace-nowrap px-1.5 py-1 text-left font-semibold">Chapter</th>
                        <th class="whitespace-nowrap px-1.5 py-1 text-center font-semibold">Studied</th>
                        <th class="whitespace-nowrap px-1.5 py-1 text-center font-semibold">Under study</th>
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
                        <td class="whitespace-nowrap px-1.5 py-0.5 font-medium text-slate-800" :title="chapter.label || chapter.name">
                            {{ chapterShortLabel(chapter) }}
                        </td>
                        <td class="px-1.5 py-0.5 text-center">
                            <button
                                type="button"
                                class="inline-flex h-4 w-4 items-center justify-center rounded text-[10px] leading-none"
                                :class="chapter.studied
                                    ? 'bg-emerald-600 text-white'
                                    : 'bg-transparent text-slate-300 hover:text-emerald-600'"
                                :title="chapter.studied ? 'Marked studied' : 'Mark as studied'"
                                :disabled="savingId !== null"
                                @click="mark(chapter.id, 'studied')"
                            >
                                ✓
                            </button>
                        </td>
                        <td class="px-1.5 py-0.5 text-center">
                            <button
                                type="button"
                                class="inline-flex h-4 w-4 items-center justify-center rounded text-[10px] leading-none"
                                :class="chapter.under_study
                                    ? 'bg-amber-500 text-white'
                                    : 'bg-transparent text-slate-300 hover:text-amber-600'"
                                :title="chapter.under_study ? 'Currently under study' : 'Mark as under study'"
                                :disabled="savingId !== null"
                                @click="mark(chapter.id, 'under_study')"
                            >
                                ✓
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
