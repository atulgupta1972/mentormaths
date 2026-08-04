<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({
    boards: { type: Array, default: () => [] },
    gradeLevels: { type: Array, default: () => [] },
    preferredDayOptions: { type: Array, default: () => [] },
    referralOptions: { type: Array, default: () => [] },
    genderOptions: { type: Array, default: () => [] },
    platformUsageOptions: { type: Array, default: () => [] },
    mentorMathsFeatures: { type: Array, default: () => [] },
});

const form = useForm({
    name: '',
    email: '',
    mobile: '',
    gender: '',
    date_of_birth: '',
    password: '',
    password_confirmation: '',
    city: '',
    qualification: '',
    current_role: '',
    years_of_experience: '',
    bio: '',
    monitoring_platform_name: '',
    platform_usage_scope: '',
    current_tool_features: [],
    platform_experience_notes: '',
    board_ids: [],
    teaching_grade_level_ids: [],
    content_grade_level_ids: [],
    interested_in_content_creation: false,
    creates_mcq: true,
    creates_fill_blank: true,
    creates_written_sheets: true,
    creates_chapter_tests: true,
    creates_formula_drills: false,
    sample_work_url: '',
    interested_in_book_content_upload: false,
    proposed_rate_per_set_inr: '',
    interested_in_doubt_solving: false,
    doubt_sessions_per_week: 2,
    doubt_hours_per_week: 2,
    proposed_hourly_rate_inr: '',
    preferred_days: [],
    preferred_time_slot: '',
    expected_start_date: '',
    teaches_english_medium: true,
    teaches_hindi_medium: false,
    referral_source: '',
    notes: '',
    resume: null,
    agreed_to_mentoring_program: false,
    agreed_to_terms: false,
});

const onResumeChange = (event) => {
    form.resume = event.target.files[0] ?? null;
};

const toggleId = (field, id) => {
    const key = String(id);
    const set = new Set(form[field].map(String));

    if (set.has(key)) {
        set.delete(key);
    } else {
        set.add(key);
    }

    form[field] = [...set].map(Number);
};

const hasId = (field, id) => form[field].map(String).includes(String(id));

const toggleFeature = (key) => {
    const set = new Set(form.current_tool_features);

    if (set.has(key)) {
        set.delete(key);
    } else {
        set.add(key);
    }

    form.current_tool_features = [...set];
};

watch(
    () => form.interested_in_content_creation,
    (enabled) => {
        if (! enabled) {
            form.interested_in_book_content_upload = false;
            form.proposed_rate_per_set_inr = '';
        }
    },
);

const submit = () => {
    form.post(route('teacher-registration.store'), {
        forceFormData: true,
    });
};
</script>

