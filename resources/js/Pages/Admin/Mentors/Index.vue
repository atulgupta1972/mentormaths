<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    teachers: { type: Array, default: () => [] },
    individualMentors: { type: Array, default: () => [] },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success || '');
const editingId = ref(null);
const drafts = reactive({});
const form = useForm({
    name: '',
    mobile: '',
    email: null,
    sync_linked_user_mobile: true,
});

const startEdit = (teacher) => {
    editingId.value = teacher.id;
    drafts[teacher.id] = {
        name: teacher.name || '',
        mobile: teacher.mobile || '',
        email: teacher.email || '',
        sync_linked_user_mobile: true,
    };
};

const cancelEdit = () => {
    editingId.value = null;
    form.clearErrors();
};

const saveTeacher = (teacher) => {
    const draft = drafts[teacher.id];
    if (! draft) {
        return;
    }

    form.name = draft.name;
    form.mobile = draft.mobile;
    form.email = draft.email || null;
    form.sync_linked_user_mobile = draft.sync_linked_user_mobile;

    form.patch(route('admin.mentors.teachers.update', teacher.id), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
        },
    });
};
</script>

<template>
    <Head title="Mentors" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Setup</p>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Mentors & WhatsApp numbers</h2>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                <p v-if="flashSuccess" class="rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-800">
                    {{ flashSuccess }}
                </p>

                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    Test-completion WhatsApp goes to the <strong>mentor mobile</strong>, not every Notify parent.
                    Do <strong>not</strong> use the Mentor Maths WhatsApp Business number here — use the teacher/parent personal WhatsApp.
                </div>

                <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <h3 class="font-medium text-gray-900">Coaching mentors (one edit updates all their students)</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Fix the wrong number once — every coaching student mapped to that mentor gets the new mobile for completion WhatsApp.
                        </p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">Mentor</th>
                                    <th class="px-4 py-3">Class</th>
                                    <th class="px-4 py-3">Students</th>
                                    <th class="px-4 py-3">WhatsApp mobile</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="teachers.length === 0">
                                    <td colspan="6" class="px-4 py-6 text-gray-500">No coaching teachers yet.</td>
                                </tr>
                                <tr v-for="teacher in teachers" :key="teacher.id">
                                    <td class="px-4 py-3">
                                        <template v-if="editingId === teacher.id">
                                            <TextInput v-model="drafts[teacher.id].name" class="block w-full max-w-xs text-sm" />
                                        </template>
                                        <template v-else>
                                            <p class="font-medium text-gray-900">{{ teacher.name }}</p>
                                            <p v-if="teacher.email" class="text-xs text-gray-500">{{ teacher.email }}</p>
                                        </template>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">
                                        {{ teacher.class_name || '—' }}
                                        <span v-if="teacher.class_city" class="text-gray-500"> · {{ teacher.class_city }}</span>
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-gray-900">{{ teacher.students_count }}</td>
                                    <td class="px-4 py-3">
                                        <template v-if="editingId === teacher.id">
                                            <TextInput v-model="drafts[teacher.id].mobile" type="tel" class="block w-full max-w-[11rem] font-mono text-sm" />
                                            <label class="mt-2 flex items-center gap-2 text-xs text-gray-600">
                                                <input v-model="drafts[teacher.id].sync_linked_user_mobile" type="checkbox" class="rounded border-gray-300 text-teal-700" />
                                                Also update linked login mobile
                                            </label>
                                        </template>
                                        <template v-else>
                                            <p class="font-mono text-gray-900">{{ teacher.mobile || '—' }}</p>
                                            <p v-if="teacher.user_mobile && teacher.user_mobile !== teacher.mobile" class="text-xs text-amber-700">
                                                Login mobile differs: {{ teacher.user_mobile }}
                                            </p>
                                        </template>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="teacher.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                        >
                                            {{ teacher.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <template v-if="editingId === teacher.id">
                                            <div class="flex flex-col items-end gap-2">
                                                <InputError :message="form.errors.name || form.errors.mobile || form.errors.email" />
                                                <div class="flex gap-2">
                                                    <SecondaryButton type="button" @click="cancelEdit">Cancel</SecondaryButton>
                                                    <PrimaryButton type="button" :disabled="form.processing" @click="saveTeacher(teacher)">
                                                        {{ form.processing ? 'Saving…' : 'Save' }}
                                                    </PrimaryButton>
                                                </div>
                                            </div>
                                        </template>
                                        <button
                                            v-else
                                            type="button"
                                            class="font-medium text-teal-700 hover:underline"
                                            @click="startEdit(teacher)"
                                        >
                                            Edit mobile
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <h3 class="font-medium text-gray-900">Individual students (mentor = notify parent)</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Edit the parent mobile on the student profile contact panel. Open a student to change Notify or the number.
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">Student</th>
                                    <th class="px-4 py-3">Mentor</th>
                                    <th class="px-4 py-3">WhatsApp mobile</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="individualMentors.length === 0">
                                    <td colspan="4" class="px-4 py-6 text-gray-500">No individual mentors with a mobile yet.</td>
                                </tr>
                                <tr v-for="row in individualMentors" :key="row.student_id">
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ row.student_name }}</td>
                                    <td class="px-4 py-3 text-gray-700">
                                        {{ row.mentor_name || '—' }}
                                        <span class="text-xs text-gray-500"> · {{ row.mentor_label }}</span>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-gray-900">{{ row.mentor_mobile }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <Link
                                            :href="route('admin.students.show', row.student_id)"
                                            class="font-medium text-teal-700 hover:underline"
                                        >
                                            Open student
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
