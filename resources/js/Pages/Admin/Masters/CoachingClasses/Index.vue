<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';

defineProps({
    classes: Array,
});

const createForm = useForm({
    name: '',
    address: '',
    pin_code: '',
    state: '',
    city: '',
    phone: '',
    notes: '',
    is_active: true,
});

const pinLookupStatus = ref('');
const pinLookupBusy = ref(false);
let pinLookupTimer = null;

const lookupPin = async (pin) => {
    const digits = String(pin || '').replace(/\D/g, '');
    if (digits.length !== 6) {
        pinLookupStatus.value = digits.length ? 'Enter 6 digits to auto-fill state & city.' : '';
        return;
    }

    pinLookupBusy.value = true;
    pinLookupStatus.value = 'Looking up PIN…';

    try {
        const response = await fetch(route('admin.coaching-classes.pincode', digits), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const data = await response.json();

        if (data.ok) {
            createForm.state = data.state || '';
            createForm.city = data.city || '';
            pinLookupStatus.value = data.area
                ? `Filled from ${data.area} (${data.city}, ${data.state})`
                : `Filled: ${data.city}, ${data.state}`;
        } else {
            pinLookupStatus.value = data.message || 'Could not look up PIN. Enter state & city manually.';
        }
    } catch {
        pinLookupStatus.value = 'PIN lookup failed. Enter state & city manually.';
    } finally {
        pinLookupBusy.value = false;
    }
};

watch(() => createForm.pin_code, (value) => {
    createForm.pin_code = String(value || '').replace(/\D/g, '').slice(0, 6);
    if (pinLookupTimer) {
        clearTimeout(pinLookupTimer);
    }
    pinLookupTimer = setTimeout(() => lookupPin(createForm.pin_code), 350);
});

const createClass = () => {
    createForm.post(route('admin.coaching-classes.store'), {
        onSuccess: () => {
            createForm.reset('name', 'address', 'pin_code', 'state', 'city', 'phone', 'notes');
            pinLookupStatus.value = '';
        },
    });
};

const expandedId = ref(null);

const toggle = (id) => {
    expandedId.value = expandedId.value === id ? null : id;
};

const teacherDraft = reactive({
    name: '',
    mobile: '',
    email: '',
    notes: '',
});

const teacherSaving = ref(false);

const addTeacher = (classId) => {
    teacherSaving.value = true;
    router.post(route('admin.coaching-classes.teachers.store', classId), {
        name: teacherDraft.name,
        mobile: teacherDraft.mobile,
        email: teacherDraft.email || null,
        notes: teacherDraft.notes || null,
        is_active: true,
    }, {
        preserveScroll: true,
        onFinish: () => {
            teacherSaving.value = false;
        },
        onSuccess: () => {
            teacherDraft.name = '';
            teacherDraft.mobile = '';
            teacherDraft.email = '';
            teacherDraft.notes = '';
        },
    });
};

const deactivateTeacher = (teacher) => {
    router.patch(route('admin.coaching-class-teachers.update', teacher.id), {
        name: teacher.name,
        mobile: teacher.mobile,
        email: teacher.email || '',
        notes: teacher.notes || '',
        is_active: !teacher.is_active,
    }, { preserveScroll: true });
};

const removeTeacher = (teacher) => {
    if (!confirm(`Remove ${teacher.name}?`)) {
        return;
    }

    router.delete(route('admin.coaching-class-teachers.destroy', teacher.id), {
        preserveScroll: true,
    });
};

const toggleClassActive = (row) => {
    router.patch(route('admin.coaching-classes.update', row.id), {
        name: row.name,
        address: row.address || '',
        pin_code: row.pin_code || '000000',
        state: row.state || 'Unknown',
        city: row.city || 'Unknown',
        phone: row.phone || '',
        notes: row.notes || '',
        is_active: !row.is_active,
    }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Coaching classes" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Coaching / Tuition classes</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="font-medium text-gray-900">Add coaching class</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Enter PIN code first — state and city fill automatically from India Post data. You can still edit them.
                    </p>
                    <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="createClass">
                        <div class="sm:col-span-2">
                            <InputLabel for="name" value="Name *" />
                            <TextInput id="name" v-model="createForm.name" class="mt-1 block w-full" required />
                            <InputError class="mt-1" :message="createForm.errors.name" />
                        </div>
                        <div>
                            <InputLabel for="pin_code" value="PIN code *" />
                            <TextInput
                                id="pin_code"
                                v-model="createForm.pin_code"
                                class="mt-1 block w-full font-mono"
                                inputmode="numeric"
                                maxlength="6"
                                placeholder="6 digits"
                                required
                            />
                            <p v-if="pinLookupStatus" class="mt-1 text-xs" :class="pinLookupBusy ? 'text-slate-500' : 'text-indigo-700'">
                                {{ pinLookupStatus }}
                            </p>
                            <InputError class="mt-1" :message="createForm.errors.pin_code" />
                        </div>
                        <div>
                            <InputLabel for="phone" value="Phone" />
                            <TextInput id="phone" v-model="createForm.phone" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel for="state" value="State *" />
                            <TextInput id="state" v-model="createForm.state" class="mt-1 block w-full" required />
                            <InputError class="mt-1" :message="createForm.errors.state" />
                        </div>
                        <div>
                            <InputLabel for="city" value="City / District *" />
                            <TextInput id="city" v-model="createForm.city" class="mt-1 block w-full" required />
                            <InputError class="mt-1" :message="createForm.errors.city" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel for="address" value="Address" />
                            <TextInput id="address" v-model="createForm.address" class="mt-1 block w-full" />
                        </div>
                        <div class="sm:col-span-2">
                            <PrimaryButton :disabled="createForm.processing || pinLookupBusy">Create</PrimaryButton>
                        </div>
                    </form>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Class</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">PIN</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">City</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">State</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Teachers</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Students</th>
                                <th class="px-4 py-3 text-left text-xs uppercase text-gray-500">Status</th>
                                <th class="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <template v-for="row in classes" :key="row.id">
                                <tr :class="row.is_active ? '' : 'bg-slate-50 opacity-70'">
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ row.name }}</td>
                                    <td class="px-4 py-3 font-mono text-sm text-gray-700">{{ row.pin_code || '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ row.city || '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ row.state || '—' }}</td>
                                    <td class="px-4 py-3 text-sm">{{ row.teachers_count }}</td>
                                    <td class="px-4 py-3 text-sm">{{ row.students_count }}</td>
                                    <td class="px-4 py-3 text-sm capitalize">
                                        {{ row.is_active ? 'Active' : 'Inactive' }}
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                                        <button
                                            type="button"
                                            class="text-sm font-medium text-indigo-600 hover:underline"
                                            @click="toggle(row.id)"
                                        >
                                            {{ expandedId === row.id ? 'Hide teachers' : 'Teachers' }}
                                        </button>
                                        <button
                                            type="button"
                                            class="text-sm text-gray-600 hover:underline"
                                            @click="toggleClassActive(row)"
                                        >
                                            {{ row.is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="expandedId === row.id">
                                    <td colspan="8" class="bg-slate-50 px-4 py-4">
                                        <div class="space-y-3">
                                            <ul v-if="row.teachers?.length" class="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                                                <li
                                                    v-for="teacher in row.teachers"
                                                    :key="teacher.id"
                                                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                                                >
                                                    <div>
                                                        <span class="font-semibold text-slate-900">{{ teacher.name }}</span>
                                                        <span class="ml-2 font-mono text-slate-700">{{ teacher.mobile }}</span>
                                                        <span
                                                            v-if="!teacher.is_active"
                                                            class="ml-2 rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-rose-800"
                                                        >
                                                            Inactive
                                                        </span>
                                                    </div>
                                                    <div class="flex gap-2">
                                                        <SecondaryButton type="button" @click="deactivateTeacher(teacher)">
                                                            {{ teacher.is_active ? 'Deactivate' : 'Activate' }}
                                                        </SecondaryButton>
                                                        <button
                                                            type="button"
                                                            class="text-xs font-semibold text-rose-700 hover:underline"
                                                            @click="removeTeacher(teacher)"
                                                        >
                                                            Remove
                                                        </button>
                                                    </div>
                                                </li>
                                            </ul>
                                            <p v-else class="text-sm text-slate-600">No teachers yet — add at least one with a mobile number.</p>

                                            <form
                                                class="grid gap-2 rounded-lg border border-dashed border-indigo-300 bg-white p-3 sm:grid-cols-4"
                                                @submit.prevent="addTeacher(row.id)"
                                            >
                                                <div>
                                                    <InputLabel value="Teacher name *" />
                                                    <TextInput v-model="teacherDraft.name" class="mt-1 block w-full" required />
                                                </div>
                                                <div>
                                                    <InputLabel value="Mobile *" />
                                                    <TextInput v-model="teacherDraft.mobile" class="mt-1 block w-full" required />
                                                </div>
                                                <div>
                                                    <InputLabel value="Email" />
                                                    <TextInput v-model="teacherDraft.email" type="email" class="mt-1 block w-full" />
                                                </div>
                                                <div class="flex items-end">
                                                    <PrimaryButton :disabled="teacherSaving">
                                                        Add teacher
                                                    </PrimaryButton>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!classes.length">
                                <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">
                                    No coaching classes yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
