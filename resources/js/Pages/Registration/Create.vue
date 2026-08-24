<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    academicYear: Object,
    boards: Array,
    gradeLevels: Array,
    enrollmentOptions: { type: Array, default: () => [] },
    coachingClasses: { type: Array, default: () => [] },
    trialDays: { type: Number, default: 15 },
});

const form = useForm({
    student_name: '',
    date_of_birth: '',
    student_mobile: '',
    parent1_name: '',
    parent1_mobile: '',
    parent1_email: '',
    parent2_name: '',
    parent2_mobile: '',
    school_name: '',
    enrollment_source: 'individual',
    coaching_class_id: '',
    coaching_class_teacher_id: '',
    board_id: '',
    grade_level_id: '',
    email: '',
    password: '',
    password_confirmation: '',
    notes: '',
    notify_student_mobile: true,
    notify_parent1_mobile: true,
    notify_parent2_mobile: false,
});

const hasMobile = (value) => String(value || '').replace(/\D/g, '').length >= 10;

const teachersForClass = computed(() => {
    const row = props.coachingClasses.find((c) => Number(c.id) === Number(form.coaching_class_id));

    return row?.teachers || [];
});

const notifyOptions = computed(() => [
    {
        key: 'student',
        label: 'Student mobile',
        mobile: form.student_mobile,
        notifyField: 'notify_student_mobile',
        hint: 'Enter student mobile above first',
    },
    {
        key: 'parent1',
        label: 'Mentor mobile',
        mobile: form.parent1_mobile,
        notifyField: 'notify_parent1_mobile',
        hint: 'Enter mentor mobile above first',
    },
    {
        key: 'parent2',
        label: 'Parent 2 mobile',
        mobile: form.parent2_mobile,
        notifyField: 'notify_parent2_mobile',
        hint: 'Enter parent 2 mobile above first',
    },
]);

const toggleNotify = (field, checked, mobile) => {
    if (checked && !hasMobile(mobile)) {
        return;
    }

    form[field] = checked;
};

watch(() => form.coaching_class_id, () => {
    form.coaching_class_teacher_id = '';
});

watch(() => form.enrollment_source, (source) => {
    if (source !== 'coaching') {
        form.coaching_class_id = '';
        form.coaching_class_teacher_id = '';
    }
});

const submit = () => {
    form.post(route('registration.store'));
};
</script>

