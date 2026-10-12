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
const NEW_BOOK = '__new__';
const rateSectionRef = ref(null);
const showRateRequired = ref(false);
const chapterFilter = ref('');

const setBoard = (boardId) => {
    router.get(route('admin.content-tasks.create'), {
        board_id: boardId || undefined,
    }, { replace: true });
};

const form = useForm({
    assigned_to_user_id: props.uploaders[0]?.id ?? '',
    board_id: props.selectedBoardId ?? '',
    textbook_id: '',
    book_name: '',
    book_code: '',
    chapter_maps: [],
    rate_basis: props.classDefaultRateBasis || 'per_question',
    offered_amount_inr: '',
    duplicate_override_reason: '',
    admin_notes: '',
});

const bookSelect = ref(
    props.textbooks.length > 0 ? String(props.textbooks[0].id) : NEW_BOOK,
);

const useNewBook = computed(() => bookSelect.value === NEW_BOOK || props.textbooks.length === 0);

const selectedTextbookId = computed(() => (
    useNewBook.value ? null : Number(bookSelect.value)
));

const syllabusChapterById = computed(() => {
    const map = {};
    props.syllabusChapters.forEach((chapter) => {
        map[chapter.id] = chapter;
    });

    return map;
});

const savedMapsForTextbook = (textbookId) => {
    if (! textbookId) {
        return [];
    }

    return props.bookChapterMapsByTextbook?.[textbookId]
        ?? props.bookChapterMapsByTextbook?.[String(textbookId)]
        ?? [];
};

const blockReasonForChapter = (chapter, textbookId) => {
    if (! textbookId) {
        return '';
    }

    const tid = Number(textbookId);
    if ((chapter.uploaded_for_textbooks || []).map(Number).includes(tid)) {
        return 'Already uploaded for this book';
    }

    if ((chapter.assigned_for_textbooks || []).map(Number).includes(tid)) {
        return 'Already assigned for this book';
    }

    return '';
};

const rebuildChapterMaps = () => {
    const textbookId = selectedTextbookId.value;
    const saved = savedMapsForTextbook(textbookId);
    const savedBySyllabus = {};
    saved.forEach((row) => {
        if (row.syllabus_chapter_id) {
            savedBySyllabus[row.syllabus_chapter_id] = row;
        }
    });

    form.chapter_maps = props.syllabusChapters.map((chapter) => {
        const prior = savedBySyllabus[chapter.id];
        const blockReason = prior?.block_reason || blockReasonForChapter(chapter, textbookId);

        return {
            book_chapter_number: prior?.book_chapter_number || chapter.chapter_number || '',
            book_chapter_title: prior?.book_chapter_title || chapter.name || '',
            syllabus_chapter_id: chapter.id,
            assign: false,
            block_reason: blockReason,
            default_amount_inr: chapter.default_amount_inr,
            default_rate_basis: chapter.default_rate_basis,
            topics_label: chapter.topics_label || '',
            syllabus_label: chapter.label || chapter.name,
        };
    });
};

watch(
    () => [props.selectedBoardId, props.textbooks, props.syllabusChapters],
    () => {
        form.board_id = props.selectedBoardId ?? '';

        if (props.textbooks.length === 0) {
            bookSelect.value = NEW_BOOK;
        } else if (bookSelect.value !== NEW_BOOK) {
            const stillValid = props.textbooks.some((book) => String(book.id) === String(bookSelect.value));
            if (! stillValid) {
                bookSelect.value = String(props.textbooks[0].id);
            }
        }

        rebuildChapterMaps();
    },
    { immediate: true },
);

watch(bookSelect, () => {
    if (useNewBook.value) {
        form.book_name = form.book_name || '';
        form.book_code = form.book_code || '';
    }

    rebuildChapterMaps();
});

const isPerQuestion = computed(() => form.rate_basis === 'per_question');
const minRateInr = computed(() => (isPerQuestion.value ? 1 : 100));

const visibleChapterMaps = computed(() => {
    const needle = chapterFilter.value.trim().toLowerCase();
    if (! needle) {
        return form.chapter_maps.map((row, index) => ({ row, index }));
    }

    return form.chapter_maps
        .map((row, index) => ({ row, index }))
        .filter(({ row }) => {
            const haystack = [
                row.syllabus_label,
                row.book_chapter_title,
                row.book_chapter_number,
                row.topics_label,
            ].join(' ').toLowerCase();

            return haystack.includes(needle);
        });
});

const assignedMapRows = computed(() => form.chapter_maps.filter((row) => row.assign));

const assignableRows = computed(() => form.chapter_maps.filter((row) => ! row.block_reason));

const formatInr = (amount) => (amount > 0 ? `₹${Number(amount).toLocaleString('en-IN')}` : '—');

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

