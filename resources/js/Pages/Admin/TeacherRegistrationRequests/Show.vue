<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    application: { type: Object, required: true },
    shareLinks: { type: Object, default: () => ({}) },
});

const page = usePage();

const approveForm = useForm({ admin_notes: props.application.admin_notes ?? '' });
const rejectForm = useForm({ admin_notes: props.application.admin_notes ?? '' });
const counterForm = useForm({
    counter_hourly_rate_inr: props.application.counter_hourly_rate_inr ?? props.application.proposed_hourly_rate_inr ?? '',
    counter_offer_message: props.application.counter_offer_message ?? '',
});

const canApprove = computed(() =>
    ['pending', 'offer_accepted'].includes(props.application.status),
);

const canCounterOffer = computed(() =>
    props.application.interested_in_doubt_solving
    && ['pending', 'offer_declined'].includes(props.application.status),
);

const canReject = computed(() =>
    !['approved', 'rejected'].includes(props.application.status),
);
</script>

<template>
    <Head :title="application.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-gray-800">{{ application.name }}</h2>
                <Link :href="route('admin.teacher-registrations.index')" class="text-sm text-indigo-600">Back</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">{{ page.props.flash.success }}</div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">{{ page.props.flash.error }}</div>

                <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium capitalize">{{ application.status_label }}</span>
                        <span class="text-sm text-gray-600">{{ application.email }} · {{ application.mobile }}</span>
                    </div>

                    <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                        <div><dt class="text-gray-500">Gender</dt><dd class="font-medium capitalize">{{ application.gender?.replace(/_/g, ' ') || '—' }}</dd></div>
                        <div><dt class="text-gray-500">Date of birth</dt><dd class="font-medium">{{ application.date_of_birth || '—' }}<span v-if="application.age"> ({{ application.age }} yrs)</span></dd></div>
                        <div><dt class="text-gray-500">Experience</dt><dd class="font-medium">{{ application.years_of_experience }} years</dd></div>
                        <div><dt class="text-gray-500">Qualification</dt><dd class="font-medium">{{ application.qualification || '—' }}</dd></div>
                        <div><dt class="text-gray-500">Boards</dt><dd class="font-medium">{{ application.board_labels?.join(', ') || '—' }}</dd></div>
                        <div><dt class="text-gray-500">Expected start</dt><dd class="font-medium">{{ application.expected_start_date || '—' }}</dd></div>
                        <div v-if="application.resume_download_url" class="sm:col-span-2">
                            <dt class="text-gray-500">Resume</dt>
                            <dd class="font-medium">
                                <a :href="application.resume_download_url" class="text-indigo-600 hover:underline">
                                    {{ application.resume_original_name || 'Download resume' }}
                                </a>
                            </dd>
                        </div>
                    </dl>

                    <div v-if="application.interested_in_content_creation" class="mt-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-950">
                        <strong>Question bank:</strong> {{ application.content_class_labels?.join(', ') || '—' }}
                    </div>
                    <div v-if="application.interested_in_book_content_upload" class="mt-3 rounded-md bg-teal-50 px-3 py-2 text-sm text-teal-950">
                        <strong>Book content upload:</strong> {{ application.content_class_labels?.join(', ') || '—' }}
                        · proposed ₹{{ application.proposed_rate_per_set_inr }}/set
                    </div>
                    <div v-if="application.interested_in_doubt_solving" class="mt-3 rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-950">
                        <strong>Online mentoring:</strong> {{ application.teaching_class_labels?.join(', ') || '—' }}
                        · {{ application.doubt_sessions_per_week }} sessions/wk · {{ application.doubt_hours_per_week }} hrs/wk
                        · proposed ₹{{ application.proposed_hourly_rate_inr }}/hr
                        <span v-if="application.agreed_hourly_rate_inr"> · agreed ₹{{ application.agreed_hourly_rate_inr }}/hr</span>
                        <p v-if="application.preferred_days?.length" class="mt-1">Schedule: {{ application.preferred_days.join(', ') }} · {{ application.preferred_time_slot || '—' }}</p>
                        <p v-if="application.agreed_to_mentoring_program" class="mt-1 text-indigo-800">✓ Accepted mentoring model &amp; weekly schedule</p>
                    </div>

                    <div v-if="application.monitoring_platform_name || application.platform_usage_scope_label || application.current_tool_feature_labels?.length" class="mt-4 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-900">
                        <strong>Current platforms:</strong>
                        {{ application.monitoring_platform_name || 'Not specified' }}
                        <span v-if="application.platform_usage_scope_label"> · {{ application.platform_usage_scope_label }}</span>
                        <ul v-if="application.current_tool_feature_labels?.length" class="mt-2 list-disc pl-5">
                            <li v-for="label in application.current_tool_feature_labels" :key="label">{{ label }}</li>
                        </ul>
                        <p v-if="application.platform_experience_notes" class="mt-2 text-gray-600">{{ application.platform_experience_notes }}</p>
                    </div>

                    <p v-if="application.bio" class="mt-4 text-sm text-gray-700">{{ application.bio }}</p>
                    <p v-if="application.notes" class="mt-2 text-sm text-gray-500">{{ application.notes }}</p>
                </div>

                <div v-if="canCounterOffer" class="rounded-lg border border-amber-200 bg-amber-50/50 p-5">
                    <h3 class="font-semibold text-amber-950">Send counter offer (rates are negotiated)</h3>
                    <p class="mt-1 text-sm text-amber-900">Mentor proposed ₹{{ application.proposed_hourly_rate_inr }}/hour. Enter your counter offer — they must accept before approval.</p>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Counter rate (₹ / hour)" />
                            <TextInput v-model="counterForm.counter_hourly_rate_inr" type="number" min="100" class="mt-1 block w-full" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Message to mentor" />
                            <textarea v-model="counterForm.counter_offer_message" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                    </div>
                    <PrimaryButton class="mt-4" type="button" :disabled="counterForm.processing" @click="counterForm.post(route('admin.teacher-registrations.counter-offer', application.id))">
                        Send counter offer
                    </PrimaryButton>
                    <p v-if="application.offer_url" class="mt-2 text-xs text-amber-800">Offer link: {{ application.offer_url }}</p>
                </div>

                <div v-if="canApprove || canReject" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <InputLabel value="Admin notes" />
                    <textarea v-model="approveForm.admin_notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />

                    <div class="mt-4 flex flex-wrap gap-3">
                        <PrimaryButton v-if="canApprove" type="button" :disabled="approveForm.processing" @click="approveForm.post(route('admin.teacher-registrations.approve', application.id))">
                            Approve &amp; create login
                        </PrimaryButton>
                        <DangerButton v-if="canReject" type="button" :disabled="rejectForm.processing" @click="rejectForm.post(route('admin.teacher-registrations.reject', application.id))">
                            Reject
                        </DangerButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