<template>
    <Head title="Request Registration" />

    <div class="min-h-screen bg-gradient-to-b from-indigo-50 via-white to-emerald-50/40">
        <header class="border-b border-indigo-100/80 bg-white/80 backdrop-blur">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                <Link href="/" class="text-sm font-semibold uppercase tracking-wide text-indigo-600">
                    ← Mentor Maths
                </Link>
                <Link
                    :href="route('login')"
                    class="rounded-md border border-indigo-200 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50"
                >
                    Log in
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-6 py-10">
            <div class="mb-8 text-center">
                <span class="inline-block rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-indigo-700">
                    Registration {{ academicYear.name }}
                </span>
                <h1 class="mt-3 text-3xl font-bold text-gray-900">Get student access</h1>
                <p class="mt-2 text-gray-600">
                    Self-serve trial — you receive an access code (tcode) by email/mobile. Valid {{ trialDays }} days. No admin approval.
                </p>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <!-- Student -->
                <section class="overflow-hidden rounded-2xl bg-white shadow-md ring-1 ring-indigo-100">
                    <div class="border-b border-indigo-100 bg-gradient-to-r from-indigo-600 to-indigo-500 px-6 py-3">
                        <h2 class="font-semibold text-white">Student details</h2>
                    </div>
                    <div class="space-y-4 p-6">
                        <div>
                            <InputLabel for="student_name" value="Student full name *" />
                            <TextInput id="student_name" v-model="form.student_name" class="mt-1 block w-full" required />
                            <InputError class="mt-1" :message="form.errors.student_name" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="date_of_birth" value="Date of birth" />
                                <TextInput id="date_of_birth" v-model="form.date_of_birth" type="date" class="mt-1 block w-full" />
                                <InputError class="mt-1" :message="form.errors.date_of_birth" />
                            </div>
                            <div>
                                <InputLabel for="student_mobile" value="Student mobile" />
                                <TextInput id="student_mobile" v-model="form.student_mobile" type="tel" class="mt-1 block w-full" placeholder="10-digit mobile" required />
                                <InputError class="mt-1" :message="form.errors.student_mobile" />
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Mentor (home) -->
                <section class="overflow-hidden rounded-2xl bg-white shadow-md ring-1 ring-emerald-100">
                    <div class="border-b border-emerald-100 bg-gradient-to-r from-emerald-600 to-teal-500 px-6 py-3">
                        <h2 class="font-semibold text-white">Mentor contact</h2>
                    </div>
                    <div class="space-y-4 p-6">
                        <p class="text-sm text-slate-600">
                            For home learning, this mentor replaces any previous contact (one mentor only).
                            They get the access code and completion emails.
                        </p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="parent1_name" value="Mentor name *" />
                                <TextInput id="parent1_name" v-model="form.parent1_name" class="mt-1 block w-full" required />
                                <InputError class="mt-1" :message="form.errors.parent1_name" />
                            </div>
                            <div>
                                <InputLabel for="parent1_mobile" value="Mentor mobile *" />
                                <TextInput id="parent1_mobile" v-model="form.parent1_mobile" type="tel" class="mt-1 block w-full" required placeholder="10-digit mobile" />
                                <InputError class="mt-1" :message="form.errors.parent1_mobile" />
                            </div>
                            <div class="sm:col-span-2">
                                <InputLabel for="parent1_email" value="Mentor email" />
                                <TextInput id="parent1_email" v-model="form.parent1_email" type="email" class="mt-1 block w-full" placeholder="Defaults to login email if blank" />
                                <InputError class="mt-1" :message="form.errors.parent1_email" />
                            </div>
                            <div>
                                <InputLabel for="parent2_name" value="Second contact name (optional)" />
                                <TextInput id="parent2_name" v-model="form.parent2_name" class="mt-1 block w-full" />
                                <InputError class="mt-1" :message="form.errors.parent2_name" />
                            </div>
                            <div>
                                <InputLabel for="parent2_mobile" value="Second contact mobile" />
                                <TextInput id="parent2_mobile" v-model="form.parent2_mobile" type="tel" class="mt-1 block w-full" placeholder="10-digit mobile" />
                                <InputError class="mt-1" :message="form.errors.parent2_mobile" />
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Enrollment source -->
                <section class="overflow-hidden rounded-2xl bg-white shadow-md ring-1 ring-violet-100">
                    <div class="border-b border-violet-100 bg-gradient-to-r from-violet-600 to-indigo-500 px-6 py-3">
                        <h2 class="font-semibold text-white">Enrolled by</h2>
                    </div>
                    <div class="space-y-4 p-6">
                        <p class="text-sm text-slate-600">
                            Default is <strong>Individual</strong> — mentor contact above receives the tcode and progress mail.
                            Choose Coaching if you join through a tuition / coaching class.
                        </p>
                        <div class="flex flex-wrap gap-3">
                            <label
                                v-for="opt in enrollmentOptions"
                                :key="opt.value"
                                class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                                :class="[
                                    form.enrollment_source === opt.value
                                        ? 'border-violet-500 bg-violet-50 text-violet-950'
                                        : 'border-gray-200 bg-white text-gray-700',
                                    opt.enabled ? 'cursor-pointer' : 'cursor-not-allowed opacity-50',
                                ]"
                            >
                                <input
                                    v-model="form.enrollment_source"
                                    type="radio"
                                    class="text-violet-600"
                                    :value="opt.value"
                                    :disabled="!opt.enabled"
                                >
                                {{ opt.label }}
                            </label>
                        </div>
                        <InputError :message="form.errors.enrollment_source" />

                        <div v-if="form.enrollment_source === 'coaching'" class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Coaching class *" />
                                <select
                                    v-model="form.coaching_class_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                    required
                                >
                                    <option value="" disabled>Select coaching class</option>
                                    <option v-for="row in coachingClasses" :key="row.id" :value="row.id">
                                        {{ row.name }}{{ row.city ? ` (${row.city})` : '' }}
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="form.errors.coaching_class_id" />
                            </div>
                            <div>
                                <InputLabel value="Teacher / mentor *" />
                                <select
                                    v-model="form.coaching_class_teacher_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                    required
                                    :disabled="!form.coaching_class_id"
                                >
                                    <option value="" disabled>Select teacher</option>
                                    <option
                                        v-for="teacher in teachersForClass"
                                        :key="teacher.id"
                                        :value="teacher.id"
                                    >
                                        {{ teacher.name }} · {{ teacher.mobile }}
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="form.errors.coaching_class_teacher_id" />
                            </div>
                        </div>
                    </div>
                </section>

                <!-- School -->
                <section class="overflow-hidden rounded-2xl bg-white shadow-md ring-1 ring-sky-100">
                    <div class="border-b border-sky-100 bg-gradient-to-r from-sky-600 to-blue-500 px-6 py-3">
                        <h2 class="font-semibold text-white">School &amp; class</h2>
                    </div>
                    <div class="space-y-4 p-6">
                        <div>
                            <InputLabel for="school_name" value="School name *" />
                            <TextInput id="school_name" v-model="form.school_name" class="mt-1 block w-full" required />
                            <InputError class="mt-1" :message="form.errors.school_name" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="board_id" value="Board *" />
                                <select
                                    id="board_id"
                                    v-model="form.board_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                >
                                    <option value="" disabled>Select board</option>
                                    <option v-for="board in boards" :key="board.id" :value="board.id">
                                        {{ board.name }}
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="form.errors.board_id" />
                            </div>
                            <div>
                                <InputLabel for="grade_level_id" value="Class *" />
                                <select
                                    id="grade_level_id"
                                    v-model="form.grade_level_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                >
                                    <option value="" disabled>Select class</option>
                                    <option v-for="grade in gradeLevels" :key="grade.id" :value="grade.id">
                                        {{ grade.name }}
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="form.errors.grade_level_id" />
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Login -->
                <section class="overflow-hidden rounded-2xl bg-white shadow-md ring-1 ring-amber-100">
                    <div class="border-b border-amber-100 bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-3">
                        <h2 class="font-semibold text-white">Login account</h2>
                        <p class="text-sm text-amber-100">
                            Instant access — we email/SMS an access code (tcode). Valid {{ trialDays }} days.
                        </p>
                    </div>
                    <div class="space-y-4 p-6">
                        <div>
                            <InputLabel for="email" value="Login email *" />
                            <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" required autocomplete="username" />
                            <p class="mt-1 text-xs text-gray-500">Log in with this email + your tcode (or optional password below).</p>
                            <InputError class="mt-1" :message="form.errors.email" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="password" value="Password (optional)" />
                                <TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                                <InputError class="mt-1" :message="form.errors.password" />
                            </div>
                            <div>
                                <InputLabel for="password_confirmation" value="Confirm password" />
                                <TextInput id="password_confirmation" v-model="form.password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                                <InputError class="mt-1" :message="form.errors.password_confirmation" />
                            </div>
                        </div>
                        <p class="text-xs text-gray-500">Leave password blank to use your tcode as the login password.</p>
                        <div>
                            <InputLabel for="notes" value="Notes" />
                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="2"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="e.g. No own phone — use father's number"
                            />
                            <InputError class="mt-1" :message="form.errors.notes" />
                        </div>
                    </div>
                </section>

                <!-- Notifications -->
                <section class="overflow-hidden rounded-2xl bg-white shadow-md ring-1 ring-violet-100">
                    <div class="border-b border-violet-100 bg-gradient-to-r from-violet-600 to-purple-500 px-6 py-3">
                        <h2 class="font-semibold text-white">Progress updates</h2>
                        <p class="text-sm text-violet-100">Who should receive messages about assignments &amp; results?</p>
                    </div>
                    <div class="space-y-3 p-6">
                        <label
                            v-for="option in notifyOptions"
                            :key="option.key"
                            class="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 transition"
                            :class="hasMobile(option.mobile)
                                ? 'border-violet-200 bg-violet-50/50 hover:border-violet-300'
                                : 'cursor-not-allowed border-gray-200 bg-gray-50 opacity-60'"
                        >
                            <Checkbox
                                :checked="form[option.notifyField]"
                                :disabled="!hasMobile(option.mobile)"
                                @update:checked="toggleNotify(option.notifyField, $event, option.mobile)"
                            />
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ option.label }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ hasMobile(option.mobile) ? option.mobile : option.hint }}
                                </p>
                            </div>
                        </label>
                        <InputError :message="form.errors.notify_student_mobile || form.errors.notify_parent1_mobile || form.errors.notify_parent2_mobile" />
                    </div>
                </section>

                <PrimaryButton
                    class="w-full justify-center py-3 text-base"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Submit registration request
                </PrimaryButton>
            </form>
        </main>
    </div>
</template>
