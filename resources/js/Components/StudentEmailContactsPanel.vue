<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    student: {
        type: Object,
        required: true,
    },
    loginEmail: {
        type: String,
        default: '',
    },
    saveUrl: {
        type: String,
        required: true,
    },
    summaryEmailRecipients: {
        type: Array,
        default: () => [],
    },
});

const editing = ref(false);
const page = usePage();

const flashSuccess = computed(() => page.props.flash?.success);
const flashWarning = computed(() => page.props.flash?.warning);

const form = useForm({
    email: props.student.email || '',
    parent1_email: props.student.parent1_email || '',
    parent2_email: props.student.parent2_email || '',
    notify_contact_email: props.student.notify_contact_email ?? true,
    notify_login_email: props.student.notify_login_email ?? true,
    notify_parent1_email: props.student.notify_parent1_email ?? true,
    notify_parent2_email: props.student.notify_parent2_email ?? false,
});

watch(
    () => props.student,
    (student) => {
        form.email = student.email || '';
        form.parent1_email = student.parent1_email || '';
        form.parent2_email = student.parent2_email || '';
        form.notify_contact_email = student.notify_contact_email ?? true;
        form.notify_login_email = student.notify_login_email ?? true;
        form.notify_parent1_email = student.notify_parent1_email ?? true;
        form.notify_parent2_email = student.notify_parent2_email ?? false;
    },
    { deep: true },
);

const emailRows = computed(() => [
    {
        key: 'contact',
        label: 'Contact email',
        field: 'email',
        notifyField: 'notify_contact_email',
        value: editing.value ? form.email : props.student.email,
        notify: form.notify_contact_email,
        hint: 'Primary email for progress reports',
    },
    {
        key: 'login',
        label: 'Student login email',
        notifyField: 'notify_login_email',
        value: props.loginEmail,
        notify: form.notify_login_email,
        readOnly: true,
        hint: props.loginEmail ? 'From student login account' : 'No login linked',
    },
    {
        key: 'parent1',
        label: 'Parent 1 email',
        field: 'parent1_email',
        notifyField: 'notify_parent1_email',
        value: editing.value ? form.parent1_email : props.student.parent1_email,
        notify: form.notify_parent1_email,
        hint: props.student.parent1_name ? props.student.parent1_name : null,
    },
    {
        key: 'parent2',
        label: 'Parent 2 email',
        field: 'parent2_email',
        notifyField: 'notify_parent2_email',
        value: editing.value ? form.parent2_email : props.student.parent2_email,
        notify: form.notify_parent2_email,
        hint: props.student.parent2_name ? props.student.parent2_name : null,
    },
]);

const notifyEnabledCount = computed(() =>
    emailRows.value.filter((row) => row.notify && row.value).length,
);

const save = () => {
    form.patch(props.saveUrl, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
};

const sendEmailForm = useForm({
    as_of_date: new Date().toISOString().slice(0, 10),
    send_email: true,
    send_whatsapp: false,
});

const sendProgressEmailNow = () => {
    if (!confirm('Send progress report email now to all included addresses? Admin will be CC\'d and PDF attached.')) {
        return;
    }

    sendEmailForm.post(route('admin.students.send-progress-summary', props.student.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4">
            <div>
                <h3 class="font-medium text-gray-900">Email contacts</h3>
                <p class="mt-1 text-sm text-gray-500">
                    Weekly progress reports go to all ticked emails. Admin is CC'd automatically.
                </p>
            </div>
            <SecondaryButton v-if="!editing" type="button" @click="editing = true">
                Edit emails
            </SecondaryButton>
        </div>

        <form class="space-y-4 p-6" @submit.prevent="save">
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

            <div class="space-y-3">
                <div
                    v-for="row in emailRows"
                    :key="row.key"
                    class="rounded-lg border border-gray-200 p-3"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900">{{ row.label }}</p>
                            <p v-if="row.hint" class="text-xs text-gray-500">{{ row.hint }}</p>
                            <TextInput
                                v-if="editing && row.field"
                                v-model="form[row.field]"
                                type="email"
                                class="mt-2 block w-full max-w-md text-sm"
                                placeholder="name@example.com"
                            />
                            <p v-else class="mt-1 text-sm text-gray-700">
                                {{ row.value || '—' }}
                            </p>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <Checkbox
                                :checked="form[row.notifyField]"
                                :disabled="!editing || row.readOnly"
                                @update:checked="form[row.notifyField] = $event"
                            />
                            Include
                        </label>
                    </div>
                </div>
            </div>

            <p class="text-sm text-gray-600">
                {{ notifyEnabledCount }} email recipient{{ notifyEnabledCount === 1 ? '' : 's' }} selected for auto reports.
            </p>

            <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                <PrimaryButton
                    type="button"
                    :disabled="sendEmailForm.processing || notifyEnabledCount === 0"
                    @click="sendProgressEmailNow"
                >
                    {{ sendEmailForm.processing ? 'Sending…' : 'Send progress email now' }}
                </PrimaryButton>
                <p v-if="notifyEnabledCount === 0" class="text-sm text-amber-800">
                    Add at least one email and tick Include to send manually.
                </p>
                <p v-else class="text-xs text-gray-500">
                    Sends today's report with PDF to included addresses (admin CC'd).
                </p>
            </div>

            <div v-if="editing" class="flex flex-wrap gap-2">
                <PrimaryButton type="submit" :disabled="form.processing">
                    Save email settings
                </PrimaryButton>
                <SecondaryButton type="button" @click="editing = false; form.reset()">
                    Cancel
                </SecondaryButton>
            </div>

            <InputError :message="form.errors.email" />
            <InputError :message="form.errors.parent1_email" />
            <InputError :message="form.errors.parent2_email" />
        </form>
    </div>
</template>
