<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { openWhatsApp, openWhatsAppBatch, normalizeWhatsAppNumber, copyWhatsAppMessage } from '@/utils/whatsapp';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    studentName: { type: String, required: true },
    contacts: {
        type: Array,
        required: true,
    },
    saveUrl: { type: String, default: null },
    shareLinks: {
        type: Object,
        default: () => ({}),
    },
    editable: { type: Boolean, default: true },
});

const editing = ref(false);
const messageType = ref('progress');
const copiedContactKey = ref(null);

const form = useForm({
    student_mobile: '',
    parent1_mobile: '',
    parent2_mobile: '',
    notify_student_mobile: false,
    notify_parent1_mobile: true,
    notify_parent2_mobile: false,
});

const resetFormFromContacts = () => {
    for (const contact of props.contacts) {
        if (contact.field) {
            form[contact.field] = contact.mobile || '';
        }
        if (contact.notifyField) {
            form[contact.notifyField] = !!contact.notify;
        }
    }
};

watch(() => props.contacts, resetFormFromContacts, { immediate: true, deep: true });

const contactRows = computed(() =>
    props.contacts
        .filter((c) => c.field)
        .map((c) => ({
            ...c,
            mobile: editing.value ? form[c.field] : c.mobile,
            notify: form[c.notifyField],
            whatsappReady: normalizeWhatsAppNumber(editing.value ? form[c.field] : c.mobile),
        })),
);

const notifyEnabledRows = computed(() =>
    contactRows.value.filter((c) => c.notify && c.whatsappReady),
);

const allNotifySelected = computed(
    () =>
        contactRows.value.filter((c) => c.whatsappReady).length > 0
        && contactRows.value.filter((c) => c.whatsappReady).every((c) => c.notify),
);

const toggleNotifyAll = (checked) => {
    for (const contact of props.contacts) {
        if (contact.notifyField) {
            form[contact.notifyField] = checked && normalizeWhatsAppNumber(
                editing.value ? form[contact.field] : contact.mobile,
            );
        }
    }
};

const toggleNotify = (notifyField, checked, mobile) => {
    if (checked && !normalizeWhatsAppNumber(mobile)) {
        return;
    }

    form[notifyField] = checked;
};

const buildMessage = () => {
    const name = props.studentName;
    const login = props.shareLinks.login || window.location.origin + '/login';
    const dashboard = props.shareLinks.dashboard || login;

    if (messageType.value === 'assignment') {
        return `Hello, this is Mentor Maths.\n\nAssignment / practice link for ${name}:\n${dashboard}\n\nLogin: ${login}\n\nThank you.`;
    }

    if (messageType.value === 'report') {
        return `Hello, this is Mentor Maths.\n\nReport card / progress summary for ${name} is ready.\n\nView details here:\n${dashboard}\n\nLogin: ${login}\n\nThank you.`;
    }

    return `Hello, this is Mentor Maths.\n\nProgress update for ${name}.\n\nView assignments and results:\n${dashboard}\n\nThank you.`;
};

const copyForContact = async (contact) => {
    const ok = await copyWhatsAppMessage(buildMessage());

    if (ok) {
        copiedContactKey.value = contact.key;
        window.setTimeout(() => {
            if (copiedContactKey.value === contact.key) {
                copiedContactKey.value = null;
            }
        }, 2500);
    }
};

const sendToOne = (contact) => {
    if (!contact.whatsappReady) {
        return;
    }

    openWhatsApp(contact.mobile, buildMessage());
};

const sendToSavedRecipients = () => {
    openWhatsAppBatch(notifyEnabledRows.value, buildMessage());
};

const startEdit = () => {
    resetFormFromContacts();
    editing.value = true;
};

const cancelEdit = () => {
    resetFormFromContacts();
    editing.value = false;
};

