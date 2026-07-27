<script setup>
defineProps({
    summary: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
        <div class="border-b border-gray-200 px-6 py-4">
            <h3 class="font-medium text-gray-900">Formula drill memory</h3>
            <p class="mt-1 text-sm text-gray-500">
                Daily 10-formula MCQ gate before work. Pool from completed chapters (all classes) plus global basics.
            </p>
        </div>

        <div class="grid gap-4 p-6 sm:grid-cols-4">
            <div class="rounded-lg border border-gray-200 p-3 text-center">
                <p class="text-2xl font-bold text-indigo-700">{{ summary.pool_size }}</p>
                <p class="text-xs uppercase tracking-wide text-gray-500">Formula pool</p>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 text-center">
                <p class="text-2xl font-bold text-green-700">{{ summary.mastered_count }}</p>
                <p class="text-xs uppercase tracking-wide text-gray-500">Mastered</p>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 text-center">
                <p class="text-2xl font-bold text-amber-700">{{ summary.needs_review_count }}</p>
                <p class="text-xs uppercase tracking-wide text-gray-500">Need review</p>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 text-center">
                <p class="text-2xl font-bold text-rose-700">{{ summary.total_failures }}</p>
                <p class="text-xs uppercase tracking-wide text-gray-500">Total failures</p>
            </div>
        </div>

        <div v-if="summary.weak_formulas?.length" class="border-t border-gray-100 px-6 py-4">
            <h4 class="text-sm font-semibold text-gray-900">Weakest formulas</h4>
            <ul class="mt-3 space-y-2">
                <li
                    v-for="row in summary.weak_formulas"
                    :key="row.question_id"
                    class="rounded-lg border border-gray-200 px-3 py-2 text-sm"
                >
                    <p class="text-gray-800">{{ row.question_text }}</p>
                    <p class="mt-1 text-xs text-gray-500">
                        Failures: {{ row.total_failures }}
                        <span v-if="row.needs_review" class="font-semibold text-amber-700"> · Still in review queue</span>
                    </p>
                </li>
            </ul>
        </div>

        <p v-else class="border-t border-gray-100 px-6 py-4 text-sm text-gray-500">
            No formula drill attempts recorded yet.
        </p>
    </div>
</template>
