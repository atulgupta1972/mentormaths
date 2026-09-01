<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    uploaders: { type: Array, default: () => [] },
    gradeLevel: { type: Object, default: null },
    boards: { type: Array, default: () => [] },
    selectedBoard: { type: Object, default: null },
    selectedBoardId: { type: [Number, String, null], default: null },
    textbooks: { type: Array, default: () => [] },
    syllabusChapters: { type: Array, default: () => [] },
    bookChapterMapsByTextbook: { type: Object, default: () => ({}) },
    classDefaultRateInr: { type: Number, default: 0 },
    classDefaultRateBasis: { type: String, default: 'per_question' },
});

const page = usePage();
const setBoard = (boardId) => {
    router.get(route('admin.content-tasks.create'), {
        board_id: boardId || undefined,
    }, { replace: true });
};
const useNewBook = ref(props.textbooks.length === 0);
const selectedTextbookId = ref(props.textbooks[0]?.id ?? '');
const rateSectionRef = ref(null);
const showRateRequired = ref(false);

const form = useForm({
    assigned_to_user_id: props.uploaders[0]?.id ?? '',
    board_id: props.selectedBoardId ?? '',
    textbook_id: '',
    book_name: 'Ganita Prakash Part I',
    book_code: 'GP',
    chapter_maps: [],
    rate_basis: props.classDefaultRateBasis || 'per_question',
    offered_amount_inr: '',
    duplicate_override_reason: '',
    admin_notes: '',
});

const blankMapRow = (overrides = {}) => ({
    book_chapter_number: '',
    book_chapter_title: '',
    syllabus_chapter_id: '',
    assign: false,
    block_reason: '',
    default_amount_inr: 0,
    default_rate_basis: 'per_question',
    ...overrides,
});

const loadChapterMapsForTextbook = (textbookId) => {
    const saved = props.bookChapterMapsByTextbook?.[textbookId]
        ?? props.bookChapterMapsByTextbook?.[String(textbookId)]
        ?? [];

    if (saved.length) {
        form.chapter_maps = saved.map((row) => blankMapRow({
            book_chapter_number: row.book_chapter_number,
            book_chapter_title: row.book_chapter_title,
            syllabus_chapter_id: row.syllabus_chapter_id,
            assign: false,
            block_reason: row.block_reason || '',
            default_amount_inr: row.default_amount_inr,
            default_rate_basis: row.default_rate_basis,
        }));

        return;
    }

    form.chapter_maps = [blankMapRow()];
};

watch(
    () => [props.selectedBoardId, props.textbooks],
    () => {
        form.board_id = props.selectedBoardId ?? '';
        const books = props.textbooks;

        if (books.length === 0) {
            useNewBook.value = true;
            selectedTextbookId.value = '';
            form.chapter_maps = [blankMapRow()];

            return;
        }

        const stillValid = books.some((book) => Number(book.id) === Number(selectedTextbookId.value));
        if (!stillValid) {
            selectedTextbookId.value = books[0].id;
            useNewBook.value = false;
        }
    },
);

watch(selectedTextbookId, () => {
    if (useNewBook.value || !selectedTextbookId.value) {
        return;
    }

    loadChapterMapsForTextbook(selectedTextbookId.value);
}, { immediate: true });

watch(useNewBook, (isNew) => {
    if (isNew) {
        form.chapter_maps = [blankMapRow()];

        return;
    }

    if (selectedTextbookId.value) {
        loadChapterMapsForTextbook(selectedTextbookId.value);
    }
});

const isPerQuestion = computed(() => form.rate_basis === 'per_question');
const minRateInr = computed(() => (isPerQuestion.value ? 1 : 100));

const syllabusChapterById = computed(() => {
    const map = {};
    props.syllabusChapters.forEach((chapter) => {
        map[chapter.id] = chapter;
    });

    return map;
});

const assignedMapRows = computed(() => form.chapter_maps.filter((row) => row.assign));

const mapBlockReason = (row) => {
    if (row.block_reason) {
        return row.block_reason;
    }

    if (!row.syllabus_chapter_id) {
        return '';
    }

    const syllabus = syllabusChapterById.value[row.syllabus_chapter_id];
    if (!syllabus) {
        return '';
    }

    return '';
};

