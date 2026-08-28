<script setup>
defineProps({
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div
        class="rounded-lg border border-indigo-200 bg-indigo-50/80"
        :class="compact ? 'p-3' : 'p-4'"
    >
        <p class="text-sm font-semibold text-indigo-950">Gemini answer check — step by step</p>
        <p v-if="!compact" class="mt-1 text-xs text-indigo-900">
            Use this after a chapter is published. Gemini marks clearly correct MCQs; you fix anything flagged.
        </p>
        <ol class="mt-3 space-y-2 text-sm text-indigo-950" :class="compact ? 'text-xs' : ''">
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">1</span>
                <span>Open the chapter and scroll to <strong>Gemini answer check</strong>.</span>
            </li>
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">2</span>
                <span>Click <strong>Copy prompt for Gemini</strong> — paste it into Gemini (browser side panel is fine).</span>
            </li>
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">3</span>
                <span>When Gemini replies, copy the full answer block back.</span>
            </li>
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">4</span>
                <span>Paste into <strong>Paste Gemini reply</strong> → click <strong>Apply Gemini review</strong>.</span>
            </li>
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">5</span>
                <span>Questions marked <strong>Correct</strong> are auto-verified. Fix any <strong>Needs Verification</strong> rows, then run Gemini again on just those — no need to reset the full chapter.</span>
            </li>
            <li class="flex gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">6</span>
                <span>Progress shows as <strong>verified/total</strong> on the task list and admin matrix.</span>
            </li>
        </ol>
    </div>
</template>
