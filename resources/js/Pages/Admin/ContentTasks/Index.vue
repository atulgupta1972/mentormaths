<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    tasks: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    matrix: { type: Object, required: true },
    uploaders: { type: Array, default: () => [] },
    pendingPublishCount: { type: Number, default: 0 },
});

const page = usePage();
const formatInr = (amount) => (amount ? `₹${Number(amount).toLocaleString('en-IN')}` : '—');

const buckets = [
    {
        key: 'under_review',
        label: 'Under review',
        short: 'Review',
        idle: 'bg-sky-50 text-sky-900 ring-sky-200 hover:bg-sky-100',
        active: 'bg-sky-600 text-white ring-sky-600',
        heading: 'text-sky-800',
    },
    {
        key: 'submitted',
        label: 'Submitted',
        short: 'Submitted',
        idle: 'bg-violet-50 text-violet-900 ring-violet-200 hover:bg-violet-100',
        active: 'bg-violet-600 text-white ring-violet-600',
        heading: 'text-violet-800',
    },
    {
        key: 'published',
        label: 'Published',
        short: 'Published',
        idle: 'bg-emerald-50 text-emerald-900 ring-emerald-200 hover:bg-emerald-100',
        active: 'bg-emerald-600 text-white ring-emerald-600',
        heading: 'text-emerald-800',
    },
];

const cellBucketCount = (gradeId, uploaderId, bucket) =>
    props.matrix.cells?.[String(gradeId)]?.[String(uploaderId)]?.breakup?.[bucket] ?? 0;

const isDrillOpen = (gradeId, uploaderId) =>
    Number(props.filters.drill_grade_id) === Number(gradeId)
    && Number(props.filters.drill_uploader_id) === Number(uploaderId);

const matrixQuery = (extra = {}) => ({
    board_id: props.matrix.board_id || undefined,
    status: props.filters.status || undefined,
    ...extra,
});

const setBoard = (boardId) => {
    router.get(route('admin.content-tasks.index'), {
        board_id: boardId || undefined,
        status: props.filters.status || undefined,
    }, { preserveState: true, replace: true });
};

const toggleDrill = (gradeId, uploaderId, bucket) => {
    const open = isDrillOpen(gradeId, uploaderId);
    if (open && drillFilter.value === bucket) {
        closeDrill();
        return;
    }

    if (open) {
        drillFilter.value = bucket;
        return;
    }

    router.get(route('admin.content-tasks.index'), matrixQuery({
        drill_grade_id: gradeId,
        drill_uploader_id: uploaderId,
        drill_bucket: bucket,
    }), { preserveState: true, replace: true, preserveScroll: true });
};

const closeDrill = () => {
    router.get(route('admin.content-tasks.index'), {
        board_id: props.matrix.board_id || undefined,
        status: props.filters.status || undefined,
    }, { preserveState: true, replace: true, preserveScroll: true });
};

const statusTone = (group) => ({
    awaiting: 'bg-amber-50 text-amber-900 ring-amber-200',
    under_upload: 'bg-sky-50 text-sky-900 ring-sky-200',
    uploaded: 'bg-indigo-50 text-indigo-900 ring-indigo-200',
    submitted: 'bg-violet-50 text-violet-900 ring-violet-200',
    published: 'bg-emerald-50 text-emerald-900 ring-emerald-200',
    other: 'bg-gray-50 text-gray-700 ring-gray-200',
}[group] || 'bg-gray-50 text-gray-700 ring-gray-200');

const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);
const emailSent = computed(() => page.props.flash?.email_sent);
const assignmentSummary = computed(() => page.props.flash?.assignment_summary);

const drillFilter = ref(null);

watch(
    () => [props.matrix.drill?.grade?.id, props.matrix.drill?.uploader?.id, props.filters.drill_bucket],
    () => {
        drillFilter.value = props.filters.drill_bucket || null;
    },
    { immediate: true },
);

const drillBreakup = computed(() => props.matrix.drill?.breakup ?? {
    under_review: 0,
    submitted: 0,
    published: 0,
});

const filteredDrillChapters = computed(() => {
    const chapters = props.matrix.drill?.chapters ?? [];
    if (!drillFilter.value) {
        return chapters;
    }

    return chapters.filter((row) => row.breakup_bucket === drillFilter.value);
});

const groupedDrillChapters = computed(() => {
    const groups = [];
    let current = null;

    for (const row of filteredDrillChapters.value) {
        const board = row.chapter?.board_code || row.chapter?.board_name || 'Board';
        const book = row.chapter?.book_author_name || row.chapter?.textbook_name || 'Book / author';
        const key = `${board}||${book}`;

        if (!current || current.key !== key) {
            current = { key, board, book, rows: [] };
            groups.push(current);
        }

        current.rows.push(row);
    }

    return groups;
});

