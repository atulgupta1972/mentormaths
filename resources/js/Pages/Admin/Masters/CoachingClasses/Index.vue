<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    classes: Array,
    mappableStudents: { type: Array, default: () => [] },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);

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

const applyPinLookup = async (pin, targetForm, statusRef) => {
    const digits = String(pin || '').replace(/\D/g, '');
    if (digits.length !== 6) {
        statusRef.value = digits.length ? 'Enter 6 digits to auto-fill state & city.' : '';
        return;
    }

    pinLookupBusy.value = true;
    statusRef.value = 'Looking up PIN…';

    try {
        const response = await fetch(route('admin.coaching-classes.pincode', digits), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const data = await response.json();

        if (data.ok) {
            targetForm.state = data.state || '';
            targetForm.city = data.city || '';
            statusRef.value = data.area
                ? `Filled from ${data.area} (${data.city}, ${data.state})`
                : `Filled: ${data.city}, ${data.state}`;
        } else {
            statusRef.value = data.message || 'Could not look up PIN. Enter state & city manually.';
        }
    } catch {
        statusRef.value = 'PIN lookup failed. Enter state & city manually.';
    } finally {
        pinLookupBusy.value = false;
    }
};

watch(() => createForm.pin_code, (value) => {
    createForm.pin_code = String(value || '').replace(/\D/g, '').slice(0, 6);
    if (pinLookupTimer) {
        clearTimeout(pinLookupTimer);
    }
    pinLookupTimer = setTimeout(() => applyPinLookup(createForm.pin_code, createForm, pinLookupStatus), 350);
});

const createClass = () => {
    createForm.post(route('admin.coaching-classes.store'), {
        onSuccess: () => {
            createForm.reset('name', 'address', 'pin_code', 'state', 'city', 'phone', 'notes');
            pinLookupStatus.value = '';
        },
    });
};

const panelId = ref(null);
const panelMode = ref(null); // teachers | edit | map

const openPanel = (id, mode) => {
    if (panelId.value === id && panelMode.value === mode) {
        panelId.value = null;
        panelMode.value = null;
        return;
    }
    panelId.value = id;
    panelMode.value = mode;
    if (mode === 'edit') {
        const row = props.classes.find((c) => c.id === id);
        if (row) {
            editForm.name = row.name || '';
            editForm.address = row.address || '';
            editForm.pin_code = row.pin_code || '';
            editForm.state = row.state || '';
            editForm.city = row.city || '';
            editForm.phone = row.phone || '';
            editForm.notes = row.notes || '';
            editForm.is_active = !!row.is_active;
            editPinStatus.value = '';
        }
    }
    if (mode === 'map') {
        mapForm.student_ids = [];
        mapForm.coaching_class_teacher_id = '';
        mapSearch.value = '';
    }
};

const editForm = useForm({
    name: '',
    address: '',
    pin_code: '',
    state: '',
    city: '',
    phone: '',
    notes: '',
    is_active: true,
});

const editPinStatus = ref('');
let editPinTimer = null;

watch(() => editForm.pin_code, (value) => {
    if (panelMode.value !== 'edit') {
        return;
    }
    editForm.pin_code = String(value || '').replace(/\D/g, '').slice(0, 6);
    if (editPinTimer) {
        clearTimeout(editPinTimer);
    }
    editPinTimer = setTimeout(() => applyPinLookup(editForm.pin_code, editForm, editPinStatus), 350);
});

const saveEdit = (classId) => {
    editForm.patch(route('admin.coaching-classes.update', classId), {
        preserveScroll: true,
        onSuccess: () => {
            panelId.value = null;
            panelMode.value = null;
        },
    });
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
    router.post(route('admin.coaching-classes.toggle-active', row.id), {}, { preserveScroll: true });
};

const mapForm = useForm({
    student_ids: [],
    coaching_class_teacher_id: '',
});

const mapSearch = ref('');

const filteredMappableStudents = computed(() => {
    const q = mapSearch.value.trim().toLowerCase();
    if (!q) {
        return props.mappableStudents;
    }

    return props.mappableStudents.filter((s) =>
        [s.name, s.email, s.parent1_mobile, s.coaching_class_name]
            .filter(Boolean)
            .some((v) => String(v).toLowerCase().includes(q)),
    );
});

const toggleStudent = (id) => {
    const sid = Number(id);
    const idx = mapForm.student_ids.indexOf(sid);
    if (idx >= 0) {
        mapForm.student_ids.splice(idx, 1);
    } else {
        mapForm.student_ids.push(sid);
    }
};

const submitMap = (classId) => {
    mapForm.post(route('admin.coaching-classes.map-students', classId), {
        preserveScroll: true,
        onSuccess: () => {
            mapForm.reset();
            panelId.value = null;
            panelMode.value = null;
        },
    });
};

const activeTeachers = (row) => (row.teachers || []).filter((t) => t.is_active);
</script>

<template>
    <Head title="Coaching classes" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Coaching / Tuition classes</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="flashSuccess" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ flashSuccess }}</div>
                <div v-if="flashError" class="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ flashError }}</div>

                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="font-medium text-gray-900">Add coaching class</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Enter PIN code first — state and city fill automatically. Add teachers, then map active-login students.
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
                            <p v-if="pinLookupStatus" class="mt-1 text-xs text-indigo-700">{{ pinLookupStatus }}</p>
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
                                <th class="px-4 py-3 text-right text-xs uppercase text-gray-500">Actions</th>
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
                                    <td class="px-4 py-3 text-right text-sm whitespace-nowrap space-x-2">
                                        <button type="button" class="font-medium text-indigo-700 hover:underline" @click="openPanel(row.id, 'edit')">
                                            {{ panelId === row.id && panelMode === 'edit' ? 'Hide' : 'Edit' }}
                                        </button>
                                        <button type="button" class="font-medium text-indigo-700 hover:underline" @click="openPanel(row.id, 'teachers')">
                                            Teachers
                                        </button>
                                        <button type="button" class="font-medium text-emerald-700 hover:underline" @click="openPanel(row.id, 'map')">
                                            Map students
                                        </button>
                                        <button type="button" class="text-gray-600 hover:underline" @click="toggleClassActive(row)">
                                            {{ row.is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </td>
                                </tr>

                                <tr v-if="panelId === row.id && panelMode === 'edit'">
                                    <td colspan="8" class="bg-amber-50/60 px-4 py-4">
                                        <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveEdit(row.id)">
                                            <div class="sm:col-span-2">
                                                <InputLabel value="Name *" />
                                                <TextInput v-model="editForm.name" class="mt-1 block w-full" required />
                                            </div>
                                            <div>
                                                <InputLabel value="PIN code *" />
                                                <TextInput v-model="editForm.pin_code" class="mt-1 block w-full font-mono" maxlength="6" required />
                                                <p v-if="editPinStatus" class="mt-1 text-xs text-indigo-700">{{ editPinStatus }}</p>
                                                <InputError class="mt-1" :message="editForm.errors.pin_code" />
                                            </div>
                                            <div>
                                                <InputLabel value="Phone" />
                                                <TextInput v-model="editForm.phone" class="mt-1 block w-full" />
                                            </div>
                                            <div>
                                                <InputLabel value="State *" />
                                                <TextInput v-model="editForm.state" class="mt-1 block w-full" required />
                                                <InputError class="mt-1" :message="editForm.errors.state" />
                                            </div>
                                            <div>
                                                <InputLabel value="City / District *" />
                                                <TextInput v-model="editForm.city" class="mt-1 block w-full" required />
                                                <InputError class="mt-1" :message="editForm.errors.city" />
                                            </div>
                                            <div class="sm:col-span-2">
                                                <InputLabel value="Address" />
                                                <TextInput v-model="editForm.address" class="mt-1 block w-full" />
                                            </div>
                                            <div class="sm:col-span-2 flex gap-2">
                                                <PrimaryButton :disabled="editForm.processing">Save changes</PrimaryButton>
                                                <SecondaryButton type="button" @click="openPanel(row.id, 'edit')">Cancel</SecondaryButton>
                                            </div>
                                        </form>
                                    </td>
                                </tr>

                                <tr v-if="panelId === row.id && panelMode === 'teachers'">
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
                                                        <button type="button" class="text-xs font-semibold text-rose-700 hover:underline" @click="removeTeacher(teacher)">
                                                            Remove
                                                        </button>
                                                    </div>
                                                </li>
                                            </ul>
                                            <p v-else class="text-sm text-slate-600">No teachers yet — add at least one with a mobile number before mapping students.</p>

                                            <form class="grid gap-2 rounded-lg border border-dashed border-indigo-300 bg-white p-3 sm:grid-cols-4" @submit.prevent="addTeacher(row.id)">
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
                                                    <PrimaryButton :disabled="teacherSaving">Add teacher</PrimaryButton>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>

                                <tr v-if="panelId === row.id && panelMode === 'map'">
                                    <td colspan="8" class="bg-emerald-50/50 px-4 py-4">
                                        <div class="space-y-3">
                                            <div v-if="row.students?.length" class="rounded-lg border border-emerald-200 bg-white p-3">
                                                <p class="text-xs font-bold uppercase tracking-wide text-emerald-900">Already mapped ({{ row.students.length }})</p>
                                                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                                                    <li v-for="student in row.students" :key="student.id" class="flex flex-wrap justify-between gap-2 py-1.5">
                                                        <span class="font-medium text-slate-900">{{ student.name }}</span>
                                                        <span class="text-slate-600">
                                                            Mentor: {{ student.coaching_class_teacher?.name || '—' }}
                                                            <span v-if="student.user?.email" class="ml-2 font-mono text-xs">{{ student.user.email }}</span>
                                                        </span>
                                                    </li>
                                                </ul>
                                            </div>

                                            <p v-if="!activeTeachers(row).length" class="text-sm font-semibold text-rose-700">
                                                Add an active teacher first, then map students (teacher = mentor).
                                            </p>

                                            <form v-else class="space-y-3 rounded-lg border border-emerald-300 bg-white p-3" @submit.prevent="submitMap(row.id)">
                                                <div>
                                                    <InputLabel value="Teacher / mentor *" />
                                                    <select v-model="mapForm.coaching_class_teacher_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                                        <option value="" disabled>Select teacher</option>
                                                        <option v-for="teacher in activeTeachers(row)" :key="teacher.id" :value="teacher.id">
                                                            {{ teacher.name }} · {{ teacher.mobile }}
                                                        </option>
                                                    </select>
                                                    <InputError class="mt-1" :message="mapForm.errors.coaching_class_teacher_id" />
                                                </div>

                                                <div>
                                                    <InputLabel value="Active-login students *" />
                                                    <TextInput v-model="mapSearch" class="mt-1 block w-full" placeholder="Search name / email / mobile" />
                                                    <div class="mt-2 max-h-56 overflow-y-auto rounded-md border border-slate-200">
                                                        <label
                                                            v-for="student in filteredMappableStudents"
                                                            :key="student.id"
                                                            class="flex cursor-pointer items-start gap-2 border-b border-slate-100 px-3 py-2 text-sm hover:bg-slate-50"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                class="mt-1 rounded border-gray-300 text-indigo-600"
                                                                :checked="mapForm.student_ids.includes(student.id)"
                                                                @change="toggleStudent(student.id)"
                                                            >
                                                            <span>
                                                                <span class="font-semibold text-slate-900">{{ student.name }}</span>
                                                                <span v-if="student.email" class="ml-2 font-mono text-xs text-slate-600">{{ student.email }}</span>
                                                                <span
                                                                    v-if="student.coaching_class_id"
                                                                    class="mt-0.5 block text-xs text-amber-800"
                                                                >
                                                                    Currently: {{ student.coaching_class_name || 'another class' }}
                                                                    <span v-if="Number(student.coaching_class_id) === Number(row.id)">(this class)</span>
                                                                </span>
                                                            </span>
                                                        </label>
                                                        <p v-if="!filteredMappableStudents.length" class="px-3 py-4 text-center text-sm text-slate-500">
                                                            No active-login students found.
                                                        </p>
                                                    </div>
                                                    <p class="mt-1 text-xs text-slate-600">
                                                        Selected: {{ mapForm.student_ids.length }}
                                                    </p>
                                                    <InputError class="mt-1" :message="mapForm.errors.student_ids" />
                                                </div>

                                                <PrimaryButton :disabled="mapForm.processing || !mapForm.student_ids.length">
                                                    Map selected students
                                                </PrimaryButton>
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
