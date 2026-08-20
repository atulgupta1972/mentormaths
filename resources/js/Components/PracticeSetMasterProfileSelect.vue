<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import { computed } from 'vue';

const props = defineProps({
    masterProfiles: {
        type: Array,
        default: () => [],
    },
    difficultyMarks: {
        type: Object,
        default: () => ({ easy: 1, medium: 2, hard: 3 }),
    },
    bookOptions: {
        type: Array,
        default: () => [],
    },
    modelValue: {
        type: String,
        default: '',
    },
    textbookChapterId: {
        type: [Number, String, null],
        default: null,
    },
});

const emit = defineEmits(['update:modelValue', 'update:textbookChapterId', 'apply']);

const selectedProfile = computed({
    get: () => props.modelValue || '',
    set: (value) => emit('update:modelValue', value),
});

const selectedBookId = computed({
    get: () => props.textbookChapterId ?? '',
    set: (value) => emit('update:textbookChapterId', value === '' ? null : value),
});

const active = computed(() => props.masterProfiles.find((p) => p.value === selectedProfile.value) ?? null);

const marksLine = computed(() => {
    const m = props.difficultyMarks || {};

    return `Marks master: Easy ${m.easy ?? 1} · Medium ${m.medium ?? 2} · Hard ${m.hard ?? 3}`;
});

const onProfileChange = () => {
    if (active.value) {
        emit('apply', active.value);
    }
};
</script>

<template>
    <div class="rounded-lg border border-sky-200 bg-sky-50/60 p-4">
        <h4 class="text-sm font-semibold text-slate-900">Set master — Learner / Achiever / Expert</h4>
        <p class="mt-1 text-xs text-slate-600">
            Select a profile to auto-fill easy / medium / hard. {{ marksLine }}
        </p>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <InputLabel value="Profile" />
                <select
                    v-model="selectedProfile"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                    @change="onProfileChange"
                >
                    <option value="">Custom counts…</option>
                    <option
                        v-for="profile in masterProfiles"
                        :key="profile.value"
                        :value="profile.value"
                    >
                        {{ profile.label }} — {{ profile.easy }}E / {{ profile.medium }}M / {{ profile.hard }}H ({{ profile.total }} · score {{ profile.score }})
                    </option>
                </select>
            </div>
            <div>
                <InputLabel value="Book basis (optional)" />
                <select
                    v-model="selectedBookId"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                    :disabled="!bookOptions.length"
                >
                    <option value="">Syllabus topic only (current prompt)</option>
                    <option
                        v-for="book in bookOptions"
                        :key="book.id"
                        :value="book.id"
                    >
                        {{ book.label }}{{ book.topic_count ? ` · ${book.topic_count} topics` : '' }}
                    </option>
                </select>
                <p v-if="!bookOptions.length" class="mt-1 text-[11px] text-slate-500">
                    No published book chapter linked to this syllabus chapter yet.
                </p>
                <p v-else class="mt-1 text-[11px] text-slate-500">
                    If a book is selected, the JSON prompt uses that book’s topic list.
                </p>
            </div>
        </div>

        <p v-if="active" class="mt-2 text-xs font-medium text-sky-900">
            {{ active.label }}: {{ active.tagline }}
        </p>
    </div>
</template>
