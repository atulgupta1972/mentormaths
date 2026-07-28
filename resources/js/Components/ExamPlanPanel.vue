<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import ExamPlanChapterTable from '@/Components/ExamPlanChapterTable.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatScoreLabel } from '@/utils/scores';
import { useForm, router } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    plans: { type: Array, default: () => [] },
    syllabusChapters: { type: Array, default: () => [] },
    examTypeOptions: { type: Array, default: () => [] },
    studentId: { type: [Number, String], default: null },
    context: { type: String, default: 'student' },
    compact: { type: Boolean, default: false },
    autoOpenCreate: { type: Boolean, default: false },
    highlightPlanId: { type: [Number, String], default: null },
});

const isAdminContext = computed(() => props.context === 'admin');

const showForm = ref(false);
const editingPlan = ref(null);
const assigningPlanId = ref(null);
const expandedPlanId = ref(null);
const assignDueDates = ref({});
const marksDraft = ref({});
const savingMarksPlanId = ref(null);
const marksErrors = ref({});

const emptyForm = () => ({
    exam_date: '',
    title: '',
    exam_type: 'unit_test',
    notes: '',
    obtained_marks: '',
    total_marks: '',
    chapter_selections: [],
    ...(props.context === 'admin' && props.studentId ? { student_id: props.studentId } : {}),
});

const form = useForm(emptyForm());
const chapterSelections = ref({});
const expandedChapters = ref({});
const submitProcessing = ref(false);
const submitErrors = ref({});
const assignForm = useForm({
    student_id: '',
    target_date: '',
    exam_plan_id: '',
    notes: '',
});

const canAddExam = computed(() => props.syllabusChapters.length > 0);
const canEditPlans = computed(() => canAddExam.value || props.plans.length > 0);
const canManage = computed(() => {
    if (isAdminContext.value) {
        return Boolean(props.studentId);
    }

    return true;
});

