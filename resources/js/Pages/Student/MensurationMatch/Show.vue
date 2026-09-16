<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MensurationMatchBoard from '@/Components/MensurationMatchBoard.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    enabled: { type: Boolean, default: false },
    grade_name: { type: String, default: null },
    boards: { type: Array, default: () => [] },
    play: { type: Object, default: null },
    required_today: { type: Boolean, default: false },
    all_done: { type: Boolean, default: false },
    next_url: { type: String, default: null },
    next_label: { type: String, default: 'Continue' },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const feedback = computed(() => flash.value.mensuration_flash || null);

const readyByBoard = ref({});
const starting = ref(null);
const submitting = ref(false);
const boardRef = ref(null);

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

function onAnswer({ item_key, formula }) {
    if (!props.play?.session_id) return;
    submitting.value = true;
    router.post(
        route('student.mensuration-match.answer', props.play.session_id),
        { item_key, formula },
        {
            preserveScroll: true,
            onFinish: () => {
                submitting.value = false;
                boardRef.value?.clearSelection?.();
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
                    Not quite — that formula does not fill this FIND. Try another.
                </p>

                <!-- Board picker / tick to start -->
                <div v-if="!play" class="space-y-5">
                    <div
                        v-if="required_today"
                        class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900"
                    >
                        Daily series · after your formula drill · finish Mensuration Match to continue.
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="text-lg font-semibold text-slate-900">Match FIND cards to formulas</h3>
                        <p class="mt-1 text-sm text-slate-600">
                            <span v-if="grade_name">Class {{ grade_name }} · </span>
                            Tick “I’m ready”, then start a board. Tap a formula, then tap the FIND card — the blank fills only when correct.
                        </p>
                    </div>

                    <div
                        v-if="all_done && next_url"
                        class="rounded-xl border border-emerald-200 bg-emerald-50 p-5"
                    >
                        <p class="text-sm font-semibold text-emerald-900">All Mensuration boards done for today.</p>
                        <button
                            type="button"
                            class="mt-3 rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600"
                            @click="router.visit(next_url)"
                        >
                            {{ next_label }}
                        </button>
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
                                {{ board.item_count }} FIND cards
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
                                <span v-else>Pick the formula for each card</span>
                            </p>
                        </div>
                        <button
                            v-if="play.status === 'completed' && all_done && next_url"
                            type="button"
                            class="rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-600"
                            @click="router.visit(next_url)"
                        >
                            {{ next_label }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                            @click="backToBoards"
                        >
                            ← Boards
                        </button>
                    </div>

                    <MensurationMatchBoard
                        ref="boardRef"
                        :play="play"
                        :submitting="submitting"
                        @answer="onAnswer"
                    />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
