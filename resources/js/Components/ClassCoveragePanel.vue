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
</script>

<template>
    <section class="rounded-xl border border-indigo-200 bg-gradient-to-br from-indigo-50 via-violet-50 to-white p-4 shadow-sm">
        <div class="mb-3">
            <h3 class="text-sm font-semibold text-indigo-950">Topics already covered in class</h3>
            <p class="mt-1 text-xs text-indigo-800">
                Tick the chapter you are studying now. Earlier chapters move to Studied automatically.
            </p>
        </div>

        <div v-if="!chapters.length" class="rounded-lg border border-dashed border-indigo-200 bg-white/70 px-4 py-6 text-center text-sm text-indigo-800">
            No syllabus chapters found for your class and board yet.
        </div>

        <div v-else class="overflow-x-auto rounded-lg bg-white ring-1 ring-indigo-100">
            <table class="min-w-full divide-y divide-indigo-100 text-sm">
                <thead class="bg-indigo-50/80 text-left text-xs uppercase tracking-wide text-indigo-700">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Chapter</th>
                        <th class="w-28 px-4 py-2.5 text-center font-semibold">Studied</th>
                        <th class="w-32 px-4 py-2.5 text-center font-semibold">Under study</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-indigo-50">
                    <tr
                        v-for="chapter in chapters"
                        :key="chapter.id"
                        class="hover:bg-indigo-50/40"
                        :class="{ 'opacity-60': savingId === chapter.id }"
                    >
                        <td class="px-4 py-2.5">
                            <p class="font-medium text-gray-900">{{ chapter.label || chapter.name }}</p>
                        </td>
                        <td class="px-4 py-2.5 text-center">
                            <button
                                type="button"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-full border text-base transition"
                                :class="chapter.studied
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700'
                                    : 'border-gray-200 bg-white text-transparent hover:border-emerald-300 hover:text-emerald-400'"
                                :title="chapter.studied ? 'Marked studied' : 'Mark as studied'"
                                :disabled="savingId !== null"
                                @click="mark(chapter.id, 'studied')"
                            >
                                ✓
                            </button>
                        </td>
                        <td class="px-4 py-2.5 text-center">
                            <button
                                type="button"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-full border text-base transition"
                                :class="chapter.under_study
                                    ? 'border-amber-400 bg-amber-50 text-amber-700 ring-2 ring-amber-200'
                                    : 'border-gray-200 bg-white text-transparent hover:border-amber-300 hover:text-amber-400'"
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