const saveSettings = () => {
    if (!props.saveUrl) {
        return;
    }

    form.patch(props.saveUrl, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
};
</script>

<template>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4">
            <div>
                <h3 class="font-medium text-gray-900">Contact &amp; notification settings</h3>
                <p class="text-sm text-gray-500">
                    Choose who receives alerts when work is completed or you send a report
                </p>
            </div>
            <div v-if="editable && saveUrl" class="flex gap-2">
                <SecondaryButton v-if="!editing" type="button" @click="startEdit">Edit numbers</SecondaryButton>
                <template v-else>
                    <SecondaryButton type="button" @click="cancelEdit">Cancel edit</SecondaryButton>
                </template>
            </div>
        </div>

        <form class="space-y-4 p-6" @submit.prevent="saveSettings">
            <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
                <strong>Notify:</strong> tick the numbers that should automatically receive messages
                (assignment done, progress report, report card). Then click
                <strong>Save notification settings</strong> below.
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm font-medium text-gray-800">
                    <Checkbox :checked="allNotifySelected" @update:checked="toggleNotifyAll" />
                    Notify all numbers
                </label>
                <span class="text-xs text-gray-500">
                    {{ notifyEnabledRows.length }} selected for notifications
                </span>
            </div>

            <div class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                <div
                    v-for="contact in contactRows"
                    :key="contact.key"
                    class="flex flex-wrap items-center gap-3 px-4 py-3"
                >
                    <div class="flex w-24 shrink-0 flex-col items-start gap-1">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Notify</span>
                        <Checkbox
                            :checked="contact.notify"
                            :disabled="!contact.whatsappReady"
                            @update:checked="toggleNotify(contact.notifyField, $event, contact.mobile)"
                        />
                    </div>
                    <div class="min-w-[7rem] flex-1">
                        <p class="text-sm font-medium text-gray-900">{{ contact.label }}</p>
                        <p v-if="contact.name" class="text-xs text-gray-500">{{ contact.name }}</p>
                    </div>
                    <div class="w-full sm:w-48">
                        <TextInput
                            v-if="editing"
                            v-model="form[contact.field]"
                            type="tel"
                            class="block w-full text-sm"
                            :placeholder="contact.required ? 'Required' : 'Optional'"
                        />
                        <p v-else class="text-sm text-gray-800">
                            {{ contact.mobile || '—' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center rounded-full px-3 py-1.5 text-sm font-medium bg-indigo-100 text-indigo-800 hover:bg-indigo-200"
                            @click="copyForContact(contact)"
                        >
                            {{ copiedContactKey === contact.key ? 'Copied!' : 'Copy msg' }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-sm font-medium"
                            :class="contact.whatsappReady
                                ? 'bg-green-100 text-green-800 hover:bg-green-200'
                                : 'cursor-not-allowed bg-gray-100 text-gray-400'"
                            :disabled="!contact.whatsappReady"
                            @click="sendToOne(contact)"
                        >
                            Open WhatsApp
                        </button>
                    </div>
                </div>
            </div>

            <InputError :message="form.errors.student_mobile || form.errors.parent1_mobile || form.errors.parent2_mobile" />
            <InputError :message="form.errors.notify_student_mobile || form.errors.notify_parent1_mobile || form.errors.notify_parent2_mobile" />

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4">
                <PrimaryButton type="submit" :disabled="form.processing">
                    Save notification settings
                </PrimaryButton>
                <p v-if="form.recentlySuccessful" class="text-sm font-medium text-green-700">
                    Saved — these numbers will be used for future alerts.
                </p>
            </div>
        </form>

        <div class="space-y-4 border-t border-gray-200 bg-gray-50 px-6 py-4">
            <p class="text-sm font-medium text-gray-800">Send a message now (manual WhatsApp)</p>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <label class="font-medium text-gray-700">Message type:</label>
                <label class="flex items-center gap-2">
                    <input v-model="messageType" type="radio" value="progress" class="text-indigo-600" />
                    Progress update
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="messageType" type="radio" value="assignment" class="text-indigo-600" />
                    Assignment link
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="messageType" type="radio" value="report" class="text-indigo-600" />
                    Report card
                </label>
            </div>
            <SecondaryButton
                type="button"
                :disabled="notifyEnabledRows.length === 0"
                @click="sendToSavedRecipients"
            >
                WhatsApp saved recipients ({{ notifyEnabledRows.length }})
            </SecondaryButton>
            <p class="text-xs text-gray-500">
                Copy message works even when WhatsApp Web is stuck. Paste in WhatsApp on your phone if needed.
            </p>
        </div>
    </div>
</template>
