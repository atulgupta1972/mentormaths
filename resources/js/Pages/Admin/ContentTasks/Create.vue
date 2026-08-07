<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    uploaders: { type: Array, default: () => [] },
    gradeLevel: { type: Object, default: null },
    textbooks: { type: Array, default: () => [] },
    syllabusChapters: { type: Array, default: () => [] },
    classDefaultRateInr: { type: Number, default: 0 },
});

const page = usePage();
const useNewBook = ref(props.textbooks.length === 0);
const selectedTextbookId = ref(props.textbooks[0]?.id ?? '');
const rateSectionRef = ref(null);
const showRateRequired = ref(false);

watch(
    () => props.textbooks,
    (books) => {
        if (books.length === 0) {
            useNewBook.value = true;
            selectedTextbookId.value = '';
        } else if (!selectedTextbookId.value) {
            selectedTextbookId.value = books[0].id;
            useNewBook.value = false;
        }
    },
    { immediate: true },
);

const form = useForm({
    assigned_to_user_id: props.uploaders[0]?.id ?? '',
    textbook_id: '',
    book_name: 'Ganita Prakash Part I',
    book_code: 'GP',
    syllabus_chapter_ids: [],
    offered_amount_inr: '',
    duplicate_override_reason: '',
    admin_notes: '',
});

const toggleChapter = (chapterId) => {
    if (chapterBlockReason(chapterId)) {
        return;
    }

    const ids = new Set(form.syllabus_chapter_ids);
    if (ids.has(chapterId)) {
        ids.delete(chapterId);
    } else {
        ids.add(chapterId);
    }
    form.syllabus_chapter_ids = [...ids];
};

const chapterBlockReason = (chapterOrId) => {
    const chapter = typeof chapterOrId === 'object'
        ? chapterOrId
        : props.syllabusChapters.find((row) => Number(row.id) === Number(chapterOrId));

    if (!chapter) {
        return '';
    }

    // New book master: nothing uploaded/assigned for that book yet.
    if (useNewBook.value || !selectedTextbookId.value) {
        return '';
    }

    const textbookId = Number(selectedTextbookId.value);
    const assignedIds = (chapter.assigned_for_textbooks || []).map(Number);
    if (assignedIds.includes(textbookId)) {
        return 'already assigned';
    }

    const uploadedIds = (chapter.uploaded_for_textbooks || []).map(Number);
    if (uploadedIds.includes(textbookId)) {
        return 'already uploaded';
    }

    return '';
};

const formatInr = (amount) => (amount > 0 ? `₹${Number(amount).toLocaleString('en-IN')}` : '—');

watch(selectedTextbookId, () => {
    form.syllabus_chapter_ids = form.syllabus_chapter_ids.filter((id) => !chapterBlockReason(id));
});

watch(useNewBook, (isNew) => {
    if (isNew) {
        return;
    }

    form.syllabus_chapter_ids = form.syllabus_chapter_ids.filter((id) => !chapterBlockReason(id));
});

const selectedRatePreview = computed(() => {
    const selected = props.syllabusChapters.filter((ch) => form.syllabus_chapter_ids.includes(ch.id));
    if (!selected.length) {
        return null;
    }
    const amounts = [...new Set(selected.map((ch) => ch.default_amount_inr).filter((a) => a > 0))];
    if (amounts.length === 1) {
        return formatInr(amounts[0]);
    }
    if (amounts.length > 1) {
        return `${formatInr(Math.min(...amounts))} – ${formatInr(Math.max(...amounts))}`;
    }
    return 'Set rates in matrix first';
});

const hasMatrixRate = computed(() => {
    const selected = props.syllabusChapters.filter((ch) => form.syllabus_chapter_ids.includes(ch.id));
    if (!selected.length) {
        return false;
    }

    return selected.every((ch) => Number(ch.default_amount_inr) > 0);
});

const hasValidRate = computed(() => {
    if (form.offered_amount_inr !== '' && Number(form.offered_amount_inr) >= 100) {
        return true;
    }

    return hasMatrixRate.value;
});

const needsRateOverride = computed(() => {
    return form.syllabus_chapter_ids.length > 0 && !hasMatrixRate.value;
});

const canSubmit = computed(() => {
    return Boolean(form.assigned_to_user_id) && form.syllabus_chapter_ids.length > 0;
});

