<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PendingWorkEmailPanel from '@/Components/PendingWorkEmailPanel.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    mailSettings: {
        type: Object,
        required: true,
    },
    whatsappSettings: {
        type: Object,
        default: () => ({
            enabled: false,
            driver: 'manual',
            can_auto_send: false,
            meta: {},
            channels: {},
            setup: [],
        }),
    },
    activeYear: Object,
    selectedGrade: Object,
    gradeLevels: {
        type: Array,
        default: () => [],
    },
});

const mailStatusLabel = computed(() => {
    if (props.mailSettings.is_log_mailer) {
        return 'Log only (not delivered)';
    }

    if (props.mailSettings.is_smtp) {
        return `SMTP · ${props.mailSettings.smtp_host || 'configured'}`;
    }

    return props.mailSettings.mailer;
});

const mailStatusClass = computed(() => (
    props.mailSettings.is_log_mailer
        ? 'bg-amber-100 text-amber-800'
        : 'bg-green-100 text-green-800'
));

const envHintsText = computed(() => props.mailSettings.env_hints.join('\n'));

const whatsappStatusLabel = computed(() => {
    if (props.whatsappSettings.can_auto_send) {
        return `Auto-send · ${props.whatsappSettings.driver}`;
    }

    if (props.whatsappSettings.enabled && props.whatsappSettings.driver === 'meta') {
        return 'Meta API — credentials incomplete';
    }

    if (props.whatsappSettings.driver === 'log') {
        return 'Log only (not delivered)';
    }

    return 'Manual (copy / wa.me links)';
});

const whatsappStatusClass = computed(() => {
    if (props.whatsappSettings.can_auto_send) {
        return 'bg-green-100 text-green-800';
    }

    if (props.whatsappSettings.driver === 'log') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-gray-100 text-gray-700';
});

const whatsappEnvHints = computed(() => [
    'WHATSAPP_ENABLED=true',
    'WHATSAPP_DRIVER=meta',
    'WHATSAPP_META_PHONE_NUMBER_ID=your_phone_number_id',
    'WHATSAPP_META_ACCESS_TOKEN=your_permanent_token',
    '# Optional: WHATSAPP_META_API_VERSION=v21.0',
    '# Test: php artisan whatsapp:test 9876543210',
].join('\n'));
</script>

<template>
    <Head title="Email & Notifications" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Email & notifications</h2>
                <p class="text-sm text-gray-500">Mail delivery, WhatsApp reminders, and pending-work notifications</p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-gray-900">Mail delivery status</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Configure these in your server <code class="rounded bg-gray-100 px-1">.env</code> file, then run
                                <code class="rounded bg-gray-100 px-1">php artisan config:cache</code>.
                            </p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="mailStatusClass">
                            {{ mailStatusLabel }}
                        </span>
                    </div>

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">From address</dt>
                            <dd class="mt-1 text-gray-900">{{ mailSettings.from_address || '—' }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Admin CC email</dt>
                            <dd class="mt-1 text-gray-900">{{ mailSettings.admin_notify_email || '—' }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Daily balance email</dt>
                            <dd class="mt-1 text-gray-900">
                                {{ mailSettings.daily_balance_enabled ? `Enabled at ${mailSettings.daily_balance_time} IST` : 'Disabled' }}
                            </dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">SMTP port</dt>
                            <dd class="mt-1 text-gray-900">{{ mailSettings.smtp_port || '—' }}</dd>
                        </div>
                    </dl>

                    <div
                        v-if="mailSettings.is_log_mailer"
                        class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                    >
                        Emails are being logged locally and not delivered. On production (Virtualmin), set SMTP in
                        <code class="rounded bg-white/70 px-1">.env</code> and rebuild config cache.
                    </div>

                    <div class="mt-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Suggested .env lines</p>
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100">{{ envHintsText }}</pre>
                    </div>

                    <div class="mt-4 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
                        <p class="font-medium">Cron for automatic daily emails</p>
                        <p class="mt-1">
                            Add this to your server crontab (Virtualmin → Scheduled Cron Jobs), not inside Laravel:
                        </p>
                        <pre class="mt-2 overflow-x-auto rounded bg-white/80 p-3 text-xs text-sky-950">{{ mailSettings.cron_command }}</pre>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-gray-900">WhatsApp Business status</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                When configured, weekly summaries, daily balance reminders, assignment alerts, and pending-work
                                notifications are sent automatically alongside email to mobiles marked <strong>Notify</strong> on each student profile.
                            </p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="whatsappStatusClass">
                            {{ whatsappStatusLabel }}
                        </span>
                    </div>

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Driver</dt>
                            <dd class="mt-1 text-gray-900">{{ whatsappSettings.driver }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Auto-send enabled</dt>
                            <dd class="mt-1 text-gray-900">{{ whatsappSettings.enabled ? 'Yes' : 'No' }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Meta phone number ID</dt>
                            <dd class="mt-1 text-gray-900">{{ whatsappSettings.meta?.phone_number_id_set ? 'Set' : 'Not set' }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Meta access token</dt>
                            <dd class="mt-1 text-gray-900">{{ whatsappSettings.meta?.access_token_set ? 'Set' : 'Not set' }}</dd>
                        </div>
                    </dl>

                    <div
                        v-if="!whatsappSettings.can_auto_send"
                        class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                    >
                        WhatsApp is in manual mode until Meta Cloud API credentials are added. You can still copy messages from each student profile.
                    </div>

                    <div class="mt-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Suggested .env lines</p>
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100">{{ whatsappEnvHints }}</pre>
                    </div>

                    <ul v-if="whatsappSettings.setup?.length" class="mt-4 list-disc space-y-1 pl-5 text-sm text-gray-600">
                        <li v-for="(step, index) in whatsappSettings.setup" :key="index">{{ step }}</li>
                    </ul>
                </div>

                <PendingWorkEmailPanel
                    :mail-settings="mailSettings"
                    :active-year="activeYear"
                    :selected-grade="selectedGrade"
                    :grade-levels="gradeLevels"
                    :show-settings-link="false"
                />

                <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm">
                    <p>
                        Per-student send is also available on each
                        <Link :href="route('admin.students.index')" class="font-medium text-indigo-700 hover:underline">
                            student profile
                        </Link>
                        under Email contacts.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