const setDrillFilter = (bucket) => {
    drillFilter.value = drillFilter.value === bucket ? null : bucket;
};

const breakupCards = computed(() => [
    {
        key: 'under_review',
        label: 'Under review',
        hint: 'Assigned, uploading, or verifying',
        count: drillBreakup.value.under_review ?? 0,
        idle: 'bg-sky-50 text-sky-950 ring-sky-200 hover:bg-sky-100',
        active: 'bg-sky-600 text-white ring-sky-600',
    },
    {
        key: 'submitted',
        label: 'Submitted',
        hint: 'Click to review & publish',
        count: drillBreakup.value.submitted ?? 0,
        idle: 'bg-violet-50 text-violet-950 ring-violet-200 hover:bg-violet-100',
        active: 'bg-violet-600 text-white ring-violet-600',
    },
    {
        key: 'published',
        label: 'Published',
        hint: 'Live for students',
        count: drillBreakup.value.published ?? 0,
        idle: 'bg-emerald-50 text-emerald-950 ring-emerald-200 hover:bg-emerald-100',
        active: 'bg-emerald-600 text-white ring-emerald-600',
    },
]);

const selectedTaskIds = ref([]);
const bulkReassignForm = useForm({
    task_ids: [],
    assigned_to_user_id: '',
    note: '',
});

const reassignableDrillChapters = computed(() =>
    filteredDrillChapters.value.filter((row) => row.can_reassign),
);

const selectedCount = computed(() => selectedTaskIds.value.length);

const allReassignableSelected = computed(() => {
    const ids = reassignableDrillChapters.value.map((row) => row.id);
    return ids.length > 0 && ids.every((id) => selectedTaskIds.value.includes(id));
});

const someReassignableSelected = computed(() =>
    selectedCount.value > 0 && !allReassignableSelected.value,
);

const isSelected = (id) => selectedTaskIds.value.includes(id);

const toggleRow = (id, canReassign) => {
    if (!canReassign) {
        return;
    }

    if (isSelected(id)) {
        selectedTaskIds.value = selectedTaskIds.value.filter((rowId) => rowId !== id);
        return;
    }

    selectedTaskIds.value = [...selectedTaskIds.value, id];
};

const toggleSelectAll = () => {
    if (allReassignableSelected.value) {
        const drop = new Set(reassignableDrillChapters.value.map((row) => row.id));
        selectedTaskIds.value = selectedTaskIds.value.filter((id) => !drop.has(id));
        return;
    }

    selectedTaskIds.value = [
        ...new Set([
            ...selectedTaskIds.value,
            ...reassignableDrillChapters.value.map((row) => row.id),
        ]),
    ];
};

const submitBulkReassign = () => {
    bulkReassignForm.task_ids = selectedTaskIds.value;
    bulkReassignForm.post(route('admin.content-tasks.bulk-reassign'), {
        preserveScroll: true,
        onSuccess: () => {
            selectedTaskIds.value = [];
            bulkReassignForm.reset();
        },
    });
};

watch(
    () => filteredDrillChapters.value.map((row) => row.id).join(','),
    () => {
        const visible = new Set(filteredDrillChapters.value.map((row) => row.id));
        selectedTaskIds.value = selectedTaskIds.value.filter((id) => visible.has(id));
    },
);
</script>

