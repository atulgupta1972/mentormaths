<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ContactNumbersPanel from '@/Components/ContactNumbersPanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    registrationRequest: Object,
    shareLinks: Object,
});

const page = usePage();

const form = useForm({
    admin_notes: props.registrationRequest.admin_notes ?? '',
});

const isPending = computed(() => props.registrationRequest.status === 'pending');

const contactFields = computed(() => [
    {
        key: 'student',
        field: 'student_mobile',
        notifyField: 'notify_student_mobile',
        label: 'Student mobile',
        mobile: props.registrationRequest.student_mobile,
        notify: props.registrationRequest.notify_student_mobile,
    },
    {
        key: 'parent1',
        field: 'parent1_mobile',
        notifyField: 'notify_parent1_mobile',
        label: 'Parent 1 mobile',
        name: props.registrationRequest.parent1_name,
        mobile: props.registrationRequest.parent1_mobile,
        notify: props.registrationRequest.notify_parent1_mobile ?? true,
        required: true,
    },
    {
        key: 'parent2',
        field: 'parent2_mobile',
        notifyField: 'notify_parent2_mobile',
        label: 'Parent 2 mobile',
        name: props.registrationRequest.parent2_name || null,
        mobile: props.registrationRequest.parent2_mobile,
        notify: props.registrationRequest.notify_parent2_mobile,
    },
]);

const approve = () => {
    form.post(route('admin.registration-requests.approve', props.registrationRequest.id));
};

const reject = () => {
    if (confirm('Reject this registration request?')) {
        form.post(route('admin.registration-requests.reject', props.registrationRequest.id));
    }
};

const generatedLogin = computed(() => page.props.flash?.generated_login);
</script>

<template>
    <Head :title="`Request — ${registrationRequest.student_name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ registrationRequest.student_name }}
                </h2>
                <Link
                    :href="route('admin.registration-requests.index')"
                    class="text-sm text-indigo-600 hover:text-indigo-800"
                >
                    Back to list
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="$page.props.flash?.success"
                    class="rounded-md bg-green-50 p-4 text-sm text-green-800"
                >
                    {{ $page.props.flash.success }}
                </div>

                <div
                    v-if="generatedLogin"
                    class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                >
                    <p class="font-semibold">Student login created — share these credentials:</p>
                    <p class="mt-2"><strong>Email:</strong> {{ generatedLogin.email }}</p>
                    <p><strong>Password:</strong> {{ generatedLogin.password }}</p>
                    <p v-if="$page.props.flash?.email_sent" class="mt-2 text-green-800">
                        Login details were also emailed to the parent/student.
                    </p>
                    <p v-else-if="!generatedLogin.email.endsWith('@mathsfoundation.local')" class="mt-2 text-red-800">
                        Email could not be sent — please share these credentials manually.
                    </p>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="grid gap-4 p-6 sm:grid-cols-2">
                        <div>
                            <p class="text-xs uppercase text-gray-500">Status</p>
                            <p class="mt-1 capitalize font-medium">{{ registrationRequest.status }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Academic year</p>
                            <p class="mt-1">{{ registrationRequest.academic_year?.name }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Class</p>
                            <p class="mt-1">{{ registrationRequest.grade_level?.name }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Board</p>
                            <p class="mt-1">{{ registrationRequest.board?.name }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">School</p>
                            <p class="mt-1">{{ registrationRequest.school_name }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Date of birth</p>
                            <p class="mt-1">{{ registrationRequest.date_of_birth || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Email</p>
                            <p class="mt-1">{{ registrationRequest.email || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Parent 1</p>
                            <p class="mt-1">{{ registrationRequest.parent1_name }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Parent 2</p>
                            <p class="mt-1">{{ registrationRequest.parent2_name || '—' }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <p class="text-xs uppercase text-gray-500">Notes</p>
                            <p class="mt-1">{{ registrationRequest.notes || '—' }}</p>
                        </div>
                    </div>
                </div>

                <ContactNumbersPanel
                    :student-name="registrationRequest.student_name"
                    :contacts="contactFields"
                    :save-url="route('admin.registration-requests.contacts.update', registrationRequest.id)"
                    :share-links="shareLinks"
                />

                <div v-if="isPending" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <form class="space-y-4 p-6" @submit.prevent="approve">
                        <div>
                            <InputLabel for="admin_notes" value="Admin notes (optional)" />
                            <textarea
                                id="admin_notes"
                                v-model="form.admin_notes"
                                rows="3"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                        <div class="flex gap-3">
                            <PrimaryButton :disabled="form.processing">Approve</PrimaryButton>
                            <DangerButton type="button" :disabled="form.processing" @click="reject">
                                Reject
                            </DangerButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