const formatMapRate = (row) => {
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
    if (! selected.length) {
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
    if (! assignedMapRows.value.length) {
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
const needsRateOverride = computed(() => assignedMapRows.value.length > 0 && ! hasMatrixRate.value);

const canSubmit = computed(() => {
    if (! form.assigned_to_user_id || assignedMapRows.value.length === 0) {
        return false;
    }

    if (useNewBook.value && (! form.book_name.trim() || ! form.book_code.trim())) {
        return false;
    }

    return true;
});

const submitBlockedReason = computed(() => {
    if (! form.assigned_to_user_id) {
        return 'Select a content uploader.';
    }

    if (useNewBook.value && ! form.book_name.trim()) {
        return 'Enter a book name for the new book master.';
    }

    if (useNewBook.value && ! form.book_code.trim()) {
        return 'Enter a book code for the new book master.';
    }

    if (assignedMapRows.value.length === 0) {
        return 'Tick at least one syllabus chapter to assign.';
    }

    if (! hasValidRate.value) {
        return isPerQuestion.value
            ? 'Enter a per-question rate (₹) — no rate is set in the matrix for the selected chapters.'
            : 'Enter a per-chapter rate (₹) — no rate is set in the matrix for the selected chapters.';
    }

    return '';
});

const toggleMapAssign = (index) => {
    const row = form.chapter_maps[index];
    if (! row || row.block_reason) {
        return;
    }

    if (! row.book_chapter_number || ! row.book_chapter_title) {
        return;
    }

    row.assign = ! row.assign;
};

const selectAllAssignable = () => {
    assignableRows.value.forEach((row) => {
        if (row.book_chapter_number && row.book_chapter_title) {
            row.assign = true;
        }
    });
};

const clearAssignSelection = () => {
    form.chapter_maps.forEach((row) => {
        row.assign = false;
    });
};

const resetBookTitleToSyllabus = (index) => {
    const row = form.chapter_maps[index];
    const syllabus = syllabusChapterById.value[row?.syllabus_chapter_id];
    if (! row || ! syllabus) {
        return;
    }

    row.book_chapter_title = syllabus.name || '';
    row.book_chapter_number = syllabus.chapter_number || row.book_chapter_number;
};

const applySuggestedRate = () => {
    form.rate_basis = props.classDefaultRateBasis || 'per_question';
    form.offered_amount_inr = props.classDefaultRateInr > 0
        ? props.classDefaultRateInr
        : (form.rate_basis === 'per_question' ? 2 : 5000);
    showRateRequired.value = false;
    form.clearErrors('offered_amount_inr');
};

const submit = () => {
    if (! canSubmit.value || form.processing) {
        return;
    }

    if (! hasValidRate.value) {
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
</script>

<template>
    <Head title="Assign content chapters" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Assign chapters to uploader</h2>
                    <p class="text-sm text-gray-500">
                        Pick board → book → tick syllabus chapters → assign. Book chapter name defaults to syllabus name (editable).
                    </p>
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
            <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6">
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

                    <div v-if="boards.length" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="1. Board / syllabus" />
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
                                Syllabus chapter list below is for this board only.
                            </p>
                            <InputError :message="form.errors.board_id" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel value="2. Content uploader" />
                            <select
                                v-model="form.assigned_to_user_id"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                :disabled="uploaders.length === 0"
                            >
                                <option value="" disabled>Select content uploader</option>
                                <option v-for="u in uploaders" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ uploaders.length }} content uploader(s). Manage under
                                <Link :href="route('admin.users.index')" class="text-indigo-600 underline">People → Users</Link>.
                            </p>
                            <InputError :message="form.errors.assigned_to_user_id" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <InputLabel value="3. Book master" />
                        <select
                            v-model="bookSelect"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                        >
                            <option v-for="book in textbooks" :key="book.id" :value="String(book.id)">
                                {{ book.label }}
                            </option>
                            <option :value="NEW_BOOK">+ Add new book…</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Choose an existing book, or add a new book master (name + code).
                        </p>

                        <div v-if="useNewBook" class="mt-3 grid gap-3 rounded-md border border-indigo-200 bg-indigo-50/40 p-3 sm:grid-cols-2">
                            <div>
                                <InputLabel value="New book name" />
                                <TextInput v-model="form.book_name" class="mt-1 block w-full" placeholder="e.g. Ganita Prakash Part I" required />
                                <InputError :message="form.errors.book_name" class="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Book code" />
                                <TextInput v-model="form.book_code" class="mt-1 block w-full" placeholder="e.g. GP" required />
                                <p class="mt-1 text-xs text-gray-500">Short code used in set IDs (letters/numbers only).</p>
                                <InputError :message="form.errors.book_code" class="mt-1" />
                            </div>
                        </div>
                        <InputError :message="form.errors.textbook_id" class="mt-1" />
                    </div>

                    <div v-if="syllabusChapters.length">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <InputLabel value="4. Select syllabus chapters to assign" />
                                <p class="mt-1 text-xs text-gray-500">
                                    Book chapter name defaults to the syllabus name. Change it only if the book uses a different title.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <SecondaryButton type="button" @click="selectAllAssignable">
                                    Select all available
                                </SecondaryButton>
                                <SecondaryButton type="button" @click="clearAssignSelection">
                                    Clear selection
                                </SecondaryButton>
                            </div>
                        </div>

                        <div class="mt-2">
                            <TextInput
                                v-model="chapterFilter"
                                class="block w-full text-sm"
                                placeholder="Filter chapters or topics…"
                            />
                        </div>

                        <div class="mt-2 overflow-x-auto rounded-md border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                                    <tr>
                                        <th class="px-2 py-2 text-left">Assign</th>
                                        <th class="px-2 py-2 text-left">Syllabus chapter</th>
                                        <th class="px-2 py-2 text-left">Book ch no</th>
                                        <th class="px-2 py-2 text-left">Book chapter name</th>
                                        <th class="px-2 py-2 text-left">Topics</th>
                                        <th class="px-2 py-2 text-right">Rate</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr
                                        v-for="{ row, index } in visibleChapterMaps"
                                        :key="row.syllabus_chapter_id"
                                        :class="row.block_reason ? 'bg-gray-50 text-gray-400' : (row.assign ? 'bg-emerald-50/60' : '')"
                                    >
                                        <td class="px-2 py-2 align-top">
                                            <input
                                                type="checkbox"
                                                class="rounded border-gray-300"
                                                :disabled="Boolean(row.block_reason) || !row.book_chapter_number || !row.book_chapter_title"
                                                :checked="row.assign"
                                                @change="toggleMapAssign(index)"
                                            >
                                        </td>
                                        <td class="px-2 py-2 align-top">
                                            <p class="font-medium text-gray-900" :class="row.block_reason ? 'text-gray-400' : ''">
                                                {{ row.syllabus_label }}
                                            </p>
                                            <p v-if="row.block_reason" class="mt-1 text-xs text-amber-700">
                                                {{ row.block_reason }}
                                            </p>
                                        </td>
                                        <td class="px-2 py-2 align-top">
                                            <input
                                                v-model="row.book_chapter_number"
                                                type="text"
                                                class="w-20 rounded-md border-gray-300 text-sm"
                                                :disabled="Boolean(row.block_reason)"
                                            >
                                        </td>
                                        <td class="px-2 py-2 align-top">
                                            <input
                                                v-model="row.book_chapter_title"
                                                type="text"
                                                class="min-w-[12rem] w-full rounded-md border-gray-300 text-sm"
                                                :disabled="Boolean(row.block_reason)"
                                            >
                                            <button
                                                v-if="!row.block_reason"
                                                type="button"
                                                class="mt-1 text-[11px] font-medium text-indigo-600 hover:underline"
                                                @click="resetBookTitleToSyllabus(index)"
                                            >
                                                Reset to syllabus name
                                            </button>
                                        </td>
                                        <td class="max-w-[16rem] px-2 py-2 align-top text-xs text-gray-600">
                                            <p v-if="row.topics_label" class="leading-snug">{{ row.topics_label }}</p>
                                            <span v-else class="italic text-gray-400">—</span>
                                        </td>
                                        <td class="px-2 py-2 align-top text-right text-xs text-gray-500">
                                            {{ formatMapRate(row) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p class="mt-2 text-xs text-gray-600">
                            Selected: <strong>{{ assignedMapRows.length }}</strong>
                            <span v-if="selectedRatePreview"> · Matrix rate: {{ selectedRatePreview }}</span>
                        </p>
                        <InputError :message="form.errors.chapter_maps" class="mt-1" />
                    </div>
                    <div v-else class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        No syllabus chapters found for {{ gradeLevel.name }}{{ selectedBoard ? ` · ${selectedBoard.code || selectedBoard.name}` : '' }}. Check academic year, board, and syllabus setup.
                    </div>

                    <div
                        v-if="needsRateOverride"
                        class="rounded-lg border-2 border-amber-400 bg-amber-50 px-4 py-4 text-sm text-amber-950"
                    >
                        <p class="text-base font-semibold">Set payment rate (required)</p>
                        <p class="mt-2">
                            Selected chapters show <strong>—</strong> because no rate is configured yet. Choose per-question or per-chapter below, then enter the rate.
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
                                    : (isPerQuestion ? 'Rate override (₹ per question, optional)' : 'Rate override (₹ per chapter, optional)')"
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
                                        ? (needsRateOverride ? 'Enter amount, e.g. 2' : 'Leave blank to use matrix')
                                        : (needsRateOverride ? 'Enter amount, e.g. 5000' : 'Leave blank to use matrix')"
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
                            {{ form.processing ? 'Creating…' : `Create assignment(s)${assignedMapRows.length ? ` · ${assignedMapRows.length}` : ''}` }}
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
