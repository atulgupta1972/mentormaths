<script setup>
defineProps({
    perf: {
        type: Object,
        required: true,
    },
    title: {
        type: String,
        default: 'Chapter performance',
    },
    subtitle: {
        type: String,
        default: '',
    },
});

const performanceBarClass = (pct) => {
    if (pct == null) {
        return 'bg-slate-300';
    }
    if (pct >= 80) {
        return 'bg-emerald-500';
    }
    if (pct >= 50) {
        return 'bg-amber-500';
    }

    return 'bg-rose-500';
};
</script>

<template>
    <div class="rounded-xl border-2 border-indigo-600 bg-gradient-to-r from-indigo-50 via-white to-slate-50 p-3 shadow-md ring-1 ring-indigo-200">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-indigo-950">
                {{ title }}
            </p>
            <p v-if="subtitle" class="text-[11px] font-semibold text-indigo-800">
                {{ subtitle }}
            </p>
        </div>
        <div class="mt-2 grid gap-2 sm:grid-cols-3">
            <div class="rounded-lg border border-indigo-200 bg-white px-3 py-2 shadow-sm">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-600">Completion</p>
                <p class="mt-0.5 text-xl font-extrabold tabular-nums text-slate-900">
                    <template v-if="perf.completionPct != null">{{ perf.completionPct }}%</template>
                    <template v-else>—</template>
                </p>
                <p class="text-[10px] font-semibold text-slate-500">
                    {{ perf.done }}/{{ perf.total }} sets done
                </p>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200">
                    <div
                        class="h-full rounded-full transition-all"
                        :class="performanceBarClass(perf.completionPct)"
                        :style="{ width: `${perf.completionPct ?? 0}%` }"
                    />
                </div>
            </div>
            <div class="rounded-lg border border-indigo-200 bg-white px-3 py-2 shadow-sm">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-600">Score</p>
                <p class="mt-0.5 text-xl font-extrabold tabular-nums text-slate-900">
                    <template v-if="perf.scorePct != null">{{ perf.scorePct }}%</template>
                    <template v-else>—</template>
                </p>
                <p class="text-[10px] font-semibold text-slate-500">
                    <template v-if="perf.scoredCount">Avg of {{ perf.scoredCount }} scored set(s)</template>
                    <template v-else>No scores yet</template>
                </p>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200">
                    <div
                        class="h-full rounded-full transition-all"
                        :class="performanceBarClass(perf.scorePct)"
                        :style="{ width: `${perf.scorePct ?? 0}%` }"
                    />
                </div>
            </div>
            <div class="rounded-lg border border-indigo-200 bg-white px-3 py-2 shadow-sm">
                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-600">Revised · Correction</p>
                <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                    <p class="text-sm font-extrabold text-emerald-800">
                        Done {{ perf.correctionDone }}
                    </p>
                    <p class="text-sm font-extrabold text-amber-800">
                        Pending {{ perf.correctionPending }}
                    </p>
                </div>
                <p
                    v-if="perf.openWrongs > 0"
                    class="mt-1 text-[10px] font-bold text-orange-800"
                >
                    {{ perf.openWrongs }} wrong still to redo
                </p>
                <p
                    v-else-if="!perf.correctionDone && !perf.correctionPending"
                    class="mt-1 text-[10px] font-semibold text-slate-500"
                >
                    No corrections yet
                </p>
            </div>
        </div>
        <p
            v-if="perf.chapterCount != null"
            class="mt-2 text-[10px] font-semibold text-indigo-900/80"
        >
            Based on {{ perf.chapterCount }} chapter{{ perf.chapterCount === 1 ? '' : 's' }} marked studied / under study
            <template v-if="perf.chapterLabels?.length">
                · {{ perf.chapterLabels.join(', ') }}
            </template>
        </p>
    </div>
</template>
