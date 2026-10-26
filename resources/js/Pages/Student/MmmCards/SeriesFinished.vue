<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    nextPart: { type: Object, default: null },
    seriesLabel: { type: String, default: '' },
});
</script>

<template>
    <div class="space-y-2">
        <p class="text-sm font-semibold text-emerald-900">
            {{ nextPart?.url ? 'You finished this part.' : `You finished ${seriesLabel || 'this method'}.` }}
        </p>
        <Link
            v-if="nextPart?.url"
            :href="nextPart.url"
            class="inline-flex rounded-md bg-rose-700 px-4 py-2 text-sm font-extrabold uppercase tracking-wide text-white hover:bg-rose-800"
        >
            Next part → {{ nextPart.heading }}
        </Link>
    </div>
</template>
