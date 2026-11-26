<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    student: { type: Object, required: true },
    enrollmentOptions: { type: Array, default: () => [] },
    coachingClasses: { type: Array, default: () => [] },
    mentor: { type: Object, default: null },
});

const form = useForm({
    enrollment_source: props.student.enrollment_source || 'individual',
    coaching_class_id: props.student.coaching_class_id || '',
    coaching_class_teacher_id: props.student.coaching_class_teacher_id || '',
    mentor_name: props.mentor?.name || '',
    mentor_mobile: props.mentor?.mobile || '',
});

const teachersForClass = computed(() => {
    const row = props.coachingClasses.find((c) => Number(c.id) === Number(form.coaching_class_id));

    return (row?.teachers || []).filter((t) => t.is_active || Number(t.id) === Number(form.coaching_class_teacher_id));
});

const selectedTeacher = computed(() =>
    teachersForClass.value.find((t) => Number(t.id) === Number(form.coaching_class_teacher_id)) || null,
);

const syncTeacherFields = (teacher) => {
    form.mentor_name = teacher?.name || '';
    form.mentor_mobile = teacher?.mobile || '';
};

watch(() => form.coaching_class_id, () => {
    const stillValid = teachersForClass.value.some((t) => Number(t.id) === Number(form.coaching_class_teacher_id));
    if (!stillValid) {
        form.coaching_class_teacher_id = '';
        syncTeacherFields(null);
    }
});

watch(() => form.coaching_class_teacher_id, (teacherId) => {
    if (! teacherId) {
        syncTeacherFields(null);

        return;
    }

    const teacher = teachersForClass.value.find((t) => Number(t.id) === Number(teacherId));
    syncTeacherFields(teacher);
});

watch(() => form.enrollment_source, (source) => {
    if (source !== 'coaching') {
        form.coaching_class_id = '';
        form.coaching_class_teacher_id = '';
        form.mentor_name = '';
        form.mentor_mobile = '';
    }
});

const submit = () => {
    form.patch(route('admin.students.mentor.map', props.student.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="overflow-hidden rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-medium text-gray-900">Enrollment & mentor</h3>
                <p class="mt-1 text-sm text-gray-600">
                    Individual → parent with Notify tick is the mentor.
                    Coaching → pick class and teacher, then edit their WhatsApp number below.
                </p>
            </div>
            <Link
                :href="route('admin.mentors.index')"
                class="text-sm font-medium text-teal-700 hover:underline"
            >
                Edit all mentors →
            </Link>
        </div>

        <div
            v-if="mentor"
            class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-950"
        >
            <span class="font-semibold">Current mentor:</span>
            <span v-if="mentor.name"> {{ mentor.name }}</span>
            <span v-if="mentor.mobile" class="font-mono"> · {{ mentor.mobile }}</span>
            <span class="text-indigo-800"> · {{ mentor.label }}</span>
            <span v-if="!mentor.name" class="text-rose-700"> Not mapped yet</span>
        </div>

        <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <div class="sm:col-span-2">
                <InputLabel value="Enrolled by" />
                <div class="mt-2 flex flex-wrap gap-3">
                    <label
                        v-for="opt in enrollmentOptions"
                        :key="opt.value"
                        class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                        :class="[
                            form.enrollment_source === opt.value
                                ? 'border-indigo-500 bg-indigo-50 text-indigo-950'
                                : 'border-gray-200 bg-white text-gray-700',
                            opt.enabled ? 'cursor-pointer' : 'cursor-not-allowed opacity-50',
                        ]"
                    >
                        <input
                            v-model="form.enrollment_source"
                            type="radio"
                            class="text-indigo-600"
                            :value="opt.value"
                            :disabled="!opt.enabled"
                        >
                        {{ opt.label }}
                    </label>
                </div>
            </div>

            <template v-if="form.enrollment_source === 'coaching'">
                <div>
                    <InputLabel value="Coaching class *" />
                    <select
                        v-model="form.coaching_class_id"
                        class="mt-1 block w-full rounded-md border-gray-300"
                        required
                    >
                        <option value="" disabled>Select coaching class</option>
                        <option
                            v-for="row in coachingClasses"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}{{ row.city ? ` (${row.city})` : '' }}
                        </option>
                    </select>
                    <p v-if="!coachingClasses.length" class="mt-1 text-xs text-rose-700">
                        Add a coaching class under Setup → Coaching classes first.
                    </p>
                </div>
                <div>
                    <InputLabel value="Teacher / mentor *" />
                    <select
                        v-model="form.coaching_class_teacher_id"
                        class="mt-1 block w-full rounded-md border-gray-300"
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
                </div>

                <div
                    v-if="selectedTeacher"
                    class="sm:col-span-2 rounded-lg border border-amber-200 bg-amber-50/70 p-4"
                >
                    <p class="text-sm font-medium text-amber-950">Edit this teacher’s WhatsApp number</p>
                    <p class="mt-1 text-xs text-amber-900">
                        Use their personal WhatsApp — not the Mentor Maths business number.
                        Saving updates this teacher for every student mapped to them.
                    </p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Teacher name" />
                            <TextInput v-model="form.mentor_name" class="mt-1 block w-full" required />
                        </div>
                        <div>
                            <InputLabel value="Teacher WhatsApp mobile *" />
                            <TextInput v-model="form.mentor_mobile" type="tel" class="mt-1 block w-full font-mono" required />
                        </div>
                    </div>
                </div>
            </template>

            <div class="sm:col-span-2">
                <PrimaryButton :disabled="form.processing">Save enrollment & mentor</PrimaryButton>
                <p
                    v-if="form.errors.enrollment_source || form.errors.coaching_class_id || form.errors.coaching_class_teacher_id || form.errors.mentor_mobile || form.errors.mentor_name"
                    class="mt-2 text-sm text-rose-700"
                >
                    {{ form.errors.enrollment_source || form.errors.coaching_class_id || form.errors.coaching_class_teacher_id || form.errors.mentor_mobile || form.errors.mentor_name }}
                </p>
            </div>
        </form>
    </div>
</template>
