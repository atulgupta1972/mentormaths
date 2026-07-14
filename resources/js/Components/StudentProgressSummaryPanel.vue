<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ProgressSummaryTables from '@/Components/ProgressSummaryTables.vue';
import TextInput from '@/Components/TextInput.vue';
import {
    buildWhatsAppUrl,
    copyWhatsAppMessage,
    formatDisplayMobile,
} from '@/utils/whatsapp';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    student: {
        type: Object,
        required: true,
    },
    defaultEmail: {
        type: String,
        default: '',
    },
    whatsappRecipientCount: {
        type: Number,
        default: 0,
    },
});

const page = usePage();
const copiedKey = ref(null);
const whatsappPanel = ref(null);
const previewSummary = ref(null);
const previewLoading = ref(false);
const previewError = ref('');

const today = () => new Date().toISOString().slice(0, 10);

const form = useForm({
    as_of_date: today(),
    send_email: false,
    send_whatsapp: true,
    email: '',
});

const flashSuccess = computed(() => page.props.flash?.success);
const flashWarning = computed(() => page.props.flash?.warning);

const whatsappRows = computed(() => {
    const notifications = page.props.flash?.whatsapp_notifications;

    if (!Array.isArray(notifications)) {
        return [];
    }

    return notifications.map((notification, index) => ({
        ...notification,
        key: `${notification.mobile}-${index}`,
        url: buildWhatsAppUrl(notification.mobile, notification.message),
        displayMobile: formatDisplayMobile(notification.mobile),
    }));
});

watch(
    () => page.props.flash?.whatsapp_notifications,
    async (next) => {
        if (!Array.isArray(next) || next.length === 0) {
            return;
        }

        await nextTick();
        whatsappPanel.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    },
);

const loadPreview = async () => {
    if (!form.as_of_date) {
        previewSummary.value = null;
        previewError.value = '';

        return;
    }

    previewLoading.value = true;
    previewError.value = '';

    try {
        const url = route('admin.students.progress-summary-preview', {
            student: props.student.id,
            as_of_date: form.as_of_date,
        });
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const payload = await response.json();

        if (!response.ok) {
            previewSummary.value = null;
            previewError.value = payload.error || 'Could not load preview.';

            return;
        }

        previewSummary.value = payload.summary;
    } catch {
        previewSummary.value = null;
        previewError.value = 'Could not load preview.';
    } finally {
        previewLoading.value = false;
    }
};

watch(
    () => form.as_of_date,
    () => {
        loadPreview();
    },
    { immediate: true },
);

const copyMessage = async (row) => {
    const ok = await copyWhatsAppMessage(row.message);

    if (ok) {
        copiedKey.value = row.key;
        window.setTimeout(() => {
            if (copiedKey.value === row.key) {
                copiedKey.value = null;
            }
        }, 2500);
    }
};

const submit = () => {
    form.post(route('admin.students.send-progress-summary', props.student.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
        <div class="border-b border-gray-200 px-6 py-4">
            <h3 class="font-medium text-gray-900">Progress summary</h3>
            <p class="mt-1 text-sm text-gray-500">
                Send a snapshot of completed work, pending sets, overdue items, and help needed — as on a chosen date.
            </p>
        </div>

        <form class="space-y-4 p-6" @submit.prevent="submit">
            <div
                v-if="flashSuccess"
                class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900"
            >
                {{ flashSuccess }}
            </div>

            <div
                v-if="flashWarning"
                class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                {{ flashWarning }}
            </div>

            <div>
                <label class="text-sm font-medium text-gray-700">As on date</label>
                <input
                    v-model="form.as_of_date"
                    type="date"
                    class="mt-1 block w-full max-w-xs rounded-md border-gray-300 text-sm shadow-sm"
                />
                <InputError :message="form.errors.as_of_date" class="mt-1" />
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between gap-2">
                    <p class="text-sm font-medium text-gray-700">Preview</p>
                    <p v-if="previewLoading" class="text-xs text-gray-500">Loading…</p>
                </div>
                <p v-if="previewError" class="mb-2 text-sm text-rose-700">{{ previewError }}</p>
                <ProgressSummaryTables :summary="previewSummary" />
            </div>

            <div class="flex flex-wrap gap-6 text-sm">
                <label class="flex items-center gap-2">
                    <Checkbox :checked="form.send_email" @update:checked="form.send_email = $event" />
                    Send email
                </label>
                <label class="flex items-center gap-2">
                    <Checkbox :checked="form.send_whatsapp" @update:checked="form.send_whatsapp = $event" />
                    Prepare WhatsApp
                </label>
            </div>

            <p v-if="form.send_whatsapp && whatsappRecipientCount === 0" class="text-sm text-amber-800">
                No WhatsApp recipients yet. In <strong>Contact &amp; notification settings</strong> above, tick
                <strong>Notify</strong> on at least one mobile number and save.
            </p>
            <p v-else-if="form.send_whatsapp" class="text-sm text-gray-600">
                {{ whatsappRecipientCount }} WhatsApp recipient{{ whatsappRecipientCount === 1 ? '' : 's' }} saved for notify.
            </p>

            <div v-if="form.send_email">
                <label class="text-sm font-medium text-gray-700">Email to (optional)</label>
                <TextInput
                    v-model="form.email"
                    type="email"
                    class="mt-1 block w-full max-w-md text-sm"
                    :placeholder="defaultEmail || 'Uses student profile email if blank'"
                />
                <p v-if="defaultEmail" class="mt-1 text-xs text-gray-500">
                    Default: {{ defaultEmail }}
                </p>
                <InputError :message="form.errors.email" class="mt-1" />
            </div>

            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <strong>Weekly auto-email:</strong> every Sunday at 6:00 PM IST to students with an email on file.
                Run manually on server: <code class="text-xs">php artisan students:send-weekly-summaries</code>
            </div>

            <PrimaryButton type="submit" :disabled="form.processing">
                Send progress summary
            </PrimaryButton>

            <div
                v-if="whatsappRows.length > 0"
                ref="whatsappPanel"
                class="rounded-lg border border-green-300 bg-green-50 p-4"
            >
                <p class="font-medium text-green-900">Send progress summary on WhatsApp</p>
                <p class="mt-1 text-sm text-green-800">
                    Click <strong>Copy message</strong>, open WhatsApp on your phone, paste and send to each contact.
                </p>

                <ul class="mt-4 space-y-3">
                    <li
                        v-for="row in whatsappRows"
                        :key="row.key"
                        class="rounded-lg border border-green-200 bg-white p-3"
                    >
                        <p class="text-sm font-medium text-gray-900">{{ row.label }}</p>
                        <p class="text-xs text-gray-500">{{ row.displayMobile }}</p>

                        <div class="mt-2 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                                @click="copyMessage(row)"
                            >
                                {{ copiedKey === row.key ? 'Copied!' : 'Copy message' }}
                            </button>
                            <a
                                v-if="row.url"
                                :href="row.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center rounded-md border border-green-600 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-50"
                            >
                                Open WhatsApp
                            </a>
                        </div>

                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs text-gray-500 hover:text-gray-700">
                                Preview message
                            </summary>
                            <pre class="mt-1 max-h-40 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-2 text-xs text-gray-700">{{ row.message }}</pre>
                        </details>
                    </li>
                </ul>
            </div>
        </form>
    </div>
</template>
