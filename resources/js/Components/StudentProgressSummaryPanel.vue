<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    student: {
        type: Object,
        required: true,
    },
    defaultEmail: {
        type: String,
        default: '',
    },
});

const today = () => new Date().toISOString().slice(0, 10);

const form = useForm({
    as_of_date: today(),
    send_email: true,
    send_whatsapp: true,
    email: '',
});

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
            <div>
                <label class="text-sm font-medium text-gray-700">As on date</label>
                <input
                    v-model="form.as_of_date"
                    type="date"
                    class="mt-1 block w-full max-w-xs rounded-md border-gray-300 text-sm shadow-sm"
                />
                <InputError :message="form.errors.as_of_date" class="mt-1" />
            </div>

            <div class="flex flex-wrap gap-6 text-sm">
                <label class="flex items-center gap-2">
                    <Checkbox :checked="form.send_email" @update:checked="form.send_email = $event" />
                    Send email
                </label>
                <label class="flex items-center gap-2">
                    <Checkbox :checked="form.send_whatsapp" @update:checked="form.send_whatsapp = $event" />
                    Prepare WhatsApp (opens copy/send panel)
                </label>
            </div>

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
        </form>
    </div>
</template>
