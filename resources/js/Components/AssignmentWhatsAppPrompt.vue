<script setup>
import { computed, ref } from 'vue';
import {
    buildWhatsAppUrl,
    copyWhatsAppMessage,
    formatDisplayMobile,
} from '@/utils/whatsapp';
import { useAssignmentWhatsAppFlash } from '@/composables/useAssignmentWhatsAppFlash';

const { visible, notifications, dismiss } = useAssignmentWhatsAppFlash();
const copiedKey = ref(null);

const rows = computed(() =>
    notifications.value.map((notification, index) => ({
        ...notification,
        key: `${notification.mobile}-${index}`,
        url: buildWhatsAppUrl(notification.mobile, notification.message),
        displayMobile: formatDisplayMobile(notification.mobile),
    })),
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
</script>

<template>
    <div
        v-if="visible"
        class="border-b border-green-200 bg-green-50 px-4 py-3 sm:px-6"
        role="status"
    >
        <div class="mx-auto flex max-w-7xl flex-wrap items-start justify-between gap-3">
            <div>
                <p class="font-medium text-green-900">Send on WhatsApp</p>
                <p class="mt-0.5 text-sm text-green-800">
                    <strong>Recommended:</strong> click <strong>Copy message</strong>, open WhatsApp on your
                    <strong>phone</strong>, find the parent chat, paste and send.
                    If WhatsApp Web or Desktop gets stuck on “Connecting…”, ignore it and use your phone instead.
                </p>
            </div>
            <button
                type="button"
                class="shrink-0 text-sm text-green-700 underline hover:text-green-900"
                @click="dismiss"
            >
                Dismiss
            </button>
        </div>

        <ul class="mx-auto mt-3 flex max-w-7xl flex-col gap-3">
            <li
                v-for="row in rows"
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
                        Try open WhatsApp
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
</template>