const addMapRow = () => {
    form.chapter_maps = [...form.chapter_maps, blankMapRow()];
};

const removeMapRow = (index) => {
    form.chapter_maps = form.chapter_maps.filter((_, rowIndex) => rowIndex !== index);
    if (!form.chapter_maps.length) {
        form.chapter_maps = [blankMapRow()];
    }
};

const toggleMapAssign = (index) => {
    const row = form.chapter_maps[index];
    if (!row || mapBlockReason(row)) {
        return;
    }

    row.assign = !row.assign;
};

const formatChapterRate = (chapter) => {
    const amount = Number(chapter.default_amount_inr);
    if (amount <= 0) {
        return '—';
    }

    if (chapter.default_rate_basis === 'per_question') {
        return `${formatInr(amount)}/Q`;
    }

    return formatInr(amount);
};

const formatInr = (amount) => (amount > 0 ? `₹${Number(amount).toLocaleString('en-IN')}` : '—');

const formatMapRate = (row) => {
    if (!row.syllabus_chapter_id) {
        return '—';
    }

    const syllabus = syllabusChapterById.value[row.syllabus_chapter_id];
    if (syllabus) {
        return formatChapterRate(syllabus);
    }

    const amount = Number(row.default_amount_inr);
    if (amount <= 0) {
        return '—';
    }

    if (row.default_rate_basis === 'per_question') {
        return `${formatInr(amount)}/Q`;
    }

    return formatInr(amount);
};

const selectedRatePreview = computed(() => {
    const selected = assignedMapRows.value
        .map((row) => syllabusChapterById.value[row.syllabus_chapter_id])
        .filter(Boolean);
    if (!selected.length) {
        return null;
    }

    const labels = [...new Set(selected.map((ch) => formatChapterRate(ch)).filter((label) => label !== '—'))];
    if (labels.length === 1) {
        return labels[0];
    }
    if (labels.length > 1) {
        return labels.join(', ');
    }

    return 'Set rates in matrix first';
});

const hasMatrixRate = computed(() => {
    if (!assignedMapRows.value.length) {
        return false;
    }

    return assignedMapRows.value.every((row) => {
        const syllabus = syllabusChapterById.value[row.syllabus_chapter_id];

        return syllabus ? Number(syllabus.default_amount_inr) > 0 : Number(row.default_amount_inr) > 0;
    });
});

const hasValidOverride = computed(() => {
    if (form.offered_amount_inr === '') {
        return false;
    }

    return Number(form.offered_amount_inr) >= minRateInr.value;
});

const hasValidRate = computed(() => hasValidOverride.value || hasMatrixRate.value);

const needsRateOverride = computed(() => assignedMapRows.value.length > 0 && !hasMatrixRate.value);

const canSubmit = computed(() => Boolean(form.assigned_to_user_id) && assignedMapRows.value.length > 0);

const submitBlockedReason = computed(() => {
    if (!form.assigned_to_user_id) {
        return 'Select a content uploader.';
    }

    if (assignedMapRows.value.length === 0) {
        return 'Map at least one book chapter to a syllabus chapter and tick Assign.';
    }

    if (!hasValidRate.value) {
        return isPerQuestion.value
            ? 'Enter a per-question rate (₹) — no rate is set in the matrix for the selected chapters.'
            : 'Enter a per-chapter rate (₹) — no rate is set in the matrix for the selected chapters.';
    }

    return '';
});

