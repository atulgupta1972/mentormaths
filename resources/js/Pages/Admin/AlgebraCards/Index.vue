<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    packs: { type: Array, default: () => [] },
    selectedPack: { type: Object, default: null },
    cards: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const selectPack = (key) => {
    router.get(route('admin.algebra-cards.index'), { pack: key }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const loadPackHref = computed(() => {
    if (!props.selectedPack?.sample_pack_key) {
        return null;
    }
    return route('admin.questions.pack-upload', {
        sample_pack: props.selectedPack.sample_pack_key,
    });
});

const tryHref = computed(() => {
    if (!props.selectedPack?.key) {
        return null;
    }
    return route('admin.algebra-cards.try', {
        pack: props.selectedPack.key,
        restart: 1,
    });
});
</script>

<template>
    <Head title="Algebra card" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Algebra card</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Teaching cards for common algebra misconceptions. More packs will be added here.
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                <p
                    v-if="flash.success"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
                >
                    {{ flash.success }}
                </p>
                <p
                    v-if="flash.error"
                    class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
                >
                    {{ flash.error }}
                </p>

                <div v-if="!packs.length" class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
                    No algebra card packs found on this server. Pull latest
                    <code class="rounded bg-white px-1">samples/</code> and try again.
                </div>

                <div v-else class="grid gap-6 lg:grid-cols-[240px_1fr]">
                    <aside class="space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Packs</p>
                        <button
                            v-for="pack in packs"
                            :key="pack.key"
                            type="button"
                            class="block w-full rounded-lg border px-3 py-3 text-left text-sm transition"
                            :class="selectedPack?.key === pack.key
                                ? 'border-indigo-400 bg-indigo-50 text-indigo-950'
                                : 'border-slate-200 bg-white text-slate-800 hover:border-indigo-200'"
                            @click="selectPack(pack.key)"
                        >
                            <span class="block font-semibold">{{ pack.label }}</span>
                            <span class="mt-0.5 block text-xs text-slate-500">
                                {{ pack.level }} · {{ pack.card_count }} cards
                            </span>
                        </button>
                    </aside>

                    <section v-if="selectedPack" class="space-y-4">
                        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-lg font-semibold text-slate-900">{{ selectedPack.label }}</h3>
                                    <p class="mt-1 text-sm text-slate-600">{{ selectedPack.description }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <Link
                                        v-if="tryHref"
                                        :href="tryHref"
                                        class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-800"
                                    >
                                        Try as student
                                    </Link>
                                    <Link
                                        v-if="loadPackHref"
                                        :href="loadPackHref"
                                        class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800"
                                    >
                                        Load into question bank
                                    </Link>
                                </div>
                            </div>
                            <p class="mt-3 text-xs text-slate-500">
                                Each card: first Wrong &amp; Right, then the question.
                                Use <span class="font-medium">Try as student</span> to run that flow.
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <article
                                v-for="card in cards"
                                :key="card.id"
                                class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                            >
                                <div class="border-b border-slate-100 px-3 py-2">
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">
                                        Card {{ card.id }}
                                    </p>
                                    <p class="text-sm font-semibold text-slate-900">{{ card.title }}</p>
                                </div>
                                <a
                                    v-if="card.diagram_url"
                                    :href="card.diagram_url"
                                    target="_blank"
                                    class="block bg-slate-50 p-2"
                                >
                                    <img
                                        :src="card.diagram_url"
                                        :alt="card.title"
                                        class="mx-auto max-h-72 w-full object-contain"
                                    >
                                </a>
                                <p v-else class="p-4 text-xs text-amber-800">Figure missing for this card.</p>
                            </article>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
