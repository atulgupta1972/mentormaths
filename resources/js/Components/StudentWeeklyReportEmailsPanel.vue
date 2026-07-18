<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    initialEmails: {
        type: String,
        default: '',
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const form = useForm({
    weekly_report_emails: props.initialEmails || '',
});

watch(
    () => props.initialEmails,
    (value) => {
        form.weekly_report_emails = value || '';
    },
);

const save = () => {
    form.patch(route('profile.weekly-report-emails.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <section class="rounded-xl border border-indigo-200 bg-gradient-to-br from-indigo-50 via-white to-sky-50 p-4 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-indigo-950">Parent emails for weekly report</h3>
                <p class="mt-1 text-xs text-indigo-900/80">
                    Add one or two parent email addresses (comma separated). We email a progress summary with PDF every Saturday.
                </p>
            </div>
        </div>

        <form class="mt-3 space-y-3" @submit.prevent="save">
            <div
                v-if="flashSuccess"
                class="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-900"
            >
                {{ flashSuccess }}
            </div>

            <TextInput
                v-model="form.weekly_report_emails"
                type="text"
                class="block w-full text-sm"
                placeholder="parent1@example.com, parent2@example.com"
            />
            <p class="text-[11px] text-gray-600">
                Example: <span class="font-mono">mum@gmail.com, dad@gmail.com</span>
            </p>

            <InputError :message="form.errors.weekly_report_emails" />

            <PrimaryButton type="submit" :disabled="form.processing">
                {{ form.processing ? 'Saving…' : 'Save parent emails' }}
            </PrimaryButton>
        </form>
    </section>
</template>
