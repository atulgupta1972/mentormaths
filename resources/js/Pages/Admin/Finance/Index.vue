<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    unpaid: { type: Array, default: () => [] },
    unpaid_total_inr: { type: Number, default: 0 },
    payments: { type: Array, default: () => [] },
    paid_total_inr: { type: Number, default: 0 },
});

const page = usePage();
const payingTaskId = ref(null);

const form = useForm({
    content_upload_task_id: null,
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

const openPayForm = (task) => {
    payingTaskId.value = task.id;
    form.content_upload_task_id = task.id;
    form.paid_on = new Date().toISOString().slice(0, 10);
    form.method = 'upi';
    form.upi_or_reference = '';
    form.notes = '';
    form.clearErrors();
};

const cancelPay = () => {
    payingTaskId.value = null;
    form.reset();
    form.content_upload_task_id = null;
};

const submitPayment = () => {
    form.post(route('admin.finance.payments.store'), {
        preserveScroll: true,
        onSuccess: () => {
            payingTaskId.value = null;
            form.reset();
            form.paid_on = new Date().toISOString().slice(0, 10);
            form.method = 'upi';
        },
    });
};

const unpaidCount = computed(() => props.unpaid.length);
</script>

<template>
    <Head title="Finance — content payments" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Accounts · Finance</h2>
                    <p class="text-sm text-gray-500">
                        Pay content uploaders for verified / published chapter work (UPI) and email them the details.
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
                                Verified / published chapters with an agreed rate and no payment yet.
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs uppercase tracking-wide text-amber-800">Total due</p>
                            <p class="text-2xl font-bold text-amber-950">{{ formatInr(unpaid_total_inr) }}</p>
                            <p class="text-xs text-amber-800">{{ unpaidCount }} chapter{{ unpaidCount === 1 ? '' : 's' }}</p>
                        </div>
                    </div>

                    <div v-if="unpaid.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">
                        No pending payments. Publish verified chapter work to see amounts here.
                    </div>

                    <div v-else class="divide-y divide-gray-100">
                        <div v-for="task in unpaid" :key="task.id" class="px-4 py-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium text-gray-900">{{ chapterLabel(task) }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ task.chapter?.textbook_name }}
                                        · {{ task.assignee?.name || 'Uploader' }}
                                        <span v-if="task.assignee?.email">({{ task.assignee.email }})</span>
                                    </p>
                                    <p class="mt-1 text-xs text-gray-500">{{ task.status_label }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <p class="text-lg font-semibold text-gray-900">{{ formatInr(task.amount_inr) }}</p>
                                    <PrimaryButton
                                        v-if="payingTaskId !== task.id"
                                        type="button"
                                        class="!text-xs"
                                        @click="openPayForm(task)"
                                    >
                                        Record UPI payment
                                    </PrimaryButton>
                                </div>
                            </div>

                            <div
                                v-if="payingTaskId === task.id"
                                class="mt-3 rounded-md border border-indigo-200 bg-indigo-50/60 p-3"
                            >
                                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-900">
                                    Payment details (sent by email to uploader)
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
                                            placeholder="e.g. UPI ref / UTR"
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
                                        {{ form.processing ? 'Saving…' : `Save & email ${formatInr(task.amount_inr)}` }}
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
                            <p class="text-xs text-emerald-900">Recent payments with UPI / reference details.</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs uppercase tracking-wide text-emerald-800">Shown total</p>
                            <p class="text-xl font-bold text-emerald-950">{{ formatInr(paid_total_inr) }}</p>
                        </div>
                    </div>

                    <div v-if="payments.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">
                        No payments recorded yet.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-gray-50 text-[10px] uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-2">Paid on</th>
                                    <th class="px-4 py-2">Chapter</th>
                                    <th class="px-4 py-2">Uploader</th>
                                    <th class="px-4 py-2">Amount</th>
                                    <th class="px-4 py-2">Method / ref</th>
                                    <th class="px-4 py-2">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="payment in payments" :key="payment.id">
                                    <td class="whitespace-nowrap px-4 py-2 text-gray-700">{{ payment.paid_on }}</td>
                                    <td class="px-4 py-2">
                                        <p class="font-medium text-gray-900">{{ chapterLabel(payment) }}</p>
                                        <p class="text-xs text-gray-500">{{ payment.chapter?.textbook_name }}</p>
                                    </td>
                                    <td class="px-4 py-2 text-gray-700">
                                        {{ payment.assignee?.name || '—' }}
                                        <p v-if="payment.assignee?.email" class="text-xs text-gray-500">
                                            {{ payment.assignee.email }}
                                        </p>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 font-semibold text-gray-900">
                                        {{ formatInr(payment.amount_inr) }}
                                    </td>
                                    <td class="px-4 py-2 text-gray-700">
                                        <span class="font-medium">{{ payment.method_label }}</span>
                                        <p class="font-mono text-xs">{{ payment.upi_or_reference }}</p>
                                    </td>
                                    <td class="px-4 py-2 text-xs text-gray-600">
                                        {{ payment.notes || '—' }}
                                        <p v-if="payment.emailed_at" class="mt-0.5 text-emerald-700">Emailed</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
