<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    gradeLevel: Object,
    activeYear: Object,
    syllabusVersion: Object,
    assignableChapters: { type: Array, default: () => [] },
    studentsCount: { type: Number, default: 0 },
    gradeLevels: { type: Array, default: () => [] },
});

const page = usePage();

const defaultTargetDate = () => {
    const d = new Date();
    d.setDate(d.getDate() + 7);
    return d.toISOString().slice(0, 10);
};

const selectedChapterIds = ref([]);
const selectedWorksheetIds = ref([]);
const targetDate = ref(defaultTargetDate());
const notes = ref('');

const form = useForm({
    worksheet_ids: [],
    target_date: '',
    notes: '',
});

const visibleChapters = computed(() => {
    if (selectedChapterIds.value.length === 0) {
        return [];
    }

    return props.assignableChapters.filter((chapter) =>
        selectedChapterIds.value.includes(chapter.chapter_id),
    );
});

const allVisibleSets = computed(() => {
    const sets = [];

    visibleChapters.value.forEach((chapter) => {
        (chapter.topic_sets || []).forEach((set) => {
            sets.push({ ...set, chapter_id: chapter.chapter_id, chapter_label: chapter.chapter_label, type: 'practice' });
        });
        (chapter.chapter_tests || []).forEach((set) => {
            sets.push({ ...set, chapter_id: chapter.chapter_id, chapter_label: chapter.chapter_label, type: 'test' });
        });
    });

    return sets;
});

const selectedSets = computed(() =>
    allVisibleSets.value.filter((set) => selectedWorksheetIds.value.includes(set.id)),
);

const totalQuestions = computed(() =>
    selectedSets.value.reduce((sum, set) => sum + (set.questions_count || 0), 0),
);

const toggleChapter = (chapterId) => {
    const index = selectedChapterIds.value.indexOf(chapterId);

    if (index === -1) {
        selectedChapterIds.value.push(chapterId);
    } else {
        selectedChapterIds.value.splice(index, 1);
        const chapterSetIds = allSetsForChapter(chapterId).map((set) => set.id);
        selectedWorksheetIds.value = selectedWorksheetIds.value.filter((id) => !chapterSetIds.includes(id));
    }
};

const allSetsForChapter = (chapterId) => {
    const chapter = props.assignableChapters.find((item) => item.chapter_id === chapterId);

    if (!chapter) {
        return [];
    }

    return [...(chapter.topic_sets || []), ...(chapter.chapter_tests || [])];
};

const selectAllChapters = () => {
    selectedChapterIds.value = props.assignableChapters.map((chapter) => chapter.chapter_id);
};

const clearChapters = () => {
    selectedChapterIds.value = [];
    selectedWorksheetIds.value = [];
};

const toggleWorksheet = (worksheetId) => {
    const index = selectedWorksheetIds.value.indexOf(worksheetId);

    if (index === -1) {
        selectedWorksheetIds.value.push(worksheetId);
    } else {
        selectedWorksheetIds.value.splice(index, 1);
    }
};

const selectAllVisible = () => {
    selectedWorksheetIds.value = allVisibleSets.value.map((set) => set.id);
};

const selectPractice = () => {
    const practiceIds = allVisibleSets.value.filter((set) => set.type === 'practice').map((set) => set.id);
    selectedWorksheetIds.value = [...new Set([...selectedWorksheetIds.value, ...practiceIds])];
};

const selectTests = () => {
    const testIds = allVisibleSets.value.filter((set) => set.type === 'test').map((set) => set.id);
    selectedWorksheetIds.value = [...new Set([...selectedWorksheetIds.value, ...testIds])];
};

const clearSheets = () => {
    selectedWorksheetIds.value = [];
};

const switchClass = (gradeId) => {
    router.get(route('admin.classes.assign', gradeId));
};

const submit = () => {
    form.worksheet_ids = selectedWorksheetIds.value;
    form.target_date = targetDate.value;
    form.notes = notes.value;

    form.post(route('admin.classes.assign.store', props.gradeLevel.id), {
        preserveScroll: true,
    });
};

watch(
    () => props.gradeLevel.id,
    () => {
        selectedChapterIds.value = [];
        selectedWorksheetIds.value = [];
    },
);
</script>

