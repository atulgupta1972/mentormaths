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

const approveForm = useForm({
    admin_notes: props.application.admin_notes ?? '',
    assign_mentor: props.application.interested_in_doubt_solving || props.application.agreed_to_mentoring_program,
    assign_content_uploader: props.application.interested_in_book_content_upload,
});
const rejectForm = useForm({ admin_notes: props.application.admin_notes ?? '' });
const counterForm = useForm({
    counter_hourly_rate_inr: props.application.counter_hourly_rate_inr ?? props.application.proposed_hourly_rate_inr ?? '',
    counter_offer_message: props.application.counter_offer_message ?? '',
});
const profileForm = useForm({
    profile_completion_message: '',
});
const resendWelcomeForm = useForm({});
const grantUploaderForm = useForm({});

const generatedLogin = computed(() => page.props.flash?.generated_login ?? null);
const isApproved = computed(() => props.application.status === 'approved');
const hasContentUploaderGroup = computed(() =>
    Boolean(props.application.login_user?.groups?.content_uploader),
);

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

                <div
                    v-if="generatedLogin || application.login_user"
                    class="rounded-md border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-950"
                >
                    <p class="font-semibold">Login created</p>
                    <p class="mt-1"><strong>Email (login ID):</strong> {{ generatedLogin?.email || application.login_user?.email }}</p>
                    <p><strong>Password:</strong> Chosen during registration (not stored here).</p>
                    <p v-if="generatedLogin?.assign_content_uploader || application.login_user?.groups?.content_uploader" class="mt-1">
                        <strong>Content uploader:</strong>
                        <a
                            v-if="application.login_user?.content_tasks_url"
                            :href="application.login_user.content_tasks_url"
                            class="text-indigo-700 underline"
                        >My content tasks</a>
                    </p>
                    <p v-if="page.props.flash?.email_sent" class="mt-2 text-green-800">Welcome email sent to the login address.</p>
                    <p v-else-if="generatedLogin || application.login_user" class="mt-2 text-red-800">
                        Email could not be sent — share login details manually or use Resend below.
                    </p>
                </div>

                <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium capitalize">{{ application.status_label }}</span>
                        <span class="text-sm text-gray-600">{{ application.email }} · {{ application.mobile }}</span>
                    </div>

                    <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                        <div><dt class="text-gray-500">Gender</dt><dd class="font-medium capitalize">{{ application.gender?.replace(/_/g, ' ') || '—' }}</dd></div>
                        <div><dt class="text-gray-500">Date of birth</dt><dd class="font-medium">{{ application.date_of_birth || '—' }}<span v-if="application.age"> ({{ application.age }} yrs)</span></dd></div>
                        <div><dt class="text-gray-500">Experience</dt><dd class="font-medium">{{ application.years_of_experience }} years</dd></div>
                        <div><dt class="text-gray-500">Location</dt><dd class="font-medium">{{ application.location_label || '—' }}</dd></div>
                        <div><dt class="text-gray-500">Languages</dt><dd class="font-medium">{{ application.language_labels?.join(', ') || '—' }}</dd></div>
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
                        · proposed ₹{{ application.proposed_rate_per_set_inr }}/set
                        <span v-if="application.interested_in_book_content_upload"> · includes book content upload</span>
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

                    <div
                        v-if="!application.has_complete_profile"
                        class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950"
                    >
                        <strong>Profile incomplete:</strong>
                        {{ application.missing_profile_field_labels?.join(', ') || 'Location or language missing' }}
                        <p v-if="application.profile_completion_requested_at" class="mt-1 text-xs text-amber-800">
                            Completion email sent {{ application.profile_completion_requested_at }}
                        </p>
                    </div>
                </div>

                <div v-if="application.can_request_profile_completion" class="rounded-lg border border-violet-200 bg-violet-50/50 p-5">
                    <h3 class="font-semibold text-violet-950">Email mentor to complete location &amp; languages</h3>
                    <p class="mt-1 text-sm text-violet-900">
                        Send a one-time link so they can add city, state, country (default India), and teaching languages.
                    </p>
                    <div class="mt-4">
                        <InputLabel value="Message to mentor (included in email)" />
                        <textarea v-model="profileForm.profile_completion_message" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" placeholder="e.g. Please call us back on … or add your city and state." />
                    </div>
                    <PrimaryButton
                        class="mt-4"
                        type="button"
                        :disabled="profileForm.processing"
                        @click="profileForm.post(route('admin.teacher-registrations.request-profile', application.id))"
                    >
                        Send profile completion email
                    </PrimaryButton>
                    <p v-if="application.profile_completion_url" class="mt-2 text-xs text-violet-800">Latest link: {{ application.profile_completion_url }}</p>
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
                            <InputLabel value="Message to mentor (included in email)" />
                            <textarea v-model="counterForm.counter_offer_message" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" placeholder="e.g. Please call us to discuss before accepting." />
                        </div>
                    </div>
                    <PrimaryButton class="mt-4" type="button" :disabled="counterForm.processing" @click="counterForm.post(route('admin.teacher-registrations.counter-offer', application.id))">
                        Send counter offer
                    </PrimaryButton>
                    <p v-if="application.offer_url" class="mt-2 text-xs text-amber-800">Offer link: {{ application.offer_url }}</p>
                </div>

                <div v-if="canApprove || canReject" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <InputLabel value="Admin notes (included in approval email)" />
                    <textarea v-model="approveForm.admin_notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" placeholder="e.g. Please call us on … before you start." />

                    <div class="mt-4 space-y-2 text-sm">
                        <label class="flex items-center gap-2">
                            <input v-model="approveForm.assign_mentor" type="checkbox" class="rounded border-gray-300">
                            Assign as mentor (student doubt resolution)
                        </label>
                        <label class="flex items-center gap-2">
                            <input v-model="approveForm.assign_content_uploader" type="checkbox" class="rounded border-gray-300">
                            Assign as content uploader (textbook MCQ upload)
                        </label>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <PrimaryButton v-if="canApprove" type="button" :disabled="approveForm.processing" @click="approveForm.post(route('admin.teacher-registrations.approve', application.id))">
                            Approve &amp; create login
                        </PrimaryButton>
                        <DangerButton v-if="canReject" type="button" :disabled="rejectForm.processing" @click="rejectForm.post(route('admin.teacher-registrations.reject', application.id))">
                            Reject
                        </DangerButton>
                    </div>
                </div>

                <div v-if="isApproved && application.login_user" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                    <h3 class="font-semibold text-gray-900">Account</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        User #{{ application.login_user.id }} · {{ application.login_user.email }}
                    </p>
                    <p v-if="Object.keys(application.login_user.groups || {}).length" class="mt-1 text-sm text-gray-600">
                        Groups: {{ Object.values(application.login_user.groups).join(', ') }}
                    </p>
                    <p v-if="!hasContentUploaderGroup" class="mt-2 text-sm text-amber-800">
                        Not in the Content uploader list yet — they will not appear when assigning chapters.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <PrimaryButton
                            type="button"
                            :disabled="resendWelcomeForm.processing"
                            @click="resendWelcomeForm.post(route('admin.teacher-registrations.resend-welcome', application.id))"
                        >
                            Resend welcome email
                        </PrimaryButton>
                        <PrimaryButton
                            v-if="!hasContentUploaderGroup"
                            type="button"
                            :disabled="grantUploaderForm.processing"
                            @click="grantUploaderForm.post(route('admin.teacher-registrations.grant-content-uploader', application.id))"
                        >
                            Grant content uploader access
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
