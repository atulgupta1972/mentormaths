<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    mailSettings: {
        type: Object,
        default: null,
    },
    activeYear: {
        type: Object,
        default: null,
    },
    selectedGrade: {
        type: Object,
        default: null,
    },
    gradeLevels: {
        type: Array,
        default: () => [],
    },
    compact: {
        type: Boolean,
        default: false,
    },
    showSettingsLink: {
        type: Boolean,
        default: true,
    },
});

const page = usePage();

const form = useForm({
    grade_level_id: props.selectedGrade?.id || '',
});

const flashSuccess = computed(() => page.props.flash?.success);
const flashWarning = computed(() => page.props.flash?.warning);

const sendLabel = computed(() => {
    if (form.processing) {
        return 'Sending…';
    }

    if (props.selectedGrade?.name) {
        return `Email pending work — ${props.selectedGrade.name}`;
    }

    return 'Email pending work to all students';
});

const confirmMessage = computed(() => {
    const scope = props.selectedGrade?.name
        ? `students in ${props.selectedGrade.name}`
        : 'all active students';

    return `Send pending worksheet emails to ${scope}? Each email lists chapter-wise pending work and days waiting. Parents will be CC'd when their email is on file.`;
});

const sendPendingWork = () => {
    if (!confirm(confirmMessage.value)) {
        return;
    }

    form.post(route('admin.notifications.send-pending-work'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border shadow-sm"
        :class="compact
            ? 'border-indigo-200 bg-gradient-to-br from-indigo-50 to-violet-50'
            : 'border-gray-200 bg-white'"
    >
        <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-4 sm:px-6">
            <div class="min-w-0">
                <h3 class="font-semibold text-gray-900">
                    Pending worksheet emails
                </h3>
                <p class="mt-1 text-sm text-gray-600">
                    Send chapter-wise balance work with pending days. Student in TO; parent CC when on file.
                </p>
                <p v-if="activeYear" class="mt-1 text-xs text-gray-500">
                    Active year: {{ activeYear.name }}
                    <span v-if="selectedGrade"> · Class filter: {{ selectedGrade.name }}</span>
                </p>
            </div>
            <Link
                v-if="showSettingsLink"
                :href="route('admin.notifications.index')"
                class="shrink-0 text-sm font-medium text-indigo-700 hover:text-indigo-900"
            >
                Email settings →
            </Link>
        </div>

        <div
            v-if="flashSuccess || flashWarning"
            class="border-t px-4 py-3 text-sm sm:px-6"
            :class="flashWarning ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-green-200 bg-green-50 text-green-900'"
        >
            {{ flashWarning || flashSuccess }}
        </div>

        <div
            v-if="mailSettings?.is_log_mailer"
            class="border-t border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:px-6"
        >
            Mail is set to <strong>log</strong> — emails are written to storage/logs only until SMTP is configured.
        </div>

        <div class="flex flex-wrap items-end gap-3 border-t border-gray-100 px-4 py-4 sm:px-6">
            <div v-if="gradeLevels.length && !selectedGrade" class="min-w-[180px]">
                <label for="pending-work-grade" class="block text-xs font-medium uppercase tracking-wide text-gray-500">
                    Limit to class (optional)
                </label>
                <select
                    id="pending-work-grade"
                    v-model="form.grade_level_id"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option value="">All classes</option>
                    <option v-for="grade in gradeLevels" :key="grade.id" :value="grade.id">
                        {{ grade.name }}
                    </option>
                </select>
            </div>

            <PrimaryButton type="button" :disabled="form.processing" @click="sendPendingWork">
                {{ sendLabel }}
            </PrimaryButton>

            <Link
                v-if="showSettingsLink"
                :href="route('admin.notifications.index')"
                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition hover:bg-gray-50"
            >
                Configure mail
            </Link>
        </div>
    </div>
</template>
