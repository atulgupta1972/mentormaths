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
const primaryCode = computed(() => props.confirmation?.set_code || props.confirmation?.banks?.[0]?.set_code || '');
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

            <div v-if="primaryCode" class="mt-6 rounded-xl border-2 border-indigo-200 bg-indigo-50 px-6 py-5 text-center">
                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">
                    {{ isPracticeSet ? 'Practice set code (when packaged)' : 'Chapter test code (when packaged)' }}
                </p>
                <p class="mt-2 font-mono text-4xl font-bold tracking-wide text-indigo-900">{{ primaryCode }}</p>
            </div>

            <ul v-if="confirmation?.banks?.length > 1" class="mt-4 space-y-2 rounded-lg bg-gray-50 p-4 text-sm text-gray-700">
                <li v-for="bank in confirmation.banks" :key="bank.topic_name">
                    <span class="font-medium">{{ bank.topic_name }}</span>
                    — {{ bank.questions_count }} question(s)
                    <span v-if="bank.set_code" class="font-mono text-indigo-700"> · {{ bank.set_code }}</span>
                </li>
            </ul>

            <p class="mt-4 text-sm text-gray-600">
                <template v-if="isPracticeSet">
                    Questions are in the topic bank(s) below. Click <strong>Package as {{ primaryCode || 'S…' }}</strong> on each card when you are ready to publish a practice set.
                </template>
                <template v-else>
                    Questions are in the chapter test bank below. Click <strong>Create as {{ primaryCode || 'T…' }}</strong> when you are ready to publish the mixed chapter test.
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
