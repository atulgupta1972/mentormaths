<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MensurationDiagram from '@/Components/MensurationDiagram.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    grade_name: { type: String, default: null },
    play: { type: Object, required: true },
    back_url: { type: String, required: true },
    restart_url: { type: String, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const feedback = computed(() => flash.value.mensuration_flash || null);

const selectedFormula = ref(null);
const lastTap = ref(null);

function pickFormula(formula) {
    if (props.play.status === 'completed') return;
    selectedFormula.value = formula;
    lastTap.value = { type: 'formula', value: formula };
}

function pickItem(item) {
    if (props.play.status === 'completed') return;
    if (item.matched) return;
    lastTap.value = { type: 'item', value: item.key };
    if (!selectedFormula.value) return;

    router.post(
        route('admin.mensuration-match.preview-answer'),
        {
            item_key: item.key,
            formula: selectedFormula.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                selectedFormula.value = null;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Try Mensuration — ${grade_name || 'Class'}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Try Mensuration Match
                <span v-if="grade_name" class="font-normal text-slate-500">· {{ grade_name }}</span>
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    Admin check mode — answers are not saved against any student.
                </div>

                <p v-if="flash.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ flash.success }}
                </p>
                <p v-if="flash.error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    {{ flash.error }}
                </p>
                <p
                    v-if="feedback && !feedback.correct"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    Not quite — try another formula. {{ feedback.explanation }}
                </p>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">{{ play.board_title }}</h3>
                        <p class="text-sm text-slate-600">
                            Score {{ play.score }} ·
                            <span v-if="play.status === 'completed'" class="font-medium text-emerald-700">Board complete</span>
                            <span v-else>Tap formula → tap story</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link
                            :href="restart_url"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Restart
                        </Link>
                        <Link
                            :href="back_url"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            ← Classes
                        </Link>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-5">
                    <div class="space-y-3 lg:col-span-2">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Formulas</h4>
                        <button
                            v-for="formula in play.formulas"
                            :key="formula"
                            type="button"
                            class="block w-full rounded-xl border px-4 py-3 text-left font-mono text-sm font-semibold transition"
                            :class="
                                selectedFormula === formula
                                    ? 'border-indigo-500 bg-indigo-50 text-indigo-900 ring-2 ring-indigo-200'
                                    : 'border-slate-200 bg-white text-slate-800 hover:border-indigo-300'
                            "
                            :disabled="play.status === 'completed'"
                            @click="pickFormula(formula)"
                        >
                            {{ formula }}
                        </button>
                    </div>

                    <div class="space-y-3 lg:col-span-3">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Stories</h4>
                        <button
                            v-for="item in play.items"
                            :key="item.key"
                            type="button"
                            class="w-full rounded-xl border p-4 text-left transition"
                            :class="
                                item.matched
                                    ? 'border-emerald-300 bg-emerald-50 opacity-80'
                                    : lastTap?.type === 'item' && lastTap.value === item.key
                                      ? 'border-amber-400 bg-amber-50'
                                      : 'border-slate-200 bg-white hover:border-indigo-300'
                            "
                            :disabled="item.matched || play.status === 'completed'"
                            @click="pickItem(item)"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                <div class="shrink-0 rounded-lg bg-slate-50 p-1">
                                    <MensurationDiagram :diagram="item.diagram" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-slate-900">{{ item.story }}</p>
                                    <p v-if="item.matched" class="mt-2 font-mono text-xs font-semibold text-emerald-700">
                                        ✓ {{ item.matched_formula }}
                                    </p>
                                    <p v-else-if="item.hint" class="mt-2 text-xs capitalize text-slate-500">
                                        Looking for: {{ item.hint }}
                                    </p>
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
