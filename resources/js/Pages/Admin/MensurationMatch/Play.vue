<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MensurationMatchBoard from '@/Components/MensurationMatchBoard.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    grade_name: { type: String, default: null },
    play: { type: Object, required: true },
    back_url: { type: String, required: true },
    restart_url: { type: String, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const feedback = computed(() => flash.value.mensuration_flash || null);
const submitting = ref(false);
const boardRef = ref(null);

function onAnswer({ item_key, formula }) {
    submitting.value = true;
    router.post(
        route('admin.mensuration-match.preview-answer'),
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
                    Not quite — that formula does not fill this FIND. Try another.
                </p>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">{{ play.board_title }}</h3>
                        <p class="text-sm text-slate-600">
                            Score {{ play.score }} ·
                            <span v-if="play.status === 'completed'" class="font-medium text-emerald-700">Board complete</span>
                            <span v-else>Tap formula → tap FIND</span>
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

                <MensurationMatchBoard ref="boardRef" :play="play" :submitting="submitting" @answer="onAnswer" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
