<script setup>
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    confirmation: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const isPracticeSet = computed(() => props.confirmation?.bank_purpose === 'practice_set');
const setCode = computed(() => props.confirmation?.set_code || '');
const addMcqsUrl = computed(() => {
    if (!props.confirmation?.chapter_id) {
        return route('admin.questions.create');
    }

    return route('admin.questions.create', { syllabus_chapter_id: props.confirmation.chapter_id });
});
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <div class="p-6">
            <p class="text-sm font-semibold uppercase tracking-wide text-green-700">Saved successfully</p>
            <h3 class="mt-2 text-xl font-semibold text-gray-900">
                {{ confirmation?.question_count }} question(s) saved as {{ confirmation?.purpose_label }}
            </h3>
            <p v-if="confirmation?.chapter_label" class="mt-1 text-sm text-gray-600">
                {{ confirmation.chapter_label }}
            </p>
            <p v-if="confirmation?.mode_label" class="mt-2 text-sm text-gray-700">
                {{ confirmation.mode_label }}
            </p>

            <div v-if="setCode" class="mt-6 rounded-xl border-2 border-indigo-200 bg-indigo-50 px-6 py-5 text-center">
                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">
                    Set code when you package
                </p>
                <p class="mt-2 font-mono text-4xl font-bold tracking-wide text-indigo-900">{{ setCode }}</p>
                <p v-if="confirmation?.topics_count > 1" class="mt-2 text-xs text-indigo-800">
                    {{ confirmation.topics_count }} topics in this set
                </p>
            </div>

            <p class="mt-4 text-sm text-gray-600">
                <template v-if="isPracticeSet">
                    One practice set bank for this upload. Click <strong>Package as {{ setCode || 'S…' }}</strong> on the chapter page when ready. Students answer one question at a time with instant feedback.
                </template>
                <template v-else>
                    One chapter test bank for this upload. Click <strong>Create as {{ setCode || 'T…' }}</strong> when ready. Students answer all questions together.
                </template>
            </p>

            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <Link :href="addMcqsUrl">
                    <SecondaryButton type="button">Add more MCQs</SecondaryButton>
                </Link>
                <PrimaryButton type="button" @click="emit('close')">View question bank</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
