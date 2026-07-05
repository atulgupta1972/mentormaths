<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { computed } from 'vue';

const props = defineProps({
    topics: {
        type: Array,
        default: () => [],
    },
    modelValue: {
        type: Array,
        default: () => [],
    },
    generating: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue', 'generate-prompt']);

const plan = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

const updateCell = (index, field, raw) => {
    const next = plan.value.map((row, i) => {
        if (i !== index) {
            return row;
        }

        return {
            ...row,
            [field]: Math.max(0, Number.parseInt(String(raw), 10) || 0),
        };
    });
    plan.value = next;
};

const rowTotal = (row) => (row.easy || 0) + (row.medium || 0) + (row.hard || 0);

const columnTotals = computed(() => ({
    easy: plan.value.reduce((sum, row) => sum + (row.easy || 0), 0),
    medium: plan.value.reduce((sum, row) => sum + (row.medium || 0), 0),
    hard: plan.value.reduce((sum, row) => sum + (row.hard || 0), 0),
    total: plan.value.reduce((sum, row) => sum + rowTotal(row), 0),
}));

const canGenerate = computed(() => columnTotals.value.total > 0);
</script>

<template>
    <div class="rounded-lg border border-indigo-200 bg-white p-6 shadow-sm">
        <h3 class="font-medium text-gray-900">Chapter question plan</h3>
        <p class="mt-1 text-sm text-gray-600">
            Set how many MCQs you want per topic and difficulty. Questions will be saved into each topic bank.
        </p>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <th class="px-3 py-2">Topic</th>
                        <th class="px-3 py-2 w-24">Easy</th>
                        <th class="px-3 py-2 w-24">Medium</th>
                        <th class="px-3 py-2 w-24">Hard</th>
                        <th class="px-3 py-2 w-20">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in plan" :key="row.topic_id" class="border-b">
                        <td class="px-3 py-2 font-medium text-gray-800">
                            {{ index + 1 }}. {{ row.topic_name }}
                        </td>
                        <td class="px-3 py-2">
                            <input
                                type="number"
                                min="0"
                                class="w-full rounded-md border-gray-300 text-sm"
                                :value="row.easy"
                                @input="updateCell(index, 'easy', $event.target.value)"
                            />
                        </td>
                        <td class="px-3 py-2">
                            <input
                                type="number"
                                min="0"
                                class="w-full rounded-md border-gray-300 text-sm"
                                :value="row.medium"
                                @input="updateCell(index, 'medium', $event.target.value)"
                            />
                        </td>
                        <td class="px-3 py-2">
                            <input
                                type="number"
                                min="0"
                                class="w-full rounded-md border-gray-300 text-sm"
                                :value="row.hard"
                                @input="updateCell(index, 'hard', $event.target.value)"
                            />
                        </td>
                        <td class="px-3 py-2 text-center font-semibold text-indigo-700">
                            {{ rowTotal(row) }}
                        </td>
                    </tr>
                    <tr class="bg-gray-50 font-semibold">
                        <td class="px-3 py-2">Total</td>
                        <td class="px-3 py-2 text-center">{{ columnTotals.easy }}</td>
                        <td class="px-3 py-2 text-center">{{ columnTotals.medium }}</td>
                        <td class="px-3 py-2 text-center">{{ columnTotals.hard }}</td>
                        <td class="px-3 py-2 text-center text-indigo-700">{{ columnTotals.total }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <PrimaryButton
                type="button"
                :disabled="!canGenerate || generating"
                @click="emit('generate-prompt')"
            >
                {{ generating ? 'Building prompt…' : 'Generate Cursor prompt for chapter' }}
            </PrimaryButton>
            <p v-if="!canGenerate" class="text-xs text-amber-700">Enter at least one question count above.</p>
        </div>
    </div>
</template>
