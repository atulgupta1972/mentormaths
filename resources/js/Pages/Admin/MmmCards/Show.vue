<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    card: { type: Object, required: true },
    chapter_heads: { type: Array, default: () => [] },
    boards: { type: Array, default: () => [] },
    grades: { type: Array, default: () => [] },
    picker: { type: Object, required: true },
    play_url: { type: String, required: true },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const boardId = ref(props.picker.board_id ? String(props.picker.board_id) : '');
const gradeId = ref(props.picker.grade_level_id ? String(props.picker.grade_level_id) : '');
const selected = ref([]);

watch(
    () => props.picker.chapters,
    () => {
        selected.value = [];
    },
);

const reloadPicker = () => {
    router.get(
        route('admin.mmm-cards.show', props.card.id),
        {
            board_id: boardId.value || undefined,
            grade_level_id: gradeId.value || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
};

const classify = useForm({
    chapter_head_id: props.card.chapter_head_id ?? '',
    sub_heading: props.card.sub_heading ?? '',
    heading: props.card.heading ?? '',
    method_blurb: props.card.method_blurb ?? '',
    grade_level_ids: [...(props.card.grade_level_ids ?? [])],
});

const gradeChecked = (id) => classify.grade_level_ids.map(Number).includes(Number(id));

const toggleGrade = (id) => {
    const key = Number(id);
    const ids = classify.grade_level_ids.map(Number);
    classify.grade_level_ids = ids.includes(key) ? ids.filter((value) => value !== key) : [...ids, key];
};

const saveClassify = () => {
    classify
        .transform((data) => ({
            ...data,
            chapter_head_id: data.chapter_head_id === '' || data.chapter_head_id === null
                ? null
                : Number(data.chapter_head_id),
        }))
        .put(route('admin.mmm-cards.classify', props.card.id), {
            preserveScroll: true,
        });
};

const linkForm = useForm({
    syllabus_chapter_ids: [],
    mode: 'add',
});

const linkSelected = () => {
    linkForm.syllabus_chapter_ids = selected.value.map((id) => Number(id));
    if (! linkForm.syllabus_chapter_ids.length) {
        return;
    }
    linkForm.post(route('admin.mmm-cards.sync-chapters', props.card.id), {
        preserveScroll: true,
    });
};

const unlink = (chapterId) => {
    if (! window.confirm('Unlink this chapter from the MMM card?')) {
        return;
    }
    router.delete(route('admin.mmm-cards.unlink-chapter', [props.card.id, chapterId]), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="card.heading" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">MMM card</p>
                    <h2 class="text-xl font-semibold text-gray-800">{{ card.heading }}</h2>
                </div>
                <div class="flex gap-2">
                    <Link :href="route('admin.mmm-cards.index')" class="text-sm text-gray-600 hover:underline">
                        All MMM cards
                    </Link>
                    <Link
                        :href="play_url"
                        class="rounded-md bg-teal-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-teal-800"
                    >
                        Preview play
                    </Link>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-4 p-4 sm:p-6">
            <p v-if="flashSuccess" class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                {{ flashSuccess }}
            </p>

            <section class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-teal-200">
                <h3 class="text-sm font-semibold text-gray-900">Classify this method</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Chapter head, the sub heading you type, the heading, and a short description. Tick every class that should see it in Learn Methods.
                    Status: <span class="font-semibold capitalize">{{ card.status }}</span>
                    · {{ card.exercises.length }} exercise(s)
                </p>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="font-medium text-slate-700">Chapter head</span>
                        <select v-model="classify.chapter_head_id" class="mt-1 w-full rounded border-slate-300 text-sm">
                            <option value="">Choose a chapter head</option>
                            <option v-for="head in chapter_heads" :key="head.id" :value="head.id">
                                {{ head.name }}
                            </option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium text-slate-700">Sub heading</span>
                        <input
                            v-model="classify.sub_heading"
                            type="text"
                            maxlength="120"
                            placeholder="Equation solving"
                            class="mt-1 w-full rounded border-slate-300 text-sm"
                        >
                    </label>
                    <label class="block text-sm sm:col-span-2">
                        <span class="font-medium text-slate-700">Heading</span>
                        <input
                            v-model="classify.heading"
                            type="text"
                            maxlength="120"
                            class="mt-1 w-full rounded border-slate-300 text-sm"
                        >
                    </label>
                    <label class="block text-sm sm:col-span-2">
                        <span class="font-medium text-slate-700">Short description</span>
                        <textarea
                            v-model="classify.method_blurb"
                            rows="3"
                            maxlength="2000"
                            class="mt-1 w-full rounded border-slate-300 text-sm"
                        />
                    </label>
                </div>

                <fieldset class="mt-3">
                    <legend class="text-sm font-medium text-slate-700">Classes</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <label
                            v-for="grade in grades"
                            :key="grade.id"
                            class="inline-flex items-center gap-1.5 rounded border px-2 py-1 text-sm"
                            :class="gradeChecked(grade.id) ? 'border-teal-600 bg-teal-50 text-teal-900' : 'border-slate-200 text-slate-700'"
                        >
                            <input
                                type="checkbox"
                                class="rounded border-slate-300 text-teal-700"
                                :checked="gradeChecked(grade.id)"
                                @change="toggleGrade(grade.id)"
                            >
                            {{ grade.name }}
                        </label>
                    </div>
                </fieldset>

                <div class="mt-3">
                    <PrimaryButton type="button" :disabled="classify.processing" @click="saveClassify">
                        Save classification
                    </PrimaryButton>
                </div>
            </section>

            <section class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <h3 class="text-sm font-semibold text-gray-900">Exercises</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Preview any sum to check the table without saving student progress.
                </p>
                <ul class="mt-2 space-y-2">
                    <li
                        v-for="(ex, index) in card.exercises"
                        :key="ex.id"
                        class="flex flex-wrap items-start justify-between gap-2 rounded border border-slate-200 px-3 py-2 text-sm"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="rounded bg-teal-50 px-1.5 py-px text-[10px] font-bold uppercase text-teal-800">
                                    Sum {{ ex.sort_order || (index + 1) }}
                                </span>
                                <span class="rounded bg-slate-100 px-1.5 py-px text-[10px] font-bold uppercase text-slate-700">
                                    {{ ex.is_demo ? 'Demo' : ex.difficulty }}
                                </span>
                            </div>
                            <p class="mt-1 text-slate-800">{{ ex.question_text }}</p>
                        </div>
                        <Link
                            :href="route('admin.mmm-cards.play', { mmmCard: card.id, exercise: index + 1 })"
                            class="shrink-0 rounded-md border border-teal-700 px-2.5 py-1 text-xs font-semibold text-teal-800 hover:bg-teal-50"
                        >
                            Preview card
                        </Link>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <h3 class="text-sm font-semibold text-gray-900">Linked chapters</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Pick board + class, tick chapters, then link. Same card can appear on Class 6 / 7 / 8 Integers across boards.
                </p>

                <div class="mt-3 flex flex-wrap gap-2">
                    <select
                        v-model="boardId"
                        class="rounded border-slate-300 text-sm"
                        @change="reloadPicker"
                    >
                        <option value="">Board</option>
                        <option v-for="b in boards" :key="b.id" :value="String(b.id)">
                            {{ b.code }} — {{ b.name }}
                        </option>
                    </select>
                    <select
                        v-model="gradeId"
                        class="rounded border-slate-300 text-sm"
                        @change="reloadPicker"
                    >
                        <option value="">Class</option>
                        <option v-for="g in grades" :key="g.id" :value="String(g.id)">
                            {{ g.name }}
                        </option>
                    </select>
                </div>

                <div v-if="picker.chapters?.length" class="mt-3 max-h-56 space-y-1 overflow-y-auto rounded border border-slate-200 p-2">
                    <label
                        v-for="ch in picker.chapters"
                        :key="ch.id"
                        class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50"
                    >
                        <input
                            v-model="selected"
                            type="checkbox"
                            :value="String(ch.id)"
                            class="rounded border-slate-300"
                        >
                        {{ ch.label }}
                    </label>
                </div>
                <p v-else-if="boardId && gradeId" class="mt-3 text-sm text-slate-500">
                    No chapters for that board / class.
                </p>

                <PrimaryButton
                    class="mt-3"
                    type="button"
                    :disabled="!selected.length || linkForm.processing"
                    @click="linkSelected"
                >
                    Link selected chapters
                </PrimaryButton>

                <ul class="mt-4 space-y-1">
                    <li
                        v-for="ch in card.linked_chapters"
                        :key="ch.id"
                        class="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-100 px-2 py-1.5 text-sm"
                    >
                        <span>
                            <span class="font-medium text-slate-900">{{ ch.label }}</span>
                            <span class="text-slate-500"> · {{ ch.board }} · {{ ch.class }}</span>
                        </span>
                        <SecondaryButton type="button" @click="unlink(ch.id)">Unlink</SecondaryButton>
                    </li>
                    <li v-if="!card.linked_chapters.length" class="text-sm text-slate-500">
                        Not linked to any chapter yet — students will not see this card until you link one.
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
