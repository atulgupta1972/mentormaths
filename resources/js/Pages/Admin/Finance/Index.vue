<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    unpaid_groups: { type: Array, default: () => [] },
    unpaid_total_inr: { type: Number, default: 0 },
    unpaid_chapter_count: { type: Number, default: 0 },
    payment_groups: { type: Array, default: () => [] },
    paid_total_inr: { type: Number, default: 0 },
});

const page = usePage();
const payingGroupKey = ref(null);
const expandedGroupKeys = ref(new Set());
const expandedPaidKeys = ref(new Set());

const form = useForm({
    content_upload_task_ids: [],
    paid_on: new Date().toISOString().slice(0, 10),
    method: 'upi',
    upi_or_reference: '',
    notes: '',
});

const formatInr = (amount) => `₹${Number(amount || 0).toLocaleString('en-IN')}`;

const chapterLabel = (row) => {
    const ch = row.chapter;
    if (!ch) {
        return 'Chapter';
    }

    return `${ch.grade_name || ''} · Ch ${ch.chapter_number} — ${ch.title}`.trim();
};

const groupKey = (group) => String(group.assignee?.id ?? 'unknown');

const isGroupExpanded = (group) => expandedGroupKeys.value.has(groupKey(group));

const toggleGroupChapters = (group) => {
    const key = groupKey(group);
    const next = new Set(expandedGroupKeys.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    expandedGroupKeys.value = next;
};

const isPaidExpanded = (group) => expandedPaidKeys.value.has(paidGroupKey(group));

const paidGroupKey = (group) => group.batch_id || `single-${group.payments?.[0]?.id ?? 'x'}`;

const togglePaidChapters = (group) => {
    const key = paidGroupKey(group);
    const next = new Set(expandedPaidKeys.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    expandedPaidKeys.value = next;
};

const openPayForm = (group) => {
    payingGroupKey.value = groupKey(group);
    form.content_upload_task_ids = [...(group.task_ids || [])];
    form.paid_on = new Date().toISOString().slice(0, 10);
    form.method = 'upi';
    form.upi_or_reference = '';
    form.notes = '';
    form.clearErrors();
};

const cancelPay = () => {
    payingGroupKey.value = null;
    form.reset();
    form.content_upload_task_ids = [];
    form.paid_on = new Date().toISOString().slice(0, 10);
    form.method = 'upi';
};

const activePayGroup = computed(() => props.unpaid_groups.find((g) => groupKey(g) === payingGroupKey.value) ?? null);

const submitPayment = () => {
    form.post(route('admin.finance.payments.store'), {
        preserveScroll: true,
        onSuccess: () => {
            payingGroupKey.value = null;
            form.reset();
            form.paid_on = new Date().toISOString().slice(0, 10);
            form.method = 'upi';
            form.content_upload_task_ids = [];
        },
    });
};

const uploaderCount = computed(() => props.unpaid_groups.length);
</script>

<template>
    <Head title="Finance — content payments" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Accounts · Finance</h2>
                    <p class="text-sm text-gray-500">
                        Pay content uploaders for verified / published chapter work — one UPI transfer per uploader, clubbed by person.
                    </p>
                </div>
                <Link :href="route('admin.content-tasks.index')" class="text-sm text-indigo-600 hover:underline">
                    Content tasks →
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6">
                <div
                    v-if="page.props.flash?.success"
                    class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950"
                >
                    {{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash?.error"
                    class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-950"
                >
                    {{ page.props.flash.error }}
                </div>

                <section class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-amber-50 px-4 py-3">
                        <div>
                            <h3 class="text-sm font-semibold text-amber-950">Payments to be done</h3>
                            <p class="text-xs text-amber-900">
                                Grouped by uploader — record one combined UPI payment for all their pending chapters.
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs uppercase tracking-wide text-amber-800">Total due</p>
                            <p class="text-2xl font-bold text-amber-950">{{ formatInr(unpaid_total_inr) }}</p>
                            <p class="text-xs text-amber-800">
                                {{ unpaid_chapter_count }} chapter{{ unpaid_chapter_count === 1 ? '' : 's' }}
                                · {{ uploaderCount }} uploader{{ uploaderCount === 1 ? '' : 's' }}
                            </p>
                        </div>
                    </div>

                    <div v-if="unpaid_groups.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">
                        No pending payments. Publish verified chapter work to see amounts here.
                    </div>

                    <div v-else class="divide-y divide-gray-100">
                        <div v-for="group in unpaid_groups" :key="groupKey(group)" class="px-4 py-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold text-gray-900">
                                        {{ group.assignee?.name || 'Uploader' }}
                                    </p>
                                    <p v-if="group.assignee?.email" class="text-xs text-gray-500">
                                        {{ group.assignee.email }}
                                    </p>
                                    <button
                                        type="button"
                                        class="mt-2 text-xs font-medium text-indigo-700 hover:underline"
                                        @click="toggleGroupChapters(group)"
                                    >
                                        {{ isGroupExpanded(group) ? 'Hide' : 'Show' }}
                                        {{ group.task_count }} chapter{{ group.task_count === 1 ? '' : 's' }}
                                    </button>
                                    <ul v-if="isGroupExpanded(group)" class="mt-2 space-y-1 border-l-2 border-amber-200 pl-3">
                                        <li
                                            v-for="task in group.tasks"
                                            :key="task.id"
                                            class="text-xs text-gray-700"
                                        >
                                            <span class="font-medium">{{ chapterLabel(task) }}</span>
                                            <span class="text-gray-500"> · {{ formatInr(task.amount_inr) }}</span>
                                            <span class="text-gray-400"> · {{ task.status_label }}</span>
                                        </li>
                                    </ul>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <div class="text-right">
                                        <p class="text-lg font-bold text-gray-900">{{ formatInr(group.total_inr) }}</p>
                                        <p class="text-[10px] uppercase tracking-wide text-gray-500">
                                            {{ group.task_count }} × clubbed
                                        </p>
                                    </div>
                                    <PrimaryButton
                                        v-if="payingGroupKey !== groupKey(group)"
                                        type="button"
                                        class="!text-xs"
                                        @click="openPayForm(group)"
                                    >
                                        Record combined UPI
                                    </PrimaryButton>
                                </div>
                            </div>

                            <div
                                v-if="payingGroupKey === groupKey(group)"
                                class="mt-3 rounded-md border border-indigo-200 bg-indigo-50/60 p-3"
                            >
                                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-900">
                                    One UPI payment for {{ group.task_count }} chapter{{ group.task_count === 1 ? '' : 's' }}
                                    ({{ formatInr(group.total_inr) }}) — emailed to {{ group.assignee?.email }}
                                </p>
                                <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <InputLabel value="Paid on" />
                                        <input
                                            v-model="form.paid_on"
                                            type="date"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                        >
                                        <p v-if="form.errors.paid_on" class="mt-1 text-xs text-rose-700">{{ form.errors.paid_on }}</p>
                                    </div>
                                    <div>
                                        <InputLabel value="Method" />
                                        <select v-model="form.method" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                            <option value="upi">UPI</option>
                                            <option value="bank">Bank transfer</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <InputLabel value="UPI / reference / transaction ID" />
                                        <input
                                            v-model="form.upi_or_reference"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                            placeholder="e.g. UPI ref / UTR for the combined transfer"
                                        >
                                        <p v-if="form.errors.upi_or_reference" class="mt-1 text-xs text-rose-700">
                                            {{ form.errors.upi_or_reference }}
                                        </p>
                                    </div>
                                    <div class="sm:col-span-2 lg:col-span-4">
                                        <InputLabel value="Notes (optional)" />
                                        <input
                                            v-model="form.notes"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                                            placeholder="Any note for the uploader"
                                        >
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <PrimaryButton type="button" :disabled="form.processing" @click="submitPayment">
                                        {{ form.processing ? 'Saving…' : `Save & email ${formatInr(activePayGroup?.total_inr ?? group.total_inr)}` }}
                                    </PrimaryButton>
                                    <SecondaryButton type="button" :disabled="form.processing" @click="cancelPay">
                                        Cancel
                                    </SecondaryButton>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-emerald-50 px-4 py-3">
                        <div>
                            <h3 class="text-sm font-semibold text-emerald-950">Payments done</h3>
                            <p class="text-xs text-emerald-900">Grouped by uploader and UPI transfer.</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs uppercase tracking-wide text-emerald-800">Shown total</p>
                            <p class="text-xl font-bold text-emerald-950">{{ formatInr(paid_total_inr) }}</p>
                        </div>
                    </div>

                    <div v-if="payment_groups.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">
                        No payments recorded yet.
                    </div>

                    <div v-else class="divide-y divide-gray-100">
                        <div v-for="group in payment_groups" :key="paidGroupKey(group)" class="px-4 py-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-gray-900">{{ group.assignee?.name || '—' }}</p>
                                    <p v-if="group.assignee?.email" class="text-xs text-gray-500">{{ group.assignee.email }}</p>
                                    <p class="mt-1 text-xs text-gray-600">
                                        Paid {{ group.paid_on }}
                                        · {{ group.method_label }}
                                        · <span class="font-mono">{{ group.upi_or_reference }}</span>
                                    </p>
                                    <button
                                        v-if="group.chapter_count > 1"
                                        type="button"
                                        class="mt-1 text-xs font-medium text-indigo-700 hover:underline"
                                        @click="togglePaidChapters(group)"
                                    >
                                        {{ isPaidExpanded(group) ? 'Hide' : 'Show' }} {{ group.chapter_count }} chapters
                                    </button>
                                    <p v-else-if="group.payments?.[0]" class="mt-1 text-xs text-gray-700">
                                        {{ chapterLabel(group.payments[0]) }}
                                    </p>
                                    <ul v-if="isPaidExpanded(group)" class="mt-2 space-y-1 border-l-2 border-emerald-200 pl-3">
                                        <li
                                            v-for="payment in group.payments"
                                            :key="payment.id"
                                            class="text-xs text-gray-700"
                                        >
                                            {{ chapterLabel(payment) }} · {{ formatInr(payment.amount_inr) }}
                                        </li>
                                    </ul>
                                </div>
                                <div class="text-right">
                                    <p class="text-lg font-bold text-gray-900">{{ formatInr(group.total_inr) }}</p>
                                    <p v-if="group.chapter_count > 1" class="text-[10px] uppercase text-gray-500">
                                        {{ group.chapter_count }} chapters
                                    </p>
                                    <p v-if="group.emailed_at" class="text-xs text-emerald-700">Emailed</p>
                                    <p v-if="group.notes" class="mt-1 max-w-xs text-xs text-gray-500">{{ group.notes }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