const submit = () => {
    if (!canSubmit.value || form.processing) {
        return;
    }

    if (!hasValidRate.value) {
        showRateRequired.value = true;
        form.setError(
            'offered_amount_inr',
            isPerQuestion.value
                ? 'Enter a per-question rate (₹) — no rate is set in the matrix for this class.'
                : 'Enter a per-chapter rate (₹) — no rate is set in the matrix for this class.',
        );
        rateSectionRef.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    showRateRequired.value = false;

    form.transform((data) => ({
        ...data,
        board_id: props.selectedBoardId || data.board_id || null,
        textbook_id: useNewBook.value ? null : selectedTextbookId.value,
        book_name: useNewBook.value ? data.book_name : null,
        book_code: useNewBook.value ? data.book_code : null,
        chapter_maps: data.chapter_maps.map((row) => ({
            book_chapter_number: row.book_chapter_number,
            book_chapter_title: row.book_chapter_title,
            syllabus_chapter_id: row.syllabus_chapter_id ? Number(row.syllabus_chapter_id) : null,
            assign: Boolean(row.assign),
        })),
        offered_amount_inr: data.offered_amount_inr === '' ? null : Number(data.offered_amount_inr),
    })).post(route('admin.content-tasks.store'));
};

watch(() => form.offered_amount_inr, () => {
    if (hasValidRate.value) {
        showRateRequired.value = false;
        form.clearErrors('offered_amount_inr');
    }
});

const applySuggestedRate = () => {
    form.rate_basis = props.classDefaultRateBasis || 'per_question';
    form.offered_amount_inr = props.classDefaultRateInr > 0
        ? props.classDefaultRateInr
        : (form.rate_basis === 'per_question' ? 2 : 5000);
    showRateRequired.value = false;
    form.clearErrors('offered_amount_inr');
};
</script>

<template>
    <Head title="Assign content chapters" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Assign chapters to uploader</h2>
                    <p class="text-sm text-gray-500">Class → board → textbook master → map book chapters to syllabus → assign to uploader.</p>
                </div>
                <Link
                    :href="route('admin.content-rate-cards.index')"
                    class="rounded-md border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-900 hover:bg-indigo-100"
                >
                    Open rate matrix →
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6">
                <div v-if="page.props.flash?.error" class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-950">
                    {{ page.props.flash.error }}
                </div>

                <div v-if="!gradeLevel" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
                    Select a class from the top bar first (e.g. Class 7), then return to this page.
                </div>

                <form v-else class="space-y-4 rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200" @submit.prevent="submit">
                    <div class="rounded-md bg-sky-50 px-4 py-3 text-sm text-sky-900">
                        Assigning for <strong>{{ gradeLevel.name }}</strong>
                        <span v-if="selectedBoard"> · <strong>{{ selectedBoard.code || selectedBoard.name }}</strong></span>.
                        Default rates come from the <Link :href="route('admin.content-rate-cards.index')" class="underline">rate matrix</Link>
                        (currently {{ classDefaultRateBasis === 'per_question' ? `${formatInr(classDefaultRateInr || 2)} per question` : `${formatInr(classDefaultRateInr || 5000)} per chapter` }}).
                    </div>

                    <div v-if="boards.length">
                        <InputLabel value="Board" />
                        <select
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                            :value="selectedBoardId || ''"
                            @change="setBoard($event.target.value ? Number($event.target.value) : '')"
                        >
                            <option v-for="board in boards" :key="board.id" :value="board.id">
                                {{ board.code }} — {{ board.name }}
                            </option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Syllabus chapters and books below are for this board only.
                        </p>
                        <InputError :message="form.errors.board_id" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel value="Content uploader" />
                        <select
                            v-model="form.assigned_to_user_id"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                            :disabled="uploaders.length === 0"
                        >
                            <option value="" disabled>Select content uploader</option>
                            <option v-for="u in uploaders" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Showing {{ uploaders.length }} user(s) with the Content uploader group
                            (not all mentors). Manage groups under
                            <Link :href="route('admin.users.index')" class="text-indigo-600 underline">People → Users</Link>.
                        </p>
                        <InputError :message="form.errors.assigned_to_user_id" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel value="Textbook master" />
                        <div v-if="textbooks.length" class="mt-2 space-y-2">
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="useNewBook" type="radio" :value="false" class="border-gray-300">
                                Choose existing book
                            </label>
                            <select
                                v-if="!useNewBook"
                                v-model="selectedTextbookId"
                                class="block w-full rounded-md border-gray-300 text-sm"
                            >
                                <option v-for="book in textbooks" :key="book.id" :value="book.id">{{ book.label }}</option>
                            </select>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="useNewBook" type="radio" :value="true" class="border-gray-300">
                                Add new book master
                            </label>
                        </div>
                        <div v-if="useNewBook || !textbooks.length" class="mt-2 grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Book name" />
                                <TextInput v-model="form.book_name" class="mt-1 block w-full" required />
                                <InputError :message="form.errors.book_name" class="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Book code" />
                                <TextInput v-model="form.book_code" class="mt-1 block w-full" required />
                                <p class="mt-1 text-xs text-gray-500">e.g. GP for Ganita Prakash</p>
                                <InputError :message="form.errors.book_code" class="mt-1" />
                            </div>
                        </div>
                        <InputError :message="form.errors.textbook_id" class="mt-1" />
                    </div>

                    <div v-if="syllabusChapters.length">
                        <InputLabel value="Book chapter mapping" />
                        <p class="mt-1 text-xs text-gray-500">
                            Step 1: Enter each chapter name as it appears in the book. Step 2: Map it to the matching syllabus chapter.
                            The uploader sees the <strong>book</strong> name; content is stored under the syllabus chapter.
                        </p>
                        <div class="mt-2 overflow-x-auto rounded-md border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                                    <tr>
                                        <th class="px-2 py-2 text-left">Assign</th>
                                        <th class="px-2 py-2 text-left">Book ch no</th>
                                        <th class="px-2 py-2 text-left">Book chapter name</th>
                                        <th class="px-2 py-2 text-left">Maps to syllabus</th>
                                        <th class="px-2 py-2 text-right">Rate</th>
                                        <th class="px-2 py-2" />
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr
                                        v-for="(row, index) in form.chapter_maps"
                                        :key="index"
                                        :class="mapBlockReason(row) ? 'bg-gray-50 text-gray-400' : ''"
                                    >
                                        <td class="px-2 py-2 align-top">
                                            <input
                                                type="checkbox"
                                                class="rounded border-gray-300"
                                                :disabled="Boolean(mapBlockReason(row)) || !row.syllabus_chapter_id || !row.book_chapter_number || !row.book_chapter_title"
                                                :checked="row.assign"
                                                @change="toggleMapAssign(index)"
                                            >
                                        </td>
                                        <td class="px-2 py-2 align-top">
                                            <input
                                                v-model="row.book_chapter_number"
                                                type="text"
                                                class="w-20 rounded-md border-gray-300 text-sm"
                                                placeholder="1"
                                            >
                                        </td>
                                        <td class="px-2 py-2 align-top">
                                            <input
                                                v-model="row.book_chapter_title"
                                                type="text"
                                                class="min-w-[10rem] rounded-md border-gray-300 text-sm"
                                                placeholder="Chapter title in book"
                                            >
                                        </td>
                                        <td class="px-2 py-2 align-top">
                                            <select
                                                v-model="row.syllabus_chapter_id"
                                                class="min-w-[14rem] rounded-md border-gray-300 text-sm"
                                            >
                                                <option value="">Select syllabus chapter…</option>
                                                <option
                                                    v-for="chapter in syllabusChapters"
                                                    :key="chapter.id"
                                                    :value="chapter.id"
                                                >
                                                    {{ chapter.label }}
                                                </option>
                                            </select>
                                            <p v-if="mapBlockReason(row)" class="mt-1 text-xs text-amber-700">
                                                {{ mapBlockReason(row) }}
                                            </p>
                                        </td>
                                        <td class="px-2 py-2 align-top text-right text-xs text-gray-500">
                                            {{ formatMapRate(row) }}
                                        </td>
                                        <td class="px-2 py-2 align-top text-right">
                                            <button
                                                type="button"
                                                class="text-xs text-rose-600 hover:underline"
                                                @click="removeMapRow(index)"
                                            >
                                                Remove
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <button
                            type="button"
                            class="mt-2 text-sm font-medium text-indigo-600 hover:underline"
                            @click="addMapRow"
                        >
                            + Add book chapter row
                        </button>
                        <p v-if="selectedRatePreview" class="mt-2 text-xs text-gray-600">Matrix rate for selection: {{ selectedRatePreview }}</p>
                        <InputError :message="form.errors.chapter_maps" class="mt-1" />
                    </div>
                    <div v-else class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        No syllabus chapters found for {{ gradeLevel.name }}{{ selectedBoard ? ` · ${selectedBoard.code || selectedBoard.name}` : '' }}. Check academic year, board, and syllabus setup.
                    </div>

                    <div
                        v-if="needsRateOverride"
                        class="rounded-lg border-2 border-amber-400 bg-amber-50 px-4 py-4 text-sm text-amber-950"
                    >
                        <p class="text-base font-semibold">Step 1: Set payment rate (required)</p>
                        <p class="mt-2">
                            Chapters show <strong>—</strong> because no rate is configured yet. Choose per-question or per-chapter below, then enter the rate.
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <SecondaryButton type="button" @click="applySuggestedRate">
                                Use {{ classDefaultRateBasis === 'per_question' ? `${formatInr(classDefaultRateInr || 2)}/question` : formatInr(classDefaultRateInr || 5000) }}
                            </SecondaryButton>
                            <Link
                                :href="route('admin.content-rate-cards.index')"
                                class="inline-flex items-center rounded-md border border-amber-600 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-amber-900 hover:bg-amber-100"
                            >
                                Open rate matrix
                            </Link>
                        </div>
                    </div>

                    <div ref="rateSectionRef" class="space-y-3">
                        <div>
                            <InputLabel value="Payment basis" />
                            <div class="mt-2 flex flex-wrap gap-4 text-sm">
                                <label class="flex items-center gap-2">
                                    <input v-model="form.rate_basis" type="radio" value="per_question" class="border-gray-300">
                                    Per question (₹ × verified questions)
                                </label>
                                <label class="flex items-center gap-2">
                                    <input v-model="form.rate_basis" type="radio" value="per_set" class="border-gray-300">
                                    Per chapter / set (flat ₹)
                                </label>
                            </div>
                            <InputError :message="form.errors.rate_basis" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel
                                :value="needsRateOverride
                                    ? (isPerQuestion ? 'Rate override (₹ per question, required)' : 'Rate override (₹ per chapter, required)')
                                    : (isPerQuestion ? 'Rate override (₹ per question, optional — applies to all selected chapters)' : 'Rate override (₹ per chapter, optional — applies to all selected chapters)')"
                            />
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <input
                                    v-model="form.offered_amount_inr"
                                    type="number"
                                    :min="minRateInr"
                                    class="block w-full min-w-[12rem] flex-1 rounded-md text-sm"
                                    :class="showRateRequired || form.errors.offered_amount_inr
                                        ? 'border-amber-500 ring-2 ring-amber-300'
                                        : 'border-gray-300'"
                                    :placeholder="isPerQuestion
                                        ? (needsRateOverride ? 'Enter amount, e.g. 2' : 'Leave blank to use matrix per chapter')
                                        : (needsRateOverride ? 'Enter amount, e.g. 5000' : 'Leave blank to use matrix per chapter')"
                                >
                                <SecondaryButton
                                    v-if="needsRateOverride && !hasValidRate"
                                    type="button"
                                    class="shrink-0"
                                    @click="applySuggestedRate"
                                >
                                    Fill {{ isPerQuestion ? '₹2' : '₹5,000' }}
                                </SecondaryButton>
                            </div>
                            <p v-if="isPerQuestion" class="mt-1 text-xs text-gray-500">
                                Final pay = rate × number of verified questions when the chapter is published.
                            </p>
                            <InputError :message="form.errors.offered_amount_inr" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <InputLabel value="Duplicate override reason (only if re-assigning existing content)" />
                        <textarea v-model="form.duplicate_override_reason" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        <InputError :message="form.errors.duplicate_override_reason" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel value="Notes to uploader" />
                        <textarea v-model="form.admin_notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <PrimaryButton :disabled="form.processing || !canSubmit">
                            {{ form.processing ? 'Creating…' : 'Create assignment(s)' }}
                        </PrimaryButton>
                        <Link :href="route('admin.content-tasks.index')" class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700">Cancel</Link>
                    </div>
                    <p v-if="!canSubmit && !form.processing" class="text-sm text-amber-800">{{ submitBlockedReason }}</p>
                    <p v-else-if="needsRateOverride && !hasValidRate && !form.processing" class="text-sm text-amber-800">
                        Enter a rate override above, then click Create assignment(s).
                    </p>
                    <p v-if="form.processing" class="text-sm text-slate-600">Saving assignments and sending email…</p>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