<template>
    <Head title="Content upload tasks" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Content allocation matrix</h2>
                    <p class="text-sm text-gray-500">People with assigned chapters only — three counts each: under review, submitted, published.</p>
                </div>
                <div class="flex gap-2">
                    <Link :href="route('admin.content-rate-cards.index')" class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Rate matrix
                    </Link>
                    <Link :href="route('admin.content-tasks.create')">
                        <PrimaryButton>Assign chapters</PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6">
                <div v-if="flashSuccess" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                    {{ flashSuccess }}
                    <span v-if="emailSent === true" class="mt-1 block text-emerald-800">Email delivery attempted successfully.</span>
                    <span v-else-if="emailSent === false" class="mt-1 block text-amber-800">Email may not have been delivered.</span>
                    <span v-if="assignmentSummary" class="mt-1 block text-emerald-800">
                        {{ assignmentSummary.count }} chapter(s) → {{ assignmentSummary.uploader?.name }} ({{ assignmentSummary.uploader?.email }})
                    </span>
                </div>
                <div v-if="flashError" class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-950">
                    {{ flashError }}
                </div>

                <div v-if="pendingPublishCount" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    <strong>{{ pendingPublishCount }}</strong> chapter(s) submitted and waiting for admin publish.
                </div>

                <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">Board filter</label>
                            <select
                                class="mt-1 block rounded-md border-gray-300 text-sm"
                                :value="matrix.board_id || ''"
                                @change="setBoard($event.target.value ? Number($event.target.value) : null)"
                            >
                                <option value="">All boards</option>
                                <option v-for="board in matrix.boards" :key="board.id" :value="board.id">
                                    {{ board.code }} — {{ board.name }}
                                </option>
                            </select>
                        </div>
                        <p class="text-xs text-gray-500">
                            Showing <strong>{{ matrix.total_assignments ?? 0 }}</strong> assignment(s)
                            <span v-if="(matrix.database_total ?? 0) !== (matrix.total_assignments ?? 0)">
                                ({{ matrix.database_total }} in database)
                            </span>.
                            Click a count to see those chapters.
                        </p>
                    </div>

                    <div
                        v-if="(matrix.database_total ?? 0) === 0"
                        class="mt-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                    >
                        <p class="font-semibold">No chapter assignments are saved yet.</p>
                        <p class="mt-1">
                            If you clicked <strong>Create assignment(s)</strong> earlier but the form stayed filled,
                            the save likely failed because chapter rates were missing
                            (“Set rates in matrix first”).
                        </p>
                        <p class="mt-2">
                            Fix:
                            <Link :href="route('admin.content-rate-cards.index')" class="underline">set Rate matrix</Link>
                            for this class, <em>or</em> enter a <strong>Rate override (₹)</strong> on Assign chapters,
                            then assign again. You should then see a green success message and email confirmation.
                        </p>
                        <p class="mt-2">
                            <Link :href="route('admin.content-tasks.create')" class="font-medium text-indigo-700 underline">
                                Go to Assign chapters →
                            </Link>
                        </p>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full border-collapse text-sm">
                            <thead>
                                <tr class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th rowspan="2" class="sticky left-0 z-10 border border-slate-200 bg-slate-50 px-3 py-2 align-bottom">Class</th>
                                    <th
                                        v-for="uploader in matrix.uploaders"
                                        :key="uploader.id"
                                        colspan="3"
                                        class="border border-slate-200 px-3 py-2 text-center"
                                        :title="uploader.email"
                                    >
                                        {{ uploader.name }}
                                    </th>
                                </tr>
                                <tr class="bg-slate-50 text-center text-[10px] font-semibold uppercase tracking-wide">
                                    <template v-for="uploader in matrix.uploaders" :key="`hdr-${uploader.id}`">
                                        <th
                                            v-for="bucket in buckets"
                                            :key="`${uploader.id}-${bucket.key}`"
                                            class="border border-slate-200 px-2 py-1"
                                            :class="bucket.heading"
                                        >
                                            {{ bucket.short }}
                                        </th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="grade in matrix.grades" :key="grade.id">
                                    <td class="sticky left-0 z-10 border border-slate-200 bg-white px-3 py-2 font-medium text-slate-900">
                                        {{ grade.name }}
                                    </td>
                                    <template v-for="uploader in matrix.uploaders" :key="`${grade.id}-${uploader.id}`">
                                        <td
                                            v-for="bucket in buckets"
                                            :key="`${grade.id}-${uploader.id}-${bucket.key}`"
                                            class="border border-slate-200 px-1 py-1 text-center"
                                        >
                                            <button
                                                v-if="cellBucketCount(grade.id, uploader.id, bucket.key) > 0"
                                                type="button"
                                                class="min-w-[2.25rem] rounded-md px-2 py-1 text-sm font-semibold ring-1 transition"
                                                :class="isDrillOpen(grade.id, uploader.id) && drillFilter === bucket.key
                                                    ? bucket.active
                                                    : bucket.idle"
                                                :title="`${uploader.name} · ${bucket.label}`"
                                                @click="toggleDrill(grade.id, uploader.id, bucket.key)"
                                            >
                                                {{ cellBucketCount(grade.id, uploader.id, bucket.key) }}
                                            </button>
                                            <span v-else class="text-slate-300">—</span>
                                        </td>
                                    </template>
                                </tr>
                                <tr v-if="!matrix.uploaders.length">
                                    <td class="border border-slate-200 px-4 py-6 text-center text-slate-500">
                                        No assigned content uploaders in this view.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="matrix.drill" class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-indigo-200">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-slate-900">
                                {{ matrix.drill.grade?.name }} · {{ matrix.drill.uploader?.name }}
                            </h3>
                            <p class="text-sm text-slate-500">
                                {{ matrix.drill.chapters.length }} chapter(s) · {{ matrix.drill.uploader?.email }}
                            </p>
                        </div>
                        <button type="button" class="text-sm text-indigo-600 hover:underline" @click="closeDrill">Close</button>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <button
                            v-for="card in breakupCards"
                            :key="card.key"
                            type="button"
                            class="rounded-lg px-3 py-3 text-left ring-1 transition"
                            :class="drillFilter === card.key ? card.active : card.idle"
                            @click="setDrillFilter(card.key)"
                        >
                            <p class="text-xs font-semibold uppercase tracking-wide opacity-80">{{ card.label }}</p>
                            <p class="mt-1 text-2xl font-semibold">{{ card.count }}</p>
                            <p class="mt-1 text-xs opacity-80">{{ card.hint }}</p>
                        </button>
                    </div>

                    <p class="mt-3 text-xs text-slate-500">
                        <template v-if="drillFilter === 'submitted'">Showing submitted chapters — open one to review questions and publish.</template>
                        <template v-else-if="drillFilter === 'under_review'">Showing chapters still under review (not yet submitted).</template>
                        <template v-else-if="drillFilter === 'published'">Showing published chapters — click <strong>Gemini check</strong> to verify answers with Gemini paste review.</template>
                        <template v-else>Click a count above to filter. Submitted opens review &amp; publish.</template>
                    </p>

                    <form
                        v-if="reassignableDrillChapters.length"
                        class="mt-3 flex flex-wrap items-end gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-3"
                        @submit.prevent="submitBulkReassign"
                    >
                        <p class="w-full text-sm text-slate-700 sm:w-auto sm:flex-1">
                            <template v-if="selectedCount">
                                <strong>{{ selectedCount }}</strong> selected — reassign them together instead of one by one.
                            </template>
                            <template v-else>
                                Tick chapters below, or select all, then choose a new uploader.
                            </template>
                        </p>
                        <div class="min-w-[14rem] flex-1">
                            <label class="text-xs font-medium text-gray-600">New uploader</label>
                            <select
                                v-model="bulkReassignForm.assigned_to_user_id"
                                required
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            >
                                <option value="" disabled>Select uploader</option>
                                <option
                                    v-for="person in uploaders"
                                    :key="person.id"
                                    :value="person.id"
                                    :disabled="person.id === matrix.drill?.uploader?.id"
                                >
                                    {{ person.name }}{{ person.id === matrix.drill?.uploader?.id ? ' (current)' : '' }}
                                </option>
                            </select>
                        </div>
                        <div class="min-w-[12rem] flex-1">
                            <label class="text-xs font-medium text-gray-600">Note (optional)</label>
                            <input
                                v-model="bulkReassignForm.note"
                                type="text"
                                maxlength="500"
                                placeholder="Could not finish"
                                class="mt-1 w-full rounded-md border-gray-300 text-sm"
                            >
                        </div>
                        <PrimaryButton
                            type="submit"
                            :disabled="bulkReassignForm.processing || !selectedCount || !bulkReassignForm.assigned_to_user_id"
                        >
                            {{ bulkReassignForm.processing ? 'Reassigning…' : `Reassign ${selectedCount}` }}
                        </PrimaryButton>
                    </form>

                    <div class="mt-3 overflow-hidden rounded-md ring-1 ring-slate-200">
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="w-10 px-3 py-2">
                                        <input
                                            type="checkbox"
                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-40"
                                            :checked="allReassignableSelected"
                                            :indeterminate="someReassignableSelected"
                                            :disabled="!reassignableDrillChapters.length"
                                            title="Select all reassignable chapters"
                                            @change="toggleSelectAll"
                                        >
                                        <span class="sr-only">Select all</span>
                                    </th>
                                    <th class="px-3 py-2">Board</th>
                                    <th class="px-3 py-2">Book / author</th>
                                    <th class="px-3 py-2">Ch No.</th>
                                    <th class="px-3 py-2">Chapter head</th>
                                    <th class="px-3 py-2">Chapter name</th>
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2">Questions</th>
                                    <th class="px-3 py-2">Gemini check</th>
                                    <th class="px-3 py-2">Rate</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template v-for="group in groupedDrillChapters" :key="group.key">
                                    <tr class="bg-slate-100">
                                        <td colspan="12" class="px-3 py-1.5 text-xs font-semibold text-slate-800">
                                            <span class="rounded bg-indigo-100 px-1.5 py-0.5 font-bold text-indigo-900">{{ group.board }}</span>
                                            <span class="mx-2 text-slate-400">·</span>
                                            <span>{{ group.book }}</span>
                                        </td>
                                    </tr>
                                    <tr v-for="row in group.rows" :key="row.id">
                                        <td class="px-3 py-2">
                                            <input
                                                v-if="row.can_reassign"
                                                type="checkbox"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                :checked="isSelected(row.id)"
                                                :aria-label="`Select chapter ${row.chapter?.chapter_number}`"
                                                @change="toggleRow(row.id, true)"
                                            >
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-xs font-bold text-indigo-900">
                                            {{ row.chapter?.board_code || row.chapter?.board_name || '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-slate-800">
                                            <p class="font-medium">{{ row.chapter?.book_author_name || row.chapter?.textbook_name || '—' }}</p>
                                            <p v-if="row.chapter?.textbook_code" class="font-mono text-[10px] text-slate-500">{{ row.chapter.textbook_code }}</p>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 font-semibold text-slate-900">
                                            {{ row.chapter?.chapter_number || '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-slate-600">{{ row.chapter?.chapter_head_name || '—' }}</td>
                                        <td class="px-3 py-2 font-medium text-slate-900">{{ row.chapter?.title || '—' }}</td>
                                        <td class="px-3 py-2 text-slate-700">{{ row.work_type_label || '—' }}</td>
                                        <td class="px-3 py-2">
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1" :class="statusTone(row.status_group)">
                                                {{ row.status_label }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2">{{ row.question_count || '—' }}</td>
                                        <td class="px-3 py-2">
                                            <template v-if="row.gemini_progress?.can_gemini">
                                                <span
                                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                                    :class="row.gemini_progress.pending === 0 ? 'bg-emerald-100 text-emerald-900' : 'bg-amber-100 text-amber-900'"
                                                >
                                                    {{ row.gemini_progress.verified }}/{{ row.gemini_progress.total }}
                                                </span>
                                            </template>
                                            <span v-else class="text-slate-400">—</span>
                                        </td>
                                        <td class="px-3 py-2">{{ row.rate_description || formatInr(row.agreed_amount_inr || row.offered_amount_inr) }}</td>
                                        <td class="px-3 py-2 text-right">
                                            <Link
                                                :href="route('admin.content-tasks.show', row.id)"
                                                class="font-medium hover:underline"
                                                :class="row.can_review_and_publish ? 'text-violet-700' : 'text-indigo-600'"
                                            >
                                                {{ row.can_review_and_publish ? 'Review & publish' : (row.can_gemini_verify ? 'Gemini check' : 'Open') }}
                                            </Link>
                                            <Link
                                                v-if="row.can_reassign"
                                                :href="route('admin.content-tasks.show', row.id)"
                                                class="ml-3 text-slate-600 hover:underline"
                                            >
                                                Reassign
                                            </Link>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!filteredDrillChapters.length">
                                    <td colspan="12" class="px-3 py-6 text-center text-slate-500">
                                        No chapters in this bucket.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="border-b border-gray-100 px-4 py-3">
                        <h3 class="font-semibold text-gray-900">Recent tasks</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Chapter</th>
                                <th class="px-4 py-3">Uploader</th>
                                <th class="px-4 py-3">Rate</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="task in tasks.data" :key="task.id">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-gray-900">
                                        <span v-if="task.chapter?.board_code" class="mr-1 rounded bg-indigo-100 px-1.5 py-0.5 text-xs font-bold text-indigo-900">{{ task.chapter.board_code }}</span>
                                        {{ task.chapter?.grade_name }} · {{ task.chapter?.book_author_name || task.chapter?.textbook_name || 'Book' }} · {{ task.chapter?.chapter_number }}
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        <span v-if="task.chapter?.chapter_head_name">{{ task.chapter.chapter_head_name }} · </span>
                                        {{ task.chapter?.title }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">{{ task.assignee?.name }}</td>
                                <td class="px-4 py-3">{{ task.rate_description || formatInr(task.agreed_amount_inr || task.offered_amount_inr) }}</td>
                                <td class="px-4 py-3">{{ task.work_type_label || '—' }}</td>
                                <td class="px-4 py-3">{{ task.status_label }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="route('admin.content-tasks.show', task.id)" class="text-indigo-600 hover:underline">View</Link>
                                    <Link
                                        v-if="task.can_reassign"
                                        :href="route('admin.content-tasks.show', task.id)"
                                        class="ml-3 text-slate-600 hover:underline"
                                    >
                                        Reassign
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!tasks.data.length">
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">No tasks yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
