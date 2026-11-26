<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    messages: {
        type: Object,
        default: () => ({ data: [], links: [] }),
    },
    channels: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({ status: '', channel: '', q: '' }),
    },
    can_auto_send: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success || '');
const flashWarning = computed(() => page.props.flash?.warning || '');

const status = ref(props.filters.status || '');
const channel = ref(props.filters.channel || '');
const q = ref(props.filters.q || '');
const selectedId = ref(props.messages?.data?.[0]?.id ?? null);

const rows = computed(() => props.messages?.data ?? []);
const selected = computed(() => rows.value.find((row) => row.id === selectedId.value) ?? null);

const resendForm = useForm({
    mobile: '',
    use_current_mentor: true,
});

watch(
    () => props.messages?.data,
    (next) => {
        if (! Array.isArray(next) || next.length === 0) {
            selectedId.value = null;

            return;
        }

        if (! next.some((row) => row.id === selectedId.value)) {
            selectedId.value = next[0].id;
        }
    },
);

watch(selected, (row) => {
    if (! row) {
        resendForm.mobile = '';
        resendForm.use_current_mentor = true;

        return;
    }

    resendForm.mobile = row.resend_mobile || row.to_mobile || '';
    resendForm.use_current_mentor = true;
}, { immediate: true });

function applyFilters() {
    router.get(route('admin.whatsapp-outbox.index'), {
        status: status.value || undefined,
        channel: channel.value || undefined,
        q: q.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}

function statusClass(value) {
    if (value === 'sent') {
        return 'bg-green-100 text-green-800';
    }

    if (value === 'failed') {
        return 'bg-red-100 text-red-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function resendSelected() {
    if (! selected.value?.can_resend) {
        return;
    }

    resendForm.post(route('admin.whatsapp-outbox.resend', selected.value.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="WhatsApp outbox" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">
                    Setup
                </p>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    WhatsApp outbox
                </h2>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-600">
                        Sent, failed, and skipped WhatsApp messages logged by Mentor Maths.
                        <Link :href="route('admin.whatsapp-inbox.index')" class="font-medium text-teal-700 hover:underline">Open inbox</Link>
                    </p>
                </div>

                <p v-if="flashSuccess" class="rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-800">{{ flashSuccess }}</p>
                <p v-if="flashWarning" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ flashWarning }}</p>

                <form class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:grid-cols-4" @submit.prevent="applyFilters">
                    <div>
                        <label class="text-xs font-medium uppercase tracking-wide text-gray-500">Search</label>
                        <input
                            v-model="q"
                            type="search"
                            placeholder="Student, mobile, text"
                            class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                        />
                    </div>
                    <div>
                        <label class="text-xs font-medium uppercase tracking-wide text-gray-500">Status</label>
                        <select v-model="status" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">All</option>
                            <option value="sent">Sent</option>
                            <option value="failed">Failed</option>
                            <option value="skipped">Skipped</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium uppercase tracking-wide text-gray-500">Type</label>
                        <select v-model="channel" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">All</option>
                            <option v-for="option in channels" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button
                            type="submit"
                            class="w-full rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800"
                        >
                            Filter
                        </button>
                    </div>
                </form>

                <div class="grid overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm md:grid-cols-[22rem_1fr]">
                    <div class="border-b border-gray-200 md:border-b-0 md:border-r">
                        <p v-if="rows.length === 0" class="px-4 py-6 text-sm text-gray-500">
                            No WhatsApp sends logged yet.
                        </p>
                        <button
                            v-for="row in rows"
                            :key="row.id"
                            type="button"
                            class="block w-full border-b border-gray-100 px-4 py-3 text-left last:border-b-0"
                            :class="row.id === selectedId ? 'bg-teal-50' : 'hover:bg-gray-50'"
                            @click="selectedId = row.id"
                        >
                            <span class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm font-semibold text-gray-900">
                                    {{ row.student_name || row.recipient_label || 'WhatsApp' }}
                                </span>
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusClass(row.status)">
                                    {{ row.status }}
                                </span>
                            </span>
                            <span class="mt-0.5 block truncate text-xs text-gray-500">{{ row.channel_label }} · {{ row.to_mobile }}</span>
                            <span class="mt-1 block truncate text-xs text-gray-600">{{ row.message_body }}</span>
                            <span class="mt-1 block text-[11px] text-gray-400">{{ row.sent_at }}</span>
                        </button>
                    </div>

                    <div class="flex min-h-[28rem] flex-col">
                        <div v-if="!selected" class="px-4 py-6 text-sm text-gray-500">
                            Choose a sent message.
                        </div>
                        <template v-else>
                            <div class="border-b border-gray-200 px-4 py-3">
                                <p class="font-semibold text-gray-900">{{ selected.student_name || selected.recipient_label || 'WhatsApp send' }}</p>
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ selected.channel_label }} · {{ selected.recipient_label || 'Mobile' }} · {{ selected.to_mobile }}
                                </p>
                                <p class="mt-1 text-xs text-gray-400">{{ selected.sent_at }} IST</p>
                            </div>
                            <div class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
                                <div class="flex flex-wrap gap-2 text-xs">
                                    <span class="rounded-full px-2 py-0.5 font-semibold capitalize" :class="statusClass(selected.status)">
                                        {{ selected.status }}
                                    </span>
                                    <span v-if="selected.template_name" class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700">
                                        Template: {{ selected.template_name }}
                                    </span>
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700">
                                        Driver: {{ selected.driver }}
                                    </span>
                                </div>
                                <p v-if="selected.error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                                    {{ selected.error }}
                                </p>

                                <div
                                    v-if="selected.can_resend"
                                    class="rounded-lg border border-teal-200 bg-teal-50/70 p-3"
                                >
                                    <p class="text-sm font-medium text-teal-950">Send again</p>
                                    <p v-if="selected.resend_uses_current_mentor" class="mt-1 text-xs text-teal-900">
                                        Mentor mobile was updated — resend will use the current mentor number below.
                                    </p>
                                    <p v-else-if="!can_auto_send" class="mt-1 text-xs text-amber-800">
                                        WhatsApp auto-send is not ready on the server.
                                    </p>
                                    <label class="mt-3 block text-xs font-medium uppercase tracking-wide text-teal-800">Mobile</label>
                                    <input
                                        v-model="resendForm.mobile"
                                        type="tel"
                                        class="mt-1 w-full max-w-xs rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                    />
                                    <label
                                        v-if="selected.channel === 'assignment_completed' && selected.student_id"
                                        class="mt-2 flex items-center gap-2 text-xs text-teal-900"
                                    >
                                        <input
                                            v-model="resendForm.use_current_mentor"
                                            type="checkbox"
                                            class="rounded border-gray-300 text-teal-700"
                                        />
                                        Prefer current mentor mobile from student profile
                                    </label>
                                    <button
                                        type="button"
                                        class="mt-3 rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                                        :disabled="resendForm.processing || !can_auto_send || !resendForm.mobile"
                                        @click="resendSelected"
                                    >
                                        {{ resendForm.processing ? 'Sending…' : 'Resend WhatsApp' }}
                                    </button>
                                    <p v-if="resendForm.errors.mobile" class="mt-2 text-sm text-red-600">{{ resendForm.errors.mobile }}</p>
                                </div>

                                <pre class="whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-sm text-gray-800">{{ selected.message_body }}</pre>
                                <p v-if="selected.meta_message_id" class="font-mono text-xs text-gray-500">
                                    Meta id: {{ selected.meta_message_id }}
                                </p>
                                <p v-if="selected.student_id">
                                    <Link
                                        :href="route('admin.students.show', selected.student_id)"
                                        class="text-sm font-medium text-teal-700 hover:underline"
                                    >
                                        Open student profile
                                    </Link>
                                </p>
                            </div>
                        </template>
                    </div>
                </div>

                <div v-if="messages.links?.length > 3" class="flex flex-wrap gap-2">
                    <Link
                        v-for="link in messages.links"
                        :key="`${link.label}-${link.url}`"
                        :href="link.url || '#'"
                        class="rounded-md border px-3 py-1 text-sm"
                        :class="link.active ? 'border-teal-700 bg-teal-700 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                        :tabindex="link.url ? 0 : -1"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