const formatDate = (d) => {
    if (!d) {
        return '';
    }

    return new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const prepByWorksheetId = (plan) => Object.fromEntries(
    (plan.prep_assignments || []).map((prep) => [prep.practice_set_id, prep]),
);

const prepRowFromAssignment = (chapter, prep) => ({
    chapter_id: chapter.chapter_id,
    chapter_label: prep.chapter_label || chapter.chapter_label,
    topic_label: prep.topic_name || '—',
    topic_id: prep.topic_id || null,
    set_code: prep.set_code,
    kind_label: prep.kind_label,
    practice_set_id: prep.practice_set_id,
    assignment_id: prep.assignment_id,
    progress_label: prep.progress_label,
    status: prep.status,
    assignment_status: prep.assignment_status,
    is_overdue: prep.is_overdue,
    target_date: prep.target_date,
    submission_timing: prep.submission_timing,
    is_assigned: true,
    is_empty: false,
});

const chapterPrepRows = (plan) => {
    const prepMap = prepByWorksheetId(plan);
    const rows = [];

    const chapters = plan.assignable_chapters?.length
        ? plan.assignable_chapters
        : (plan.chapters || []).map((chapter) => ({
            chapter_id: chapter.id,
            chapter_label: chapter.label,
            topic_sets: [],
            chapter_tests: [],
        }));

    for (const chapter of chapters) {
        const sets = [
            ...(chapter.topic_sets || []).map((set) => ({
                ...set,
                topic_label: set.topic_name || '—',
            })),
            ...(chapter.chapter_tests || []).map((set) => ({
                ...set,
                topic_label: 'Chapter test',
                topic_id: null,
            })),
        ];

        if (sets.length === 0) {
            const chapterPreps = (plan.prep_assignments || []).filter(
                (prep) => prep.chapter_id === chapter.chapter_id,
            );

            if (chapterPreps.length === 0) {
                rows.push({
                    chapter_id: chapter.chapter_id,
                    chapter_label: chapter.chapter_label,
                    topic_label: '—',
                    set_code: null,
                    kind_label: null,
                    progress_label: 'No sets yet',
                    status: 'none',
                    target_date: null,
                    practice_set_id: null,
                    assignment_id: null,
                    topic_id: null,
                    is_assigned: false,
                    is_empty: true,
                });

                continue;
            }

            for (const prep of chapterPreps) {
                rows.push(prepRowFromAssignment(chapter, prep));
            }

            continue;
        }

        for (const set of sets) {
            const prep = prepMap[set.id];

            rows.push({
                chapter_id: chapter.chapter_id,
                chapter_label: chapter.chapter_label,
                topic_label: set.topic_label,
                topic_id: set.topic_id || null,
                set_code: set.set_code,
                kind_label: set.kind_label,
                practice_set_id: set.id,
                assignment_id: prep?.assignment_id || null,
                progress_label: prep?.progress_label || (set.is_assigned ? 'Assigned · to do' : 'Not assigned'),
                status: prep?.status || 'unassigned',
                assignment_status: prep?.assignment_status || null,
                is_overdue: prep?.is_overdue || false,
                submission_timing: prep?.submission_timing || null,
                target_date: prep?.target_date || null,
                is_assigned: Boolean(prep) || set.is_assigned,
                is_empty: false,
            });
        }
    }

    return rows;
};

const planHasChapterRows = (plan) => (plan.chapters || []).length > 0;

const expandedPlan = computed(() => props.plans.find((plan) => plan.id === expandedPlanId.value) ?? null);

const openPlanView = (plan) => {
    expandedPlanId.value = plan.id;
    document.body.style.overflow = 'hidden';
};

const closePlanView = () => {
    expandedPlanId.value = null;
    document.body.style.overflow = '';
};

const dueDateForPlan = (plan) => assignDueDates.value[plan.id] || plan.suggested_due_date || plan.exam_date;

const marksScoreLabel = (plan) => plan.marks_score_label || formatScoreLabel(plan.obtained_marks, plan.total_marks);

const syncMarksDraft = () => {
    const next = {};

    for (const plan of props.plans) {
        next[plan.id] = {
            obtained_marks: plan.obtained_marks ?? '',
            total_marks: plan.total_marks ?? '',
        };
    }

    marksDraft.value = next;
};

const saveMarks = (plan) => {
    const draft = marksDraft.value[plan.id] || {};

    savingMarksPlanId.value = plan.id;
    marksErrors.value = {};

    router.put(route(`${props.context}.exam-plans.update`, plan.id), {
        exam_date: plan.exam_date,
        title: plan.title,
        exam_type: plan.exam_type,
        notes: plan.notes || '',
        chapter_selections: plan.chapter_selections,
        obtained_marks: draft.obtained_marks === '' ? null : Number(draft.obtained_marks),
        total_marks: draft.total_marks === '' ? null : Number(draft.total_marks),
    }, {
        preserveScroll: true,
        onError: (errors) => {
            marksErrors.value = errors;
        },
        onFinish: () => {
            savingMarksPlanId.value = null;
        },
    });
};

const toggleAssign = (plan) => {
    if (assigningPlanId.value === plan.id) {
        assigningPlanId.value = null;

        return;
    }

    assigningPlanId.value = plan.id;

    if (!assignDueDates.value[plan.id]) {
        assignDueDates.value[plan.id] = plan.suggested_due_date || plan.exam_date;
    }

    if (props.compact) {
        openPlanView(plan);
    }
};

const initChapterSelections = (plan = null) => {
    const next = {};
    const expanded = {};

    for (const chapter of props.syllabusChapters) {
        next[chapter.id] = { selected: false, topicIds: null };
        expanded[chapter.id] = false;
    }

    const saved = plan?.chapter_selections || [];

    for (const selection of saved) {
        const chapterId = selection.syllabus_chapter_id;
        next[chapterId] = {
            selected: true,
            topicIds: selection.syllabus_topic_ids ?? null,
        };
    }

    chapterSelections.value = next;
    expandedChapters.value = expanded;
};

const chapterById = (chapterId) => props.syllabusChapters.find((chapter) => chapter.id === chapterId);

const allTopicIdsForChapter = (chapterId) => chapterById(chapterId)?.topics?.map((topic) => topic.id) || [];

const isChapterSelected = (chapterId) => chapterSelections.value[chapterId]?.selected ?? false;

const isTopicExpanded = (chapterId) => expandedChapters.value[chapterId] ?? false;

const isTopicSelected = (chapterId, topicId) => {
    const selection = chapterSelections.value[chapterId];

    if (!selection?.selected) {
        return false;
    }

    if (selection.topicIds === null) {
        return true;
    }

    return selection.topicIds.includes(topicId);
};

const selectionSummary = (chapterId) => {
    const selection = chapterSelections.value[chapterId];
    const chapter = chapterById(chapterId);

    if (!selection?.selected) {
        return '';
    }

    if (selection.topicIds === null) {
        return 'Full chapter';
    }

    const total = chapter?.topics?.length || 0;

    return `${selection.topicIds.length} of ${total} topics`;
};

const syncFormSelections = () => {
    form.chapter_selections = Object.entries(chapterSelections.value)
        .filter(([, selection]) => selection.selected)
        .map(([chapterId, selection]) => ({
            syllabus_chapter_id: Number(chapterId),
            syllabus_topic_ids: selection.topicIds,
        }));
};

const toggleChapter = (chapterId) => {
    const current = chapterSelections.value[chapterId];

    if (current?.selected) {
        chapterSelections.value[chapterId] = { selected: false, topicIds: null };
        expandedChapters.value[chapterId] = false;
    } else {
        chapterSelections.value[chapterId] = { selected: true, topicIds: null };
    }

    syncFormSelections();
};

const toggleTopicPanel = (chapterId) => {
    if (!isChapterSelected(chapterId)) {
        return;
    }

    expandedChapters.value[chapterId] = !expandedChapters.value[chapterId];
};

const toggleTopic = (chapterId, topicId) => {
    const selection = chapterSelections.value[chapterId];
    const allTopicIds = allTopicIdsForChapter(chapterId);

    if (!selection?.selected) {
        return;
    }

    let current = selection.topicIds === null ? [...allTopicIds] : [...selection.topicIds];

    if (current.includes(topicId)) {
        current = current.filter((id) => id !== topicId);
    } else {
        current.push(topicId);
    }

    if (current.length === 0) {
        chapterSelections.value[chapterId] = { selected: false, topicIds: null };
        expandedChapters.value[chapterId] = false;
    } else if (current.length === allTopicIds.length) {
        chapterSelections.value[chapterId] = { selected: true, topicIds: null };
    } else {
        chapterSelections.value[chapterId] = { selected: true, topicIds: current };
    }

    syncFormSelections();
};

const assignSet = (plan, worksheetId) => {
    if (!props.studentId) {
        return;
    }

    assignForm.student_id = props.studentId;
    assignForm.target_date = dueDateForPlan(plan);
    assignForm.exam_plan_id = plan.id;
    assignForm.notes = '';

    assignForm.post(route('admin.practice-sets.assign', worksheetId), {
        preserveScroll: true,
    });
};

const openCreate = () => {
    editingPlan.value = null;
    assigningPlanId.value = null;
    form.defaults(emptyForm());
    form.reset();
    form.clearErrors();
    initChapterSelections();
    syncFormSelections();
    showForm.value = true;
};

const openEdit = (plan) => {
    editingPlan.value = plan;
    assigningPlanId.value = null;
    form.defaults({
        ...emptyForm(),
        exam_date: plan.exam_date,
        title: plan.title,
        exam_type: plan.exam_type,
        notes: plan.notes || '',
        obtained_marks: plan.obtained_marks ?? '',
        total_marks: plan.total_marks ?? '',
    });
    form.reset();
    form.clearErrors();
    initChapterSelections(plan);
    syncFormSelections();
    showForm.value = true;
};

const cancelForm = () => {
    showForm.value = false;
    editingPlan.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    syncFormSelections();
    submitErrors.value = {};

    if (!editingPlan.value && form.chapter_selections.length === 0) {
        submitErrors.value = {
            chapter_selections: 'Select at least one chapter for this exam.',
        };

        return;
    }

    if (editingPlan.value && form.chapter_selections.length === 0) {
        submitErrors.value = {
            chapter_selections: 'This exam must include at least one chapter.',
        };

        return;
    }

    const payload = {
        ...form.data(),
        obtained_marks: form.obtained_marks === '' ? null : Number(form.obtained_marks),
        total_marks: form.total_marks === '' ? null : Number(form.total_marks),
    };

    const options = {
        preserveScroll: true,
        onSuccess: () => cancelForm(),
        onError: (errors) => {
            submitErrors.value = errors;
        },
        onFinish: () => {
            submitProcessing.value = false;
        },
    };

    submitProcessing.value = true;

    if (editingPlan.value) {
        router.put(route(`${props.context}.exam-plans.update`, editingPlan.value.id), payload, options);

        return;
    }

    router.post(route(`${props.context}.exam-plans.store`), payload, options);
};

const removePlan = (plan) => {
    if (!window.confirm(`Remove exam plan "${plan.title}"?`)) {
        return;
    }

    form.delete(route(`${props.context}.exam-plans.destroy`, plan.id), {
        preserveScroll: true,
    });
};

watch(
    () => props.autoOpenCreate,
    (shouldOpen) => {
        if (shouldOpen && canAddExam.value && !showForm.value) {
            openCreate();
        }
    },
    { immediate: true },
);

watch(
    () => props.highlightPlanId,
    (planId) => {
        if (!planId || showForm.value) {
            return;
        }

        const plan = props.plans.find((row) => String(row.id) === String(planId));

        if (plan) {
            openEdit(plan);
        }
    },
    { immediate: true },
);

watch(
    () => props.plans,
    () => {
        syncMarksDraft();
    },
    { immediate: true, deep: true },
);

const onEscape = (event) => {
    if (event.key === 'Escape' && expandedPlanId.value !== null) {
        closePlanView();
    }
};

watch(expandedPlanId, (planId) => {
    if (planId !== null) {
        document.addEventListener('keydown', onEscape);
    } else {
        document.removeEventListener('keydown', onEscape);
    }
});

onUnmounted(() => {
    document.removeEventListener('keydown', onEscape);
    document.body.style.overflow = '';
});
</script>

<template>
    <div class="space-y-4">
        <div v-if="!compact" class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-medium text-gray-900">Exam plans</h3>
                <p v-if="isAdminContext" class="mt-1 text-sm text-gray-500">
                    Edit the exam date and chapters, then assign practice sheets and chapter tests for this student.
                </p>
                <p v-else class="mt-1 text-sm text-gray-500">
                    Add your school test date and chapters. Your teacher's assigned prep will show here.
                </p>
            </div>
            <PrimaryButton v-if="canAddExam && !showForm" type="button" @click="openCreate">
                Add exam date
            </PrimaryButton>
        </div>

        <div v-if="compact && canAddExam" class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-600">
                {{ isAdminContext ? 'Edit exam or assign chapter practice / tests.' : 'Add your upcoming exam.' }}
            </p>
            <PrimaryButton v-if="!showForm" type="button" @click="openCreate">
                Add exam date
            </PrimaryButton>
        </div>

        <div v-if="!canAddExam && !canEditPlans" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <template v-if="isAdminContext">
                Import the class syllabus first, then you can enter exam dates here.
            </template>
            <template v-else>
                Syllabus is not set up for your class yet. Ask your teacher to import the syllabus first.
            </template>
        </div>

        <div v-if="showForm && canManage" class="rounded-lg border border-indigo-200 bg-indigo-50/40 p-4">
            <h4 class="text-sm font-medium text-gray-900">
                {{ editingPlan ? 'Edit exam plan' : 'Add exam plan' }}
            </h4>

            <form class="mt-4 space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel value="Exam date" />
                        <TextInput v-model="form.exam_date" type="date" class="mt-1 block w-full" required />
                        <InputError class="mt-1" :message="form.errors.exam_date" />
                    </div>
                    <div>
                        <InputLabel value="Type" />
                        <select v-model="form.exam_type" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            <option v-for="opt in examTypeOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.exam_type" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Title" />
                        <TextInput v-model="form.title" class="mt-1 block w-full" placeholder="Unit test 1" required />
                        <InputError class="mt-1" :message="form.errors.title" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Chapters in this exam" />
                        <div class="mt-2 max-h-80 space-y-2 overflow-y-auto rounded-md border border-gray-300 bg-white p-3">
                            <div
                                v-for="chapter in syllabusChapters"
                                :key="chapter.id"
                                class="rounded-lg border border-gray-100 p-3"
                            >
                                <div class="flex items-start gap-3">
                                    <input
                                        type="checkbox"
                                        class="mt-1 rounded border-gray-300 text-indigo-600"
                                        :checked="isChapterSelected(chapter.id)"
                                        @change="toggleChapter(chapter.id)"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <label class="block text-sm font-medium text-gray-900">{{ chapter.label }}</label>
                                        <p v-if="isChapterSelected(chapter.id)" class="mt-0.5 text-xs text-gray-500">
                                            {{ selectionSummary(chapter.id) }}
                                        </p>
                                        <button
                                            v-if="isChapterSelected(chapter.id) && chapter.topics?.length"
                                            type="button"
                                            class="mt-1 text-xs font-medium text-indigo-600 hover:underline"
                                            @click="toggleTopicPanel(chapter.id)"
                                        >
                                            {{ isTopicExpanded(chapter.id) ? 'Hide topics' : 'Select topics' }}
                                        </button>
                                    </div>
                                </div>

                                <div
                                    v-if="isChapterSelected(chapter.id) && isTopicExpanded(chapter.id) && chapter.topics?.length"
                                    class="mt-3 ml-7 space-y-1.5 border-l border-gray-200 pl-3"
                                >
                                    <label
                                        v-for="topic in chapter.topics"
                                        :key="topic.id"
                                        class="flex cursor-pointer items-center gap-2 text-sm text-gray-700"
                                    >
                                        <input
                                            type="checkbox"
                                            class="rounded border-gray-300 text-indigo-600"
                                            :checked="isTopicSelected(chapter.id, topic.id)"
                                            @change="toggleTopic(chapter.id, topic.id)"
                                        />
                                        {{ topic.name }}
                                    </label>
                                    <p class="text-[11px] text-gray-400">
                                        Leave all topics ticked for the full chapter, or untick topics to narrow the exam scope.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <InputError class="mt-1" :message="form.errors.chapter_selections" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Notes (optional)" />
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                            placeholder="Anything your teacher should know"
                        />
                        <InputError class="mt-1" :message="form.errors.notes" />
                    </div>
                    <div>
                        <InputLabel value="Marks obtained (optional)" />
                        <TextInput
                            v-model="form.obtained_marks"
                            type="number"
                            min="0"
                            class="mt-1 block w-full"
                            placeholder="e.g. 42"
                        />
                        <InputError class="mt-1" :message="form.errors.obtained_marks" />
                    </div>
                    <div>
                        <InputLabel value="Total marks (optional)" />
                        <TextInput
                            v-model="form.total_marks"
                            type="number"
                            min="1"
                            class="mt-1 block w-full"
                            placeholder="e.g. 50"
                        />
                        <InputError class="mt-1" :message="form.errors.total_marks" />
                    </div>
                    <p class="sm:col-span-2 text-xs text-gray-500">
                        Enter your school test result after the exam. Leave blank until you have the marks.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <PrimaryButton type="submit" :disabled="form.processing">
                        {{ editingPlan ? 'Save changes' : 'Save exam plan' }}
                    </PrimaryButton>
                    <SecondaryButton type="button" @click="cancelForm">Cancel</SecondaryButton>
                </div>
            </form>
        </div>

        <div v-if="plans.length === 0 && !showForm" class="rounded-lg border border-dashed border-gray-200 p-4 text-sm text-gray-500">
            No exam plans yet.
        </div>

        <div v-else-if="plans.length" class="space-y-3">
            <div
                v-for="plan in plans"
                :id="`exam-plan-${plan.id}`"
                :key="plan.id"
                class="scroll-mt-4 overflow-hidden rounded-lg border border-gray-200 bg-white"
                :class="[
                    highlightPlanId === plan.id ? 'ring-2 ring-emerald-400' : '',
                    compact ? 'cursor-pointer transition hover:border-indigo-300 hover:shadow-sm' : '',
                ]"
                @click="compact ? openPlanView(plan) : undefined"
            >
                <div
                    class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 bg-gray-50"
                    :class="compact ? 'px-3 py-2.5' : 'px-4 py-3'"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <span class="font-semibold text-indigo-700" :class="compact ? 'text-xs' : 'text-sm'">{{ formatDate(plan.exam_date) }}</span>
                            <span class="font-semibold text-gray-900" :class="compact ? 'text-sm' : 'text-base'">{{ plan.title }}</span>
                            <span class="text-xs text-gray-500">{{ plan.exam_type_label }}</span>
                        </div>
                        <p v-if="plan.has_marks" class="mt-1 font-semibold text-emerald-700" :class="compact ? 'text-xs' : 'text-sm'">
                            Result: {{ marksScoreLabel(plan) }}
                        </p>
                        <p v-else-if="planHasChapterRows(plan)" class="mt-1 text-xs text-gray-500">
                            {{ plan.chapter_names.length }} chapter{{ plan.chapter_names.length === 1 ? '' : 's' }}
                            <span v-if="plan.prep_summary"> · {{ plan.prep_summary.completed }}/{{ plan.prep_summary.total }} done</span>
                        </p>
                        <p v-else class="mt-1 text-sm text-gray-400">No chapters selected</p>
                        <p v-if="compact && planHasChapterRows(plan)" class="mt-1 text-[11px] font-medium text-indigo-600">
                            Click to open full chapter table
                        </p>
                    </div>
                    <div v-if="canManage" class="flex shrink-0 flex-wrap justify-end gap-x-3 gap-y-1 text-sm" @click.stop>
                        <button type="button" class="text-indigo-600 hover:underline" @click="openEdit(plan)">
                            Edit
                        </button>
                        <button
                            v-if="isAdminContext"
                            type="button"
                            class="font-medium text-indigo-600 hover:underline"
                            @click="toggleAssign(plan)"
                        >
                            {{ assigningPlanId === plan.id ? 'Close assign' : 'Assign sheets' }}
                        </button>
                        <button
                            v-else
                            type="button"
                            class="text-red-600 hover:underline"
                            @click="removePlan(plan)"
                        >
                            Remove
                        </button>
                    </div>
                </div>

                <template v-if="!compact">
                    <ExamPlanChapterTable
                        :plan="plan"
                        :rows="chapterPrepRows(plan)"
                        :has-chapters="planHasChapterRows(plan)"
                        :prep-summary="plan.prep_summary"
                        :is-admin-context="isAdminContext"
                        :assigning-plan-id="assigningPlanId"
                        :assign-due-date="dueDateForPlan(plan)"
                        :assign-processing="assignForm.processing"
                        @update:assign-due-date="assignDueDates[plan.id] = $event"
                        @assign-set="assignSet(plan, $event)"
                    />

                    <div
                        v-if="!isAdminContext && marksDraft[plan.id]"
                        class="border-t border-gray-200 bg-emerald-50/40 px-4 py-3"
                    >
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-800">
                            Your school test marks
                        </p>
                        <p class="mt-1 text-xs text-gray-600">
                            Enter marks from your answer sheet — obtained and total for the test paper.
                            Leave blank until you have the result.
                        </p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Marks obtained" class="!text-xs" />
                                <TextInput
                                    v-model="marksDraft[plan.id].obtained_marks"
                                    type="number"
                                    min="0"
                                    class="mt-1 block w-full text-sm"
                                    placeholder="e.g. 42"
                                />
                            </div>
                            <div>
                                <InputLabel value="Total marks" class="!text-xs" />
                                <TextInput
                                    v-model="marksDraft[plan.id].total_marks"
                                    type="number"
                                    min="1"
                                    class="mt-1 block w-full text-sm"
                                    placeholder="e.g. 50"
                                />
                            </div>
                        </div>
                        <InputError class="mt-2" :message="marksErrors.obtained_marks" />
                        <InputError class="mt-1" :message="marksErrors.total_marks" />
                        <PrimaryButton
                            type="button"
                            class="mt-3 !py-1.5 !text-xs"
                            :disabled="savingMarksPlanId === plan.id"
                            @click="saveMarks(plan)"
                        >
                            {{ savingMarksPlanId === plan.id ? 'Saving…' : 'Save marks' }}
                        </PrimaryButton>
                    </div>

                    <div
                        v-else-if="isAdminContext && plan.has_marks"
                        class="border-t border-gray-200 bg-emerald-50/40 px-4 py-3 text-sm text-emerald-800"
                    >
                        <span class="font-medium">School test result:</span> {{ marksScoreLabel(plan) }}
                    </div>
                </template>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="compact && expandedPlan"
                class="fixed inset-0 z-[100] flex flex-col bg-slate-100"
            >
                <div class="border-b border-gray-200 bg-white px-4 py-4 shadow-sm sm:px-8">
                    <div class="mx-auto flex max-w-7xl flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Exam plan</p>
                            <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ expandedPlan.title }}</h2>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ formatDate(expandedPlan.exam_date) }}
                                · {{ expandedPlan.exam_type_label }}
                                <span v-if="expandedPlan.prep_summary">
                                    · {{ expandedPlan.prep_summary.completed }}/{{ expandedPlan.prep_summary.total }} sets done
                                </span>
                            </p>
                            <p v-if="expandedPlan.has_marks" class="mt-2 text-sm font-semibold text-emerald-700">
                                Result: {{ marksScoreLabel(expandedPlan) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-100"
                                @click="openEdit(expandedPlan)"
                            >
                                Edit
                            </button>
                            <button
                                v-if="isAdminContext"
                                type="button"
                                class="rounded-md border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-100"
                                @click="toggleAssign(expandedPlan)"
                            >
                                {{ assigningPlanId === expandedPlan.id ? 'Close assign' : 'Assign sheets' }}
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                @click="closePlanView"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto px-4 py-4 sm:px-8">
                    <div class="mx-auto max-w-7xl overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <ExamPlanChapterTable
                            wide
                            :plan="expandedPlan"
                            :rows="chapterPrepRows(expandedPlan)"
                            :has-chapters="planHasChapterRows(expandedPlan)"
                            :prep-summary="expandedPlan.prep_summary"
                            :is-admin-context="isAdminContext"
                            :assigning-plan-id="assigningPlanId"
                            :assign-due-date="dueDateForPlan(expandedPlan)"
                            :assign-processing="assignForm.processing"
                            @update:assign-due-date="assignDueDates[expandedPlan.id] = $event"
                            @assign-set="assignSet(expandedPlan, $event)"
                        />

                        <div
                            v-if="isAdminContext && expandedPlan.has_marks"
                            class="border-t border-gray-200 bg-emerald-50/40 px-6 py-4 text-sm text-emerald-800"
                        >
                            <span class="font-medium">School test result:</span> {{ marksScoreLabel(expandedPlan) }}
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
