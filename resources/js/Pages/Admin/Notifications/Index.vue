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
            channels: [],
            schedule: {},
            templates: {},
            setup: [],
        }),
    },
    recentWhatsAppMessages: {
        type: Array,
        default: () => [],
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
    'WHATSAPP_TEMPLATES_ENABLED=true',
    'WHATSAPP_TEMPLATE_NAME=mentor_maths_update',
    'WHATSAPP_TEMPLATE_LANGUAGE=en',
    '',
    '# Schedule (IST, server cron required)',
    'WHATSAPP_WEEKLY_SUMMARY_ENABLED=true',
    'WHATSAPP_WEEKLY_SUMMARY_DAY=6',
    'WHATSAPP_WEEKLY_SUMMARY_TIME=08:00',
    'WHATSAPP_DAILY_BALANCE_ENABLED=true',
    'WHATSAPP_DAILY_BALANCE_TIME=14:00',
    '',
    '# Enable/disable message types',
    'WHATSAPP_PROGRESS_SUMMARY=true',
    'WHATSAPP_DAILY_BALANCE=true',
    'WHATSAPP_ASSIGNMENT_ASSIGNED=true',
    'WHATSAPP_PENDING_WORK=true',
    '',
    '# Test: php artisan whatsapp:test 9876543210',
].join('\n'));

const weeklyScheduleLabel = computed(() => {
    const schedule = props.whatsappSettings.schedule ?? {};

    if (! schedule.weekly_summary_enabled) {
        return 'Disabled';
    }

    return `${schedule.weekly_summary_day_label} at ${schedule.weekly_summary_time} IST`;
});

const dailyScheduleLabel = computed(() => {
    const schedule = props.whatsappSettings.schedule ?? {};

    if (! schedule.daily_balance_enabled) {
        return 'Disabled';
    }

    return `Daily at ${schedule.daily_balance_time} IST`;
});

function statusClass(status) {
    if (status === 'sent') {
        return 'bg-green-100 text-green-800';
    }

    if (status === 'failed') {
        return 'bg-red-100 text-red-800';
    }

    return 'bg-gray-100 text-gray-700';
}
</script>

<template>
    <Head title="Email & Notifications" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Email & notifications</h2>
                <p class="text-sm text-gray-500">Mail delivery, WhatsApp reminders, schedules, and send history</p>
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
                                Automatic WhatsApp uses approved Meta templates so parents receive messages without messaging you first.
                                Only mobiles marked <strong>Notify</strong> on each student profile are included.
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
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Templates</dt>
                            <dd class="mt-1 text-gray-900">
                                {{ whatsappSettings.templates?.enabled ? whatsappSettings.templates.default_name : 'Disabled (plain text only)' }}
                            </dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Meta credentials</dt>
                            <dd class="mt-1 text-gray-900">
                                {{ whatsappSettings.meta?.configured ? 'Configured' : 'Incomplete' }}
                            </dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Weekly summary schedule</dt>
                            <dd class="mt-1 text-gray-900">{{ weeklyScheduleLabel }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Daily balance schedule</dt>
                            <dd class="mt-1 text-gray-900">{{ dailyScheduleLabel }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Message types</p>
                        <div class="mt-2 overflow-x-auto rounded-lg border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-3 py-2">Type</th>
                                        <th class="px-3 py-2">Status</th>
                                        <th class="px-3 py-2">When it sends</th>
                                        <th class="px-3 py-2">.env toggle</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="channel in whatsappSettings.channels" :key="channel.key">
                                        <td class="px-3 py-2 font-medium text-gray-900">{{ channel.label }}</td>
                                        <td class="px-3 py-2">
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                                :class="channel.enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                            >
                                                {{ channel.enabled ? 'On' : 'Off' }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-gray-600">{{ channel.trigger }}</td>
                                        <td class="px-3 py-2 font-mono text-xs text-gray-700">
                                            WHATSAPP_{{ channel.key.toUpperCase() }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">
                            Change toggles or times in <code class="rounded bg-gray-100 px-1">.env</code>, then run
                            <code class="rounded bg-gray-100 px-1">php artisan config:cache</code>.
                            Day for weekly summary: 0=Sun … 6=Sat.
                        </p>
                    </div>

                    <div
                        v-if="!whatsappSettings.can_auto_send"
                        class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                    >
                        WhatsApp is in manual mode until Meta Cloud API credentials are added. You can still copy messages from each student profile.
                    </div>

                    <div class="mt-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Meta template to create (once)</p>
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100">{{ whatsappSettings.templates?.body }}</pre>
                    </div>

                    <div class="mt-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Suggested .env lines</p>
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100">{{ whatsappEnvHints }}</pre>
                    </div>

                    <ul v-if="whatsappSettings.setup?.length" class="mt-4 list-disc space-y-1 pl-5 text-sm text-gray-600">
                        <li v-for="(step, index) in whatsappSettings.setup" :key="index">{{ step }}</li>
                    </ul>
                </div>

                <div class="overflow-hidden rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium text-gray-900">Recent WhatsApp messages</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Last 50 sends logged by the app. “Sent” means Meta accepted the message; delivery to the phone is handled by WhatsApp.
                            </p>
                        </div>
                    </div>

                    <div v-if="recentWhatsAppMessages.length === 0" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
                        No WhatsApp messages logged yet. Run
                        <code class="rounded bg-white px-1">php artisan whatsapp:test 9876543210</code>
                        or send a progress summary from a student profile.
                    </div>

                    <div v-else class="mt-4 overflow-x-auto rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">When (IST)</th>
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2">Student</th>
                                    <th class="px-3 py-2">To</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2">Preview</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="message in recentWhatsAppMessages" :key="message.id">
                                    <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ message.sent_at }}</td>
                                    <td class="px-3 py-2 text-gray-900">{{ message.channel_label }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ message.student_name || '—' }}</td>
                                    <td class="px-3 py-2 text-gray-700">
                                        <div>{{ message.recipient_label || 'Mobile' }}</div>
                                        <div class="font-mono text-xs text-gray-500">{{ message.to_mobile }}</div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize" :class="statusClass(message.status)">
                                            {{ message.status }}
                                        </span>
                                        <div v-if="message.error" class="mt-1 text-xs text-red-600">{{ message.error }}</div>
                                        <div v-if="message.template_name" class="mt-1 text-xs text-gray-500">Template: {{ message.template_name }}</div>
                                    </td>
                                    <td class="max-w-xs px-3 py-2 text-xs text-gray-600">{{ message.message_preview }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
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