<template>
    <Head title="Join as Mentor" />

    <div class="min-h-screen bg-gradient-to-b from-violet-50 via-white to-indigo-50/40">
        <header class="border-b border-violet-100/80 bg-white/80 backdrop-blur">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                <Link href="/" class="text-sm font-semibold uppercase tracking-wide text-indigo-600">← Mentor Maths</Link>
                <Link :href="route('login')" class="rounded-md border border-indigo-200 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50">Log in</Link>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-6 py-10">
            <div class="mb-8 text-center">
                <span class="inline-block rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-violet-700">Mentor pool</span>
                <h1 class="mt-3 text-3xl font-bold text-gray-900">Join as mentor / question creator</h1>
                <p class="mt-2 text-gray-600">Create question bank content, upload book pages, and/or mentor students online on a weekly schedule.</p>
            </div>

            <form class="space-y-8 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-200" @submit.prevent="submit">
                <section>
                    <h2 class="text-lg font-semibold text-gray-900">About you</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <InputLabel value="Full name" />
                            <TextInput v-model="form.name" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Email (login after approval)" />
                            <TextInput v-model="form.email" type="email" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.email" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Mobile / WhatsApp" />
                            <TextInput v-model="form.mobile" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.mobile" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Gender" />
                            <select v-model="form.gender" class="mt-1 block w-full rounded-md border-gray-300 text-sm" required>
                                <option value="">Select</option>
                                <option v-for="option in genderOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                            <InputError :message="form.errors.gender" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Date of birth" />
                            <TextInput v-model="form.date_of_birth" type="date" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.date_of_birth" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Password" />
                            <TextInput v-model="form.password" type="password" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.password" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Confirm password" />
                            <TextInput v-model="form.password_confirmation" type="password" class="mt-1 block w-full" required />
                        </div>
                        <div>
                            <InputLabel value="City" />
                            <TextInput v-model="form.city" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel value="Years of experience" />
                            <TextInput v-model="form.years_of_experience" type="number" min="0" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.years_of_experience" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Qualification" />
                            <TextInput v-model="form.qualification" class="mt-1 block w-full" placeholder="e.g. B.Ed, M.Sc Maths" />
                        </div>
                        <div>
                            <InputLabel value="Current role" />
                            <TextInput v-model="form.current_role" class="mt-1 block w-full" placeholder="School mentor / tutor / freelance" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Upload resume (PDF or Word)" />
                            <input
                                type="file"
                                accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700"
                                @change="onResumeChange"
                            >
                            <p class="mt-1 text-xs text-gray-500">Optional · max 5 MB. Parents will review mentor profiles when the platform goes live.</p>
                            <InputError :message="form.errors.resume" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Short bio (shown on mentor profile later)" />
                            <textarea v-model="form.bio" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm" placeholder="Brief intro and maths mentoring background" />
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-slate-200 bg-slate-50/60 p-4">
                    <h2 class="text-lg font-semibold text-gray-900">Platforms you use today</h2>
                    <p class="mt-1 text-sm text-gray-600">Help us understand what tools you already use for student progress and worksheets.</p>

                    <div class="mt-4 rounded-md border border-violet-200 bg-violet-50/70 px-3 py-3 text-sm text-violet-950">
                        <p class="font-semibold">What Mentor Maths offers (mentormaths.in)</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li v-for="feature in mentorMathsFeatures" :key="feature.value">{{ feature.label }}</li>
                        </ul>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <InputLabel value="Platform / app you use for monitoring child performance (if any)" />
                            <TextInput v-model="form.monitoring_platform_name" class="mt-1 block w-full" placeholder="e.g. School LMS, Google Classroom, none" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="How do you mainly use that system?" />
                            <select v-model="form.platform_usage_scope" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option value="">Select</option>
                                <option v-for="option in platformUsageOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                            <InputError :message="form.errors.platform_usage_scope" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Which Mentor Maths–style features does your current tool have?" />
                            <p class="mt-1 text-xs text-gray-500">Tick all that apply — helps us see gaps vs what you already use.</p>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <label
                                    v-for="feature in mentorMathsFeatures"
                                    :key="`tool-${feature.value}`"
                                    class="inline-flex items-start gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 rounded border-gray-300 text-indigo-600"
                                        :checked="form.current_tool_features.includes(feature.value)"
                                        @change="toggleFeature(feature.value)"
                                    >
                                    <span>{{ feature.label }}</span>
                                </label>
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Anything else about your current tools? (optional)" />
                            <textarea v-model="form.platform_experience_notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" placeholder="e.g. We only share PDF worksheets — no scoring or gap analysis" />
                        </div>
                    </div>
                </section>

                <section>
                    <h2 class="text-lg font-semibold text-gray-900">Boards &amp; medium</h2>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <label v-for="board in boards" :key="board.id" class="inline-flex items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm">
                            <input type="checkbox" class="rounded border-gray-300 text-indigo-600" :checked="hasId('board_ids', board.id)" @change="toggleId('board_ids', board.id)">
                            {{ board.name }}
                        </label>
                    </div>
                    <InputError :message="form.errors.board_ids" class="mt-2" />
                    <div class="mt-3 flex flex-wrap gap-4 text-sm">
                        <label class="inline-flex items-center gap-2"><Checkbox v-model:checked="form.teaches_english_medium" /> English medium</label>
                        <label class="inline-flex items-center gap-2"><Checkbox v-model:checked="form.teaches_hindi_medium" /> Hindi medium</label>
                    </div>
                </section>

                <section class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-4 space-y-4">
                    <label class="flex items-start gap-3">
                        <Checkbox v-model:checked="form.interested_in_content_creation" class="mt-1" />
                        <span>
                            <span class="font-semibold text-emerald-950">Create question bank &amp; test papers</span>
                            <span class="mt-1 block text-sm text-emerald-900">MCQ sets, fill-in-blank, written sheets, chapter tests, book page upload (Classes 5–12)</span>
                        </span>
                    </label>
                    <InputError :message="form.errors.interested_in_content_creation" class="mt-2" />

                    <div v-if="form.interested_in_content_creation" class="space-y-4 pl-7">
                        <div>
                            <InputLabel value="Content types I can create" />
                            <div class="mt-2 flex flex-wrap gap-3 text-sm">
                                <label class="inline-flex items-center gap-2"><Checkbox v-model:checked="form.creates_mcq" /> MCQ</label>
                                <label class="inline-flex items-center gap-2"><Checkbox v-model:checked="form.creates_fill_blank" /> Fill-in-blank</label>
                                <label class="inline-flex items-center gap-2"><Checkbox v-model:checked="form.creates_written_sheets" /> Written sheets</label>
                                <label class="inline-flex items-center gap-2"><Checkbox v-model:checked="form.creates_chapter_tests" /> Chapter tests</label>
                                <label class="inline-flex items-center gap-2"><Checkbox v-model:checked="form.creates_formula_drills" /> Formula drills</label>
                                <label class="inline-flex items-center gap-2 rounded-md border border-emerald-300 bg-white px-2 py-1">
                                    <Checkbox v-model:checked="form.interested_in_book_content_upload" />
                                    Book content upload
                                </label>
                            </div>
                            <InputError :message="form.errors.interested_in_content_creation" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel value="Your proposed rate (₹ / question set) *" />
                            <TextInput v-model="form.proposed_rate_per_set_inr" type="number" min="50" class="mt-1 block w-full max-w-sm" placeholder="e.g. 500" />
                            <p class="mt-1 text-sm text-emerald-900">For MCQ sets, chapter tests, written sheets, book page sets, etc.</p>
                            <InputError :message="form.errors.proposed_rate_per_set_inr" class="mt-1" />
                        </div>

                        <div v-if="form.interested_in_book_content_upload" class="rounded-md border border-emerald-200 bg-emerald-50/80 px-3 py-2 text-sm text-emerald-950">
                            <p><strong>Book content upload:</strong> upload textbook pages — we provide JSON generation; you may use AI to draft questions.</p>
                            <p class="mt-1">After upload, <strong>every question must be verified</strong>. Typical set: <strong>15–20 questions</strong> · <strong>15–20 minutes</strong>.</p>
                        </div>

                        <div>
                            <InputLabel value="Sample work link (optional)" />
                            <TextInput v-model="form.sample_work_url" class="mt-1 block w-full" placeholder="Google Drive / PDF link" />
                        </div>

                        <div>
                            <InputLabel value="Classes I can work on" />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <label v-for="grade in gradeLevels" :key="`content-${grade.id}`" class="inline-flex items-center gap-2 rounded-md border border-emerald-200 bg-white px-3 py-1.5 text-sm">
                                    <input type="checkbox" :checked="hasId('content_grade_level_ids', grade.id)" @change="toggleId('content_grade_level_ids', grade.id)">
                                    {{ grade.name }}
                                </label>
                            </div>
                            <InputError :message="form.errors.content_grade_level_ids" class="mt-1" />
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-indigo-200 bg-indigo-50/40 p-4">
                    <label class="flex items-start gap-3">
                        <Checkbox v-model:checked="form.interested_in_doubt_solving" class="mt-1" />
                        <span>
                            <span class="font-semibold text-indigo-950">Online mentoring for students</span>
                            <span class="mt-1 block text-sm text-indigo-900">Weekly live support — parents will choose a mentor from profiles we publish</span>
                        </span>
                    </label>

                    <div class="mt-4 rounded-md border border-indigo-200 bg-white/80 px-3 py-3 text-sm text-indigo-950">
                        <p class="font-semibold">How online mentoring will work on Mentor Maths</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li>Parents browse mentor profiles (bio, classes, schedule, experience) and pick a mentor for their child.</li>
                            <li>You mentor a small group of students on a fixed weekly schedule — resolving doubts, guiding practice, and keeping them on track.</li>
                            <li>Student grouping and assignment will be set up by us later; for now we need your availability and acceptance of this model.</li>
                            <li>Typical commitment: about 2 sessions per week (you can propose more or less below).</li>
                        </ul>
                    </div>

                    <div v-if="form.interested_in_doubt_solving" class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <InputLabel value="Classes I can mentor (live / weekly)" />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <label v-for="grade in gradeLevels" :key="`teach-${grade.id}`" class="inline-flex items-center gap-2 rounded-md border border-indigo-200 bg-white px-3 py-1.5 text-sm">
                                    <input type="checkbox" :checked="hasId('teaching_grade_level_ids', grade.id)" @change="toggleId('teaching_grade_level_ids', grade.id)">
                                    {{ grade.name }}
                                </label>
                            </div>
                            <InputError :message="form.errors.teaching_grade_level_ids" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Sessions per week" />
                            <TextInput v-model="form.doubt_sessions_per_week" type="number" min="1" max="7" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel value="Hours per week (total)" />
                            <TextInput v-model="form.doubt_hours_per_week" type="number" step="0.5" min="0.5" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel value="Your proposed rate (₹ / hour)" />
                            <TextInput v-model="form.proposed_hourly_rate_inr" type="number" min="100" class="mt-1 block w-full" />
                            <InputError :message="form.errors.proposed_hourly_rate_inr" class="mt-1" />
                            <p class="mt-1 text-xs text-indigo-800">We may send a counter offer by email — you can accept or decline.</p>
                        </div>
                        <div>
                            <InputLabel value="Expected start date" />
                            <TextInput v-model="form.expected_start_date" type="date" class="mt-1 block w-full" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Preferred days" />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <label v-for="day in preferredDayOptions" :key="day.value" class="inline-flex items-center gap-2 rounded-md border border-indigo-200 bg-white px-3 py-1.5 text-sm">
                                    <input type="checkbox" :checked="form.preferred_days.includes(day.value)" @change="form.preferred_days = form.preferred_days.includes(day.value) ? form.preferred_days.filter((d) => d !== day.value) : [...form.preferred_days, day.value]">
                                    {{ day.label }}
                                </label>
                            </div>
                            <InputError :message="form.errors.preferred_days" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Preferred time slot (weekly)" />
                            <TextInput v-model="form.preferred_time_slot" class="mt-1 block w-full" placeholder="e.g. Weekday evenings 6–8 PM IST" />
                            <InputError :message="form.errors.preferred_time_slot" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="flex items-start gap-3 rounded-md border border-indigo-200 bg-white px-3 py-3 text-sm text-indigo-950">
                                <Checkbox v-model:checked="form.agreed_to_mentoring_program" class="mt-0.5" />
                                <span>
                                    I accept the online mentoring model above and commit to the weekly schedule I have entered.
                                    I understand parents will select mentors based on profile and availability once the module is live.
                                </span>
                            </label>
                            <InputError :message="form.errors.agreed_to_mentoring_program" class="mt-1" />
                        </div>
                    </div>
                </section>

                <section>
                    <InputLabel value="How did you hear about us?" />
                    <select v-model="form.referral_source" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">Select</option>
                        <option v-for="option in referralOptions" :key="option" :value="option">{{ option }}</option>
                    </select>
                    <div class="mt-4">
                        <InputLabel value="Anything else?" />
                        <textarea v-model="form.notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                    </div>
                </section>

                <label class="flex items-start gap-3 text-sm text-gray-700">
                    <Checkbox v-model:checked="form.agreed_to_terms" class="mt-0.5" />
                    <span>I agree that my mentor application will be reviewed by Mentor Maths admin before account access is granted.</span>
                </label>
                <InputError :message="form.errors.agreed_to_terms" />

                <PrimaryButton type="submit" :disabled="form.processing" class="w-full justify-center">
                    {{ form.processing ? 'Submitting…' : 'Submit application' }}
                </PrimaryButton>
            </form>
        </main>
    </div>
</template>