const submitBlockedReason = computed(() => {
    if (!form.assigned_to_user_id) {
        return 'Select a content uploader.';
    }

    if (form.syllabus_chapter_ids.length === 0) {
        return 'Select at least one syllabus chapter.';
    }

    if (!hasValidRate.value) {
        return 'Enter a rate override (₹) — no rate is set in the matrix for the selected chapters.';
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
            'Enter a rate (₹) per chapter — no rate is set in the matrix for this class.',
        );
        rateSectionRef.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    showRateRequired.value = false;

    form.transform((data) => ({
        ...data,
        textbook_id: useNewBook.value ? null : selectedTextbookId.value,
        book_name: useNewBook.value ? data.book_name : null,
        book_code: useNewBook.value ? data.book_code : null,
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
    form.offered_amount_inr = props.classDefaultRateInr > 0 ? props.classDefaultRateInr : 5000;
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
                    <p class="text-sm text-gray-500">Class → textbook master → syllabus chapters. One uploader per chapter.</p>
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
                        Assigning for <strong>{{ gradeLevel.name }}</strong>.
                        Default rates come from the <Link :href="route('admin.content-rate-cards.index')" class="underline">rate matrix</Link>.
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
                        <p v-if="uploaders.length < 2" class="mt-1 text-xs text-amber-800">
                            Expected more people? Open their mentor application and click
                            <strong>Grant content uploader access</strong>, or edit the user and tick the Content uploader group.
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
                        <InputLabel value="Syllabus chapters" />
                        <div class="mt-2 max-h-64 space-y-2 overflow-y-auto rounded-md border border-gray-200 p-3">
                            <label
                                v-for="chapter in syllabusChapters"
                                :key="chapter.id"
                                class="flex items-start justify-between gap-2 text-sm"
                                :class="chapterBlockReason(chapter) ? 'cursor-not-allowed text-gray-400' : 'cursor-pointer text-gray-800'"
                            >
                                <span class="flex items-start gap-2">
                                    <input
                                        type="checkbox"
                                        class="mt-1 rounded border-gray-300"
                                        :disabled="Boolean(chapterBlockReason(chapter))"
                                        :checked="form.syllabus_chapter_ids.includes(chapter.id)"
                                        @change="toggleChapter(chapter.id)"
                                    >
                                    <span>
                                        {{ chapter.label }}
                                        <span v-if="chapterBlockReason(chapter)" class="text-xs font-medium text-amber-700">
                                            — {{ chapterBlockReason(chapter) }}
                                        </span>
                                    </span>
                                </span>
                                <span class="shrink-0 text-xs text-gray-500">{{ formatInr(chapter.default_amount_inr) }}</span>
                            </label>
                        </div>
                        <p v-if="selectedRatePreview" class="mt-2 text-xs text-gray-600">Matrix rate for selection: {{ selectedRatePreview }}</p>
                        <InputError :message="form.errors.syllabus_chapter_ids" class="mt-1" />
                    </div>
                    <div v-else class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        No syllabus chapters found for {{ gradeLevel.name }}. Check academic year and syllabus setup.
                    </div>

                    <div
                        v-if="needsRateOverride"
                        class="rounded-lg border-2 border-amber-400 bg-amber-50 px-4 py-4 text-sm text-amber-950"
                    >
                        <p class="text-base font-semibold">Step 1: Set payment rate (required)</p>
                        <p class="mt-2">
                            Chapters show <strong>—</strong> because no rate is configured yet. Choose one:
                        </p>
                        <ol class="mt-2 list-decimal space-y-1 pl-5">
                            <li>
                                Type an amount in <strong>Rate override</strong> below (e.g. 5000), then create assignments.
                            </li>
                            <li>
                                Or open the
                                <Link :href="route('admin.content-rate-cards.index')" class="font-semibold underline">rate matrix</Link>,
                                add a row for <strong>{{ gradeLevel.name }}</strong>, then return here.
                            </li>
                        </ol>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <SecondaryButton type="button" @click="applySuggestedRate">
                                Use {{ formatInr(classDefaultRateInr > 0 ? classDefaultRateInr : 5000) }} in override
                            </SecondaryButton>
                            <Link
                                :href="route('admin.content-rate-cards.index')"
                                class="inline-flex items-center rounded-md border border-amber-600 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-amber-900 hover:bg-amber-100"
                            >
                                Open rate matrix
                            </Link>
                        </div>
                    </div>

                    <div ref="rateSectionRef">
                        <InputLabel
                            :value="needsRateOverride
                                ? 'Rate override (₹, required — no matrix rate for this class)'
                                : 'Rate override (₹, optional — applies to all selected chapters)'"
                        />
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <input
                                v-model="form.offered_amount_inr"
                                type="number"
                                min="100"
                                class="block w-full min-w-[12rem] flex-1 rounded-md text-sm"
                                :class="showRateRequired || form.errors.offered_amount_inr
                                    ? 'border-amber-500 ring-2 ring-amber-300'
                                    : 'border-gray-300'"
                                :placeholder="needsRateOverride ? 'Enter amount, e.g. 5000' : 'Leave blank to use matrix per chapter'"
                            >
                            <SecondaryButton
                                v-if="needsRateOverride && !hasValidRate"
                                type="button"
                                class="shrink-0"
                                @click="applySuggestedRate"
                            >
                                Fill ₹5,000
                            </SecondaryButton>
                        </div>
                        <InputError :message="form.errors.offered_amount_inr" class="mt-1" />
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
                        Enter a rate override (₹) above, then click Create assignment(s).
                    </p>
                    <p v-if="form.processing" class="text-sm text-slate-600">Saving assignments and sending email…</p>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