<template>
    <Head :title="`Assign to ${gradeLevel.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm text-gray-500">
                        <Link :href="route('admin.classes.index')" class="text-indigo-600 hover:underline">Classes</Link>
                        · Class-wise assignment
                    </p>
                    <h2 class="text-xl font-semibold text-gray-800">Assign to {{ gradeLevel.name }}</h2>
                    <p v-if="activeYear && syllabusVersion" class="mt-1 text-sm text-gray-500">
                        {{ activeYear.name }} · {{ syllabusVersion.board?.code }} · {{ studentsCount }} active student{{ studentsCount === 1 ? '' : 's' }}
                    </p>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <InputLabel value="Class" class="!text-xs" />
                        <select
                            :value="gradeLevel.id"
                            class="mt-1 rounded-md border-gray-300 text-sm"
                            @change="switchClass(Number($event.target.value))"
                        >
                            <option v-for="grade in gradeLevels" :key="grade.id" :value="grade.id">
                                {{ grade.name }}
                            </option>
                        </select>
                    </div>
                    <Link
                        :href="route('admin.classes.show', gradeLevel.id)"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Class hub
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 p-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.warning" class="rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                    {{ page.props.flash.warning }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 p-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div v-if="!syllabusVersion" class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
                    No syllabus found for {{ gradeLevel.name }} in the active year. Import syllabus first.
                </div>

                <template v-else>
                    <div class="grid gap-6 lg:grid-cols-3">
                        <div class="space-y-6 lg:col-span-2">
                            <!-- Step 1: Chapters -->
                            <section class="rounded-xl border border-violet-200 bg-violet-50/40 p-5">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-sm font-semibold text-violet-900">1. Select chapters</h3>
                                        <p class="mt-1 text-xs text-violet-700">Tick the chapters you want to assign work from.</p>
                                    </div>
                                    <div class="flex gap-2">
                                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="selectAllChapters">
                                            All chapters
                                        </SecondaryButton>
                                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="clearChapters">
                                            Clear
                                        </SecondaryButton>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                                    <label
                                        v-for="chapter in assignableChapters"
                                        :key="chapter.chapter_id"
                                        class="flex cursor-pointer items-start gap-3 rounded-lg border bg-white px-3 py-3 transition"
                                        :class="selectedChapterIds.includes(chapter.chapter_id)
                                            ? 'border-violet-400 ring-1 ring-violet-200'
                                            : 'border-gray-200 hover:border-violet-200'"
                                    >
                                        <input
                                            type="checkbox"
                                            class="mt-0.5 rounded border-gray-300 text-violet-600"
                                            :checked="selectedChapterIds.includes(chapter.chapter_id)"
                                            @change="toggleChapter(chapter.chapter_id)"
                                        />
                                        <span>
                                            <span class="block text-sm font-medium text-gray-900">{{ chapter.chapter_label }}</span>
                                            <span class="mt-0.5 block text-xs text-gray-500">
                                                {{ (chapter.topic_sets || []).length }} practice · {{ (chapter.chapter_tests || []).length }} test{{ (chapter.chapter_tests || []).length === 1 ? '' : 's' }}
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </section>

                            <!-- Step 2: Sheets -->
                            <section class="rounded-xl border border-indigo-200 bg-indigo-50/30 p-5">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-sm font-semibold text-indigo-900">2. Select practice / tests</h3>
                                        <p class="mt-1 text-xs text-indigo-700">
                                            Choose one or more sheets from the selected chapters.
                                        </p>
                                    </div>
                                    <div v-if="visibleChapters.length" class="flex flex-wrap gap-2">
                                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="selectAllVisible">
                                            All visible
                                        </SecondaryButton>
                                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="selectPractice">
                                            All practice
                                        </SecondaryButton>
                                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="selectTests">
                                            All tests
                                        </SecondaryButton>
                                        <SecondaryButton type="button" class="!py-1.5 !text-xs" @click="clearSheets">
                                            Clear sheets
                                        </SecondaryButton>
                                    </div>
                                </div>

                                <p v-if="!visibleChapters.length" class="mt-4 text-sm text-gray-500">
                                    Select at least one chapter above to see available sheets.
                                </p>

                                <div v-else class="mt-4 space-y-4">
                                    <div
                                        v-for="chapter in visibleChapters"
                                        :key="chapter.chapter_id"
                                        class="rounded-lg border border-gray-200 bg-white p-4"
                                    >
                                        <h4 class="text-sm font-medium text-gray-900">{{ chapter.chapter_label }}</h4>

                                        <div v-if="!(chapter.topic_sets?.length || chapter.chapter_tests?.length)" class="mt-2 text-xs text-gray-400">
                                            No published sheets for this chapter yet.
                                        </div>

                                        <div v-if="chapter.topic_sets?.length" class="mt-3">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Topic practice</p>
                                            <ul class="mt-2 space-y-1">
                                                <li
                                                    v-for="set in chapter.topic_sets"
                                                    :key="set.id"
                                                >
                                                    <label class="flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 hover:bg-gray-50">
                                                        <input
                                                            type="checkbox"
                                                            class="rounded border-gray-300 text-indigo-600"
                                                            :checked="selectedWorksheetIds.includes(set.id)"
                                                            @change="toggleWorksheet(set.id)"
                                                        />
                                                        <span class="font-mono text-sm font-semibold text-indigo-600">{{ set.set_code }}</span>
                                                        <span class="text-xs text-gray-600">{{ set.topic_name }}</span>
                                                        <span class="text-xs text-gray-400">{{ set.tier_label }} · {{ set.questions_count }} Q</span>
                                                    </label>
                                                </li>
                                            </ul>
                                        </div>

                                        <div v-if="chapter.chapter_tests?.length" class="mt-3">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Chapter tests</p>
                                            <ul class="mt-2 space-y-1">
                                                <li
                                                    v-for="set in chapter.chapter_tests"
                                                    :key="set.id"
                                                >
                                                    <label class="flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 hover:bg-gray-50">
                                                        <input
                                                            type="checkbox"
                                                            class="rounded border-gray-300 text-indigo-600"
                                                            :checked="selectedWorksheetIds.includes(set.id)"
                                                            @change="toggleWorksheet(set.id)"
                                                        />
                                                        <span class="font-mono text-sm font-semibold text-indigo-600">{{ set.set_code }}</span>
                                                        <span class="text-xs text-gray-600">Chapter test</span>
                                                        <span class="text-xs text-gray-400">{{ set.questions_count }} Q</span>
                                                    </label>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <!-- Summary sidebar -->
                        <aside class="space-y-4">
                            <div class="sticky top-6 rounded-xl border border-emerald-200 bg-emerald-50/50 p-5">
                                <h3 class="text-sm font-semibold text-emerald-900">3. Assign & notify</h3>
                                <p class="mt-1 text-xs text-emerald-800">
                                    Assigns to all active students in {{ gradeLevel.name }}. WhatsApp opens for notify-enabled contacts.
                                </p>

                                <div class="mt-4 space-y-3">
                                    <div>
                                        <InputLabel value="Target date" />
                                        <input
                                            v-model="targetDate"
                                            type="date"
                                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                        />
                                    </div>
                                    <div>
                                        <InputLabel value="Note (optional)" />
                                        <textarea
                                            v-model="notes"
                                            rows="2"
                                            class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                            placeholder="e.g. Complete before school test"
                                        />
                                    </div>
                                </div>

                                <div class="mt-4 rounded-lg bg-white/80 p-3 text-sm">
                                    <p><span class="font-medium">{{ selectedSets.length }}</span> sheet{{ selectedSets.length === 1 ? '' : 's' }} selected</p>
                                    <p class="mt-1 text-gray-600">{{ totalQuestions }} questions total</p>
                                    <p class="mt-1 text-gray-600">{{ studentsCount }} student{{ studentsCount === 1 ? '' : 's' }} in class</p>
                                </div>

                                <PrimaryButton
                                    type="button"
                                    class="mt-4 w-full justify-center"
                                    :disabled="form.processing || !selectedWorksheetIds.length || !targetDate || !studentsCount"
                                    @click="submit"
                                >
                                    Assign & send WhatsApp
                                </PrimaryButton>

                                <p v-if="!studentsCount" class="mt-2 text-xs text-red-600">
                                    No active students in this class for the current year.
                                </p>
                            </div>
                        </aside>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
