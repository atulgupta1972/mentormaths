<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    studentProfile: { type: Object, required: true },
    saveUrl: { type: String, required: true },
});

const form = useForm({
    student_mobile: props.studentProfile.student_mobile || '',
    parent1_name: props.studentProfile.parent1_name || '',
    parent1_mobile: props.studentProfile.parent1_mobile || '',
    parent2_name: props.studentProfile.parent2_name || '',
    parent2_mobile: props.studentProfile.parent2_mobile || '',
    notify_student_mobile: !!props.studentProfile.notify_student_mobile,
    notify_parent1_mobile: props.studentProfile.notify_parent1_mobile ?? true,
    notify_parent2_mobile: !!props.studentProfile.notify_parent2_mobile,
});

const notifyOptions = computed(() => [
    {
        key: 'student',
        label: 'Student mobile',
        mobile: form.student_mobile,
        notifyField: 'notify_student_mobile',
        hint: form.student_mobile || 'Add number above',
    },
    {
        key: 'parent1',
        label: `Parent 1${form.parent1_name ? ` (${form.parent1_name})` : ''}`,
        mobile: form.parent1_mobile,
        notifyField: 'notify_parent1_mobile',
        hint: form.parent1_mobile || 'Required above',
    },
    {
        key: 'parent2',
        label: `Parent 2${form.parent2_name ? ` (${form.parent2_name})` : ''}`,
        mobile: form.parent2_mobile,
        notifyField: 'notify_parent2_mobile',
        hint: form.parent2_mobile || 'Optional',
    },
]);

const hasMobile = (value) => String(value || '').replace(/\D/g, '').length >= 10;

const toggleNotify = (field, checked, mobile) => {
    if (checked && !hasMobile(mobile)) {
        return;
    }

    form[field] = checked;
};

const submit = () => {
    form.patch(props.saveUrl, { preserveScroll: true });
};
</script>

<template>
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
        <div class="border-b border-indigo-100 bg-gradient-to-r from-indigo-600 to-violet-600 px-6 py-4">
            <h3 class="font-semibold text-white">Student &amp; family profile</h3>
            <p class="mt-0.5 text-sm text-indigo-100">
                View details and update contact numbers or who receives progress updates
            </p>
        </div>

        <div class="grid gap-4 border-b border-gray-100 bg-slate-50 p-6 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg bg-white p-3 ring-1 ring-gray-200">
                <p class="text-xs font-medium uppercase text-gray-500">Student</p>
                <p class="mt-1 font-semibold text-gray-900">{{ studentProfile.name }}</p>
            </div>
            <div v-if="studentProfile.date_of_birth" class="rounded-lg bg-white p-3 ring-1 ring-gray-200">
                <p class="text-xs font-medium uppercase text-gray-500">Date of birth</p>
                <p class="mt-1 font-semibold text-gray-900">{{ studentProfile.date_of_birth }}</p>
            </div>
            <div v-if="studentProfile.enrollment?.class" class="rounded-lg bg-white p-3 ring-1 ring-emerald-100 bg-emerald-50/50">
                <p class="text-xs font-medium uppercase text-emerald-700">Class</p>
                <p class="mt-1 font-semibold text-emerald-900">{{ studentProfile.enrollment.class }}</p>
            </div>
            <div v-if="studentProfile.enrollment?.board" class="rounded-lg bg-white p-3 ring-1 ring-sky-100 bg-sky-50/50">
                <p class="text-xs font-medium uppercase text-sky-700">Board</p>
                <p class="mt-1 font-semibold text-sky-900">{{ studentProfile.enrollment.board }}</p>
            </div>
            <div v-if="studentProfile.enrollment?.academic_year" class="rounded-lg bg-white p-3 ring-1 ring-amber-100 bg-amber-50/50">
                <p class="text-xs font-medium uppercase text-amber-700">Academic year</p>
                <p class="mt-1 font-semibold text-amber-900">{{ studentProfile.enrollment.academic_year }}</p>
            </div>
            <div v-if="studentProfile.enrollment?.school_name" class="rounded-lg bg-white p-3 ring-1 ring-gray-200 sm:col-span-2">
                <p class="text-xs font-medium uppercase text-gray-500">School</p>
                <p class="mt-1 font-semibold text-gray-900">{{ studentProfile.enrollment.school_name }}</p>
            </div>
        </div>

        <form class="space-y-6 p-6" @submit.prevent="submit">
            <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
                Parents can update mobile numbers and choose who receives WhatsApp alerts when practice is completed or reports are ready.
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="profile_student_mobile" value="Student mobile (optional)" />
                    <TextInput id="profile_student_mobile" v-model="form.student_mobile" type="tel" class="mt-1 block w-full" placeholder="10-digit mobile" />
                    <InputError class="mt-1" :message="form.errors.student_mobile" />
                </div>
                <div>
                    <InputLabel for="profile_parent1_name" value="Parent 1 name *" />
                    <TextInput id="profile_parent1_name" v-model="form.parent1_name" class="mt-1 block w-full" required />
                    <InputError class="mt-1" :message="form.errors.parent1_name" />
                </div>
                <div>
                    <InputLabel for="profile_parent1_mobile" value="Parent 1 mobile *" />
                    <TextInput id="profile_parent1_mobile" v-model="form.parent1_mobile" type="tel" class="mt-1 block w-full" required placeholder="10-digit mobile" />
                    <InputError class="mt-1" :message="form.errors.parent1_mobile" />
                </div>
                <div>
                    <InputLabel for="profile_parent2_name" value="Parent 2 name" />
                    <TextInput id="profile_parent2_name" v-model="form.parent2_name" class="mt-1 block w-full" />
                    <InputError class="mt-1" :message="form.errors.parent2_name" />
                </div>
                <div>
                    <InputLabel for="profile_parent2_mobile" value="Parent 2 mobile" />
                    <TextInput id="profile_parent2_mobile" v-model="form.parent2_mobile" type="tel" class="mt-1 block w-full" placeholder="10-digit mobile" />
                    <InputError class="mt-1" :message="form.errors.parent2_mobile" />
                </div>
            </div>

            <div class="rounded-xl border border-violet-200 bg-violet-50/60 p-4">
                <p class="text-sm font-semibold text-violet-900">Progress updates — notify on:</p>
                <p class="mt-1 text-xs text-violet-700">Tick the numbers that should receive messages about assignments and results.</p>

                <div class="mt-4 space-y-3">
                    <label
                        v-for="option in notifyOptions"
                        :key="option.key"
                        class="flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 transition"
                        :class="hasMobile(option.mobile)
                            ? 'border-white bg-white shadow-sm hover:border-violet-200'
                            : 'cursor-not-allowed border-gray-200 bg-gray-50 opacity-60'"
                    >
                        <Checkbox
                            :checked="form[option.notifyField]"
                            :disabled="!hasMobile(option.mobile)"
                            @update:checked="toggleNotify(option.notifyField, $event, option.mobile)"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900">{{ option.label }}</p>
                            <p class="text-xs text-gray-500">{{ hasMobile(option.mobile) ? option.mobile : option.hint }}</p>
                        </div>
                    </label>
                </div>
                <InputError class="mt-2" :message="form.errors.notify_student_mobile || form.errors.notify_parent1_mobile || form.errors.notify_parent2_mobile" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Save profile &amp; notifications</PrimaryButton>
                <p v-if="form.recentlySuccessful" class="text-sm font-medium text-green-700">Saved successfully.</p>
            </div>
        </form>
    </div>
</template>
