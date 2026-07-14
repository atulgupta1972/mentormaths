<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    chapter: Object,
    gradeLevel: Object,
    board: Object,
    activeYear: Object,
    summary: Object,
    sets: { type: Array, default: () => [] },
});

const page = usePage();
const runningId = ref(null);

const chaptersIndexUrl = `${route('admin.question-audit.classes.show', props.gradeLevel.id)}?board_id=${props.board?.id}`;

const runAudit = (worksheetId) => {
    runningId.value = worksheetId;
    router.post(route('admin.question-audit.worksheets.run', worksheetId), {}, {
        preserveScroll: true,
        onFinish: () => {
            runningId.value = null;
        },
    });
};

const auditBadge = (set) => {
    if (set.audit_status === 'clean') {
        return { label: 'Clean', class: 'bg-green-100 text-green-800' };
    }
    if (set.audit_status === 'issues') {
        return { label: `${set.issue_count} issue${set.issue_count === 1 ? '' : 's'}`, class: 'bg-red-100 text-red-800' };
    }
    return { label: 'Not audited', class: 'bg-gray-100 text-gray-700' };
};

const formatWhen = (value) => {
    if (!value) return '—';
    return new Date(value).toLocaleString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>

<template>
    <Head :title="`Ch ${chapter.chapter_number} — Audit`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <Link :href="chaptersIndexUrl" class="text-sm text-indigo-600">
                    ← {{ board?.code }} {{ gradeLevel?.name }}
                </Link>
                <h2 class="mt-1 text-xl font-semibold text-gray-800">
                    Ch {{ chapter.chapter_number }} — {{ chapter.name }}
                </h2>
                <p class="text-sm text-gray-500">Run audit on each practice set or chapter test.</p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 p-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>

                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-indigo-600">{{ summary.total_sets }}</p>
                        <p class="text-xs text-gray-500">Sets / tests</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-amber-700">{{ summary.not_audited }}</p>
                        <p class="text-xs text-gray-500">Not audited</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-green-700">{{ summary.clean }}</p>
                        <p class="text-xs text-gray-500">Clean</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 text-center shadow-sm">
                        <p class="text-2xl font-bold text-red-700">{{ summary.issues }}</p>
                        <p class="text-xs text-gray-500">With issues</p>
                    </div>
                </div>

                <div v-if="!sets.length" class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">
                    No packaged sets or tests in this chapter yet.
                </div>

                <div v-else class="space-y-4">
                    <div
                        v-for="set in sets"
                        :key="set.id"
                        class="rounded-xl border bg-white p-5 shadow-sm"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="font-mono text-xl font-bold text-indigo-600">{{ set.set_code }}</span>
                                    <span
                                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="auditBadge(set).class"
                                    >
                                        {{ auditBadge(set).label }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-gray-600">
                                    {{ set.kind_label }} · {{ set.tier_label }} · {{ set.questions_count }} questions
                                    <span v-if="set.topic_name"> · {{ set.topic_name }}</span>
                                </p>
                                <p v-if="set.audited_at" class="mt-1 text-xs text-gray-500">
                                    Last audited {{ formatWhen(set.audited_at) }}
                                    <span v-if="set.audited_by"> by {{ set.audited_by }}</span>
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <Link
                                    v-if="set.audit_status !== 'not_audited'"
                                    :href="route('admin.question-audit.worksheets.show', set.id)"
                                    class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                >
                                    View results
                                </Link>
                                <PrimaryButton
                                    type="button"
                                    class="!py-2"
                                    :disabled="runningId === set.id"
                                    @click="runAudit(set.id)"
                                >
                                    {{ runningId === set.id ? 'Auditing…' : set.audit_status === 'not_audited' ? 'Run audit' : 'Re-run audit' }}
                                </PrimaryButton>
                                <Link
                                    :href="route('admin.questions.sets.show', set.id)"
                                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50"
                                >
                                    Open set
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
