<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    gradeLevel: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    protect_test_attempts: props.gradeLevel.protect_test_attempts ?? true,
    protect_practice_attempts: props.gradeLevel.protect_practice_attempts ?? true,
});

const submit = () => {
    form.patch(route('admin.classes.attempt-protection.update', props.gradeLevel.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <h3 class="text-sm font-semibold text-gray-900">Attempt protection</h3>
        <p class="mt-1 text-xs text-gray-600">
            Control anti-copy and tab-leave tracking for this class. Tests use strict mode (copy blocked + fullscreen).
            Practice uses light mode (tab hide + leave count only).
        </p>

        <form class="mt-4 space-y-3" @submit.prevent="submit">
            <label class="flex items-start gap-3 text-sm text-gray-800">
                <Checkbox
                    :checked="form.protect_test_attempts"
                    @update:checked="form.protect_test_attempts = $event"
                />
                <span>
                    <strong>Chapter tests</strong> — strict protection, fullscreen required, tab leaves recorded
                </span>
            </label>

            <label class="flex items-start gap-3 text-sm text-gray-800">
                <Checkbox
                    :checked="form.protect_practice_attempts"
                    @update:checked="form.protect_practice_attempts = $event"
                />
                <span>
                    <strong>Guided practice</strong> — light protection, tab leaves recorded
                </span>
            </label>

            <PrimaryButton type="submit" class="!py-1.5 !text-xs" :disabled="form.processing">
                Save protection settings
            </PrimaryButton>
        </form>
    </div>
</template>
