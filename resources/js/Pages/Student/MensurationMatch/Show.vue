<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MensurationDiagram from '@/Components/MensurationDiagram.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    enabled: { type: Boolean, default: false },
    grade_name: { type: String, default: null },
    boards: { type: Array, default: () => [] },
    play: { type: Object, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const feedback = computed(() => flash.value.mensuration_flash || null);

const readyByBoard = ref({});
const selectedFormula = ref(null);
const lastTap = ref(null);
const starting = ref(null);

watch(
    () => props.play?.session_id,
    () => {
        selectedFormula.value = null;
        lastTap.value = null;
    },
);

function startBoard(boardKey) {
    starting.value = boardKey;
    const form = useForm({
        board: boardKey,
        ready: !!readyByBoard.value[boardKey],
    });
    form.post(route('student.mensuration-match.start'), {
        preserveScroll: true,
        onFinish: () => {
            starting.value = null;
        },
    });
}

function openSession(sessionId) {
    router.get(route('student.mensuration-match.play', sessionId));
}

function pickFormula(formula) {
    if (!props.play || props.play.status === 'completed') return;
    selectedFormula.value = formula;
    lastTap.value = { type: 'formula', value: formula };
}

function pickItem(item) {
    if (!props.play || props.play.status === 'completed') return;
    if (item.matched) return;
    lastTap.value = { type: 'item', value: item.key };

    if (!selectedFormula.value) {
        return;
    }

    router.post(
        route('student.mensuration-match.answer', props.play.session_id),
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

function backToBoards() {
    router.get(route('student.mensuration-match.show'));
}
</script>

<template>
    <Head title="Mensuration Match" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Mensuration Match</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
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

                <!-- Board picker / tick to start -->
                <div v-if="!play" class="space-y-5">
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="text-lg font-semibold text-slate-900">Match stories to formulas</h3>
                        <p class="mt-1 text-sm text-slate-600">
                            <span v-if="grade_name">Class {{ grade_name }} · </span>
                            Tick “I’m ready”, then start a board. Tap a formula, then tap the matching story.
                        </p>
                    </div>

                    <div
                        v-if="!enabled || !boards.length"
                        class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-5 py-10 text-center text-sm text-slate-600"
                    >
                        Mensuration Match is not enabled for your class yet. Ask your teacher to map your class.
                    </div>

                    <div v-else class="grid gap-4 sm:grid-cols-2">
                        <div
                            v-for="board in boards"
                            :key="board.key"
                            class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                        >
                            <h4 class="text-base font-semibold text-slate-900">{{ board.title || board.label }}</h4>
                            <p class="mt-1 text-sm text-slate-600">{{ board.subtitle }}</p>
                            <p class="mt-2 text-xs text-slate-500">
                                {{ board.item_count }} stories
                                <span v-if="board.completed_today" class="ml-1 font-medium text-emerald-700">· done today</span>
                            </p>

                            <template v-if="board.session_id && board.completed_today">
                                <button
                                    type="button"
                                    class="mt-4 w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                                    @click="openSession(board.session_id)"
                                >
                                    Review board
                                </button>
                            </template>
                            <template v-else-if="board.session_id">
                                <button
                                    type="button"
                                    class="mt-4 w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500"
                                    @click="openSession(board.session_id)"
                                >
                                    Continue board
                                </button>
                            </template>
                            <template v-else>
                                <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm text-slate-800">
                                    <input
                                        v-model="readyByBoard[board.key]"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    I’m ready — start this board
                                </label>

                                <button
                                    type="button"
                                    class="mt-4 w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                                    :disabled="!readyByBoard[board.key] || starting === board.key"
                                    @click="startBoard(board.key)"
                                >
                                    {{ starting === board.key ? 'Starting…' : `Start ${board.title || board.label}` }}
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Active play -->
                <div v-else class="space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">{{ play.board_title }}</h3>
                            <p class="text-sm text-slate-600">
                                Score {{ play.score }} ·
                                <span v-if="play.status === 'completed'" class="font-medium text-emerald-700">Board complete</span>
                                <span v-else>Tap formula → tap story</span>
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                            @click="backToBoards"
                        >
                            ← Boards
                        </button>
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
        </div>
    </AuthenticatedLayout>
</template>
