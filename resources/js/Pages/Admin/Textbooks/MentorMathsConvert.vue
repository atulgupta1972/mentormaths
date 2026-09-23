<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GeminiFillBlankConversionPanel from '@/Components/GeminiFillBlankConversionPanel.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    chapter: { type: Object, required: true },
    suggested: { type: Object, default: () => ({}) },
    source_ref_options: { type: Array, default: () => [] },
    rebrand: { type: Object, default: () => ({}) },
    gemini: { type: Object, default: null },
    queue_step: { type: String, default: 'rebrand' },
});

const page = usePage();

const rebrandForm = useForm({
    book_name: props.rebrand.book_name || props.suggested.name || 'MentorMaths 1',
    book_code: props.rebrand.book_code || props.suggested.code || 'MM1',
    source_ref: props.rebrand.source_ref || '',
});

const publishForm = useForm({
    allow_close_stems: false,
});
const discardForm = useForm({
    indexes: [],
    all: false,
});

const needsRebrand = computed(() => !props.chapter.is_mentormaths);
const readyCount = computed(() => props.chapter.fill_blank_ready_count || 0);
const minReady = computed(() => props.chapter.min_fill_blank_ready || 15);
const meetsMinimum = computed(() => readyCount.value >= minReady.value);
const publishBlockers = computed(() => props.chapter.publish_blockers || []);
const publishBlockerCount = computed(() => props.chapter.publish_blocker_count || publishBlockers.value.length || 0);
const hasPublishBlockers = computed(() => publishBlockerCount.value > 0);
const canPublish = computed(() => {
    if (!meetsMinimum.value || !props.chapter.is_mentormaths) {
        return false;
    }

    return !hasPublishBlockers.value || publishForm.allow_close_stems;
});
const canDiscardAllStuck = computed(() => {
    if (!hasPublishBlockers.value) {
        return false;
    }

    return (readyCount.value - publishBlockerCount.value) >= minReady.value;
});

const statusHeadline = computed(() => {
    if (needsRebrand.value) {
        return 'Pending: rebrand this book to MentorMaths.';
    }
    if (!props.chapter.items_count) {
        return 'Pending: import source questions for this chapter.';
    }
    if (!meetsMinimum.value) {
        return `Pending: invent/apply more fill-blanks (${readyCount.value}/${minReady.value}).`;
    }
    if (hasPublishBlockers.value) {
        return `Pending: rewrite or discard ${publishBlockerCount.value} too-similar stem(s), then publish.`;
    }
    if (props.queue_step === 'publish' || !props.chapter.has_fill_blank_published) {
        return 'Pending: click Publish (local only until you pull this on the server).';
    }
    return 'Done — published on this environment.';
});

const submitRebrand = () => {
    rebrandForm.post(route('admin.mentormaths-conversion.rebrand', props.chapter.id), {
        preserveScroll: true,
    });
};

const discardOne = (row) => {
    const after = readyCount.value - 1;
    if (after < minReady.value) {
        window.alert(`Cannot discard Q${row.number} — would leave only ${after} blanks (need ${minReady.value}).`);

        return;
    }

    if (!window.confirm(`Discard stuck Q${row.number}? It will not be published as fill-blank.`)) {
        return;
    }

    discardForm.indexes = [row.index];
    discardForm.all = false;
    discardForm.post(route('admin.mentormaths-conversion.discard-publish-blockers', props.chapter.id), {
        preserveScroll: true,
    });
};

const discardAllStuck = () => {
    if (!canDiscardAllStuck.value) {
        window.alert(`Cannot discard all stuck — remaining blanks would drop below ${minReady.value}.`);

        return;
    }

    if (!window.confirm(
        `Discard all ${publishBlockerCount.value} stuck blanks and keep ${readyCount.value - publishBlockerCount.value} good ones?`,
    )) {
        return;
    }

    discardForm.indexes = [];
    discardForm.all = true;
    discardForm.post(route('admin.mentormaths-conversion.discard-publish-blockers', props.chapter.id), {
        preserveScroll: true,
    });
};

const publish = () => {
    if (!window.confirm('Publish fill-blank + written for this chapter? It will leave the conversion queue.')) {
        return;
    }

    publishForm.post(route('admin.mentormaths-conversion.publish', props.chapter.id));
};
</script>

<template>
    <Head :title="`Convert · ${chapter.label || chapter.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        Convert to MentorMaths
                    </h2>
                    <p class="text-sm text-gray-500">
                        {{ chapter.grade_name }} · {{ chapter.book_name }} ({{ chapter.book_code }})
                        · {{ chapter.label || `Ch ${chapter.chapter_number} — ${chapter.title}` }}
                    </p>
                </div>
                <Link
                    :href="route('admin.mentormaths-conversion.index')"
                    class="text-sm text-indigo-600 hover:underline"
                >
                    ← Back to queue
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <ol class="grid gap-2 sm:grid-cols-4 text-xs">
                    <li
                        class="rounded-md px-3 py-2 font-semibold ring-1"
                        :class="needsRebrand ? 'bg-amber-50 text-amber-950 ring-amber-200' : 'bg-emerald-50 text-emerald-900 ring-emerald-200'"
                    >
                        1. Rebrand
                    </li>
                    <li
                        class="rounded-md px-3 py-2 font-semibold ring-1"
                        :class="queue_step === 'import' ? 'bg-amber-50 text-amber-950 ring-amber-200' : (chapter.items_count ? 'bg-emerald-50 text-emerald-900 ring-emerald-200' : 'bg-slate-50 text-slate-600 ring-slate-200')"
                    >
                        2. Questions ready
                    </li>
                    <li
                        class="rounded-md px-3 py-2 font-semibold ring-1"
                        :class="queue_step === 'transform' || (readyCount > 0 && !meetsMinimum) || hasPublishBlockers ? 'bg-amber-50 text-amber-950 ring-amber-200' : (meetsMinimum ? 'bg-emerald-50 text-emerald-900 ring-emerald-200' : 'bg-slate-50 text-slate-600 ring-slate-200')"
                    >
                        3. Transform (min {{ minReady }})
                    </li>
                    <li
                        class="rounded-md px-3 py-2 font-semibold ring-1"
                        :class="queue_step === 'publish' && !hasPublishBlockers ? 'bg-amber-50 text-amber-950 ring-amber-200' : (chapter.has_fill_blank_published ? 'bg-emerald-50 text-emerald-900 ring-emerald-200' : 'bg-slate-50 text-slate-600 ring-slate-200')"
                    >
                        4. Publish → leaves queue
                    </li>
                </ol>

                <div
                    class="rounded-lg border px-4 py-3 text-sm"
                    :class="hasPublishBlockers || !meetsMinimum || needsRebrand
                        ? 'border-amber-300 bg-amber-50 text-amber-950'
                        : 'border-sky-200 bg-sky-50 text-sky-950'"
                >
                    <p class="font-semibold">Where you are (this machine only)</p>
                    <p class="mt-1">{{ statusHeadline }}</p>
                    <p class="mt-2 text-xs opacity-90">
                        Work on <code class="rounded bg-white/70 px-1">maths_foundation.test</code> stays local until you
                        <code class="rounded bg-white/70 px-1">git pull</code> + publish on the production server.
                    </p>
                </div>

                <div
                    v-if="chapter.is_mentormaths && chapter.items_count"
                    class="rounded-lg border px-4 py-3 text-sm"
                    :class="meetsMinimum && !hasPublishBlockers
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-950'
                        : 'border-amber-200 bg-amber-50 text-amber-950'"
                >
                    <strong>Fill-blank progress:</strong>
                    {{ readyCount }} / {{ minReady }} minimum
                    <span v-if="!meetsMinimum">
                        — need {{ chapter.remaining_to_minimum || (minReady - readyCount) }} more before publish.
                        Apply convertibles, then use Invent numeric pack for skipped rows.
                    </span>
                    <span v-else-if="hasPublishBlockers">
                        — count is fine, but {{ publishBlockerCount }} stem(s) still fail the similarity gate (listed below).
                    </span>
                    <span v-else> — count OK; publish when ready.</span>
                </div>

                <div
                    v-if="hasPublishBlockers"
                    class="rounded-lg border-2 border-rose-300 bg-rose-50 p-4 text-sm text-rose-950"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold">Pending before publish · {{ publishBlockerCount }} too-similar</p>
                            <p class="mt-1 text-rose-900">
                                Rewrite them in the transform pack below, <strong>or discard</strong> a few stuck ones
                                if you already have {{ minReady }}+ good blanks.
                            </p>
                        </div>
                        <SecondaryButton
                            type="button"
                            class="!border-rose-300 !bg-white !text-rose-900"
                            :disabled="discardForm.processing || !canDiscardAllStuck"
                            @click="discardAllStuck"
                        >
                            {{ discardForm.processing ? 'Discarding…' : `Discard all ${publishBlockerCount} stuck` }}
                        </SecondaryButton>
                    </div>
                    <p
                        v-if="!canDiscardAllStuck"
                        class="mt-2 text-xs text-rose-800"
                    >
                        Discard-all locked — keeping only good blanks would drop below {{ minReady }}.
                    </p>
                    <ul class="mt-3 max-h-52 space-y-2 overflow-y-auto">
                        <li
                            v-for="row in publishBlockers"
                            :key="`blocker-${row.number}`"
                            class="flex flex-wrap items-center justify-between gap-2 rounded border border-rose-200 bg-white/70 px-3 py-2"
                        >
                            <div>
                                <span class="font-semibold">Q{{ row.number }}</span>
                                <span v-if="row.label"> · {{ row.label }}</span>
                                <p class="mt-0.5 text-xs text-rose-800">{{ row.reason }}</p>
                            </div>
                            <SecondaryButton
                                type="button"
                                class="!border-rose-200 !bg-white !px-2 !py-1 !text-xs !text-rose-900"
                                :disabled="discardForm.processing || (readyCount - 1) < minReady"
                                @click="discardOne(row)"
                            >
                                Discard
                            </SecondaryButton>
                        </li>
                    </ul>
                </div>

                <!-- Step 1 -->
                <div class="rounded-lg border border-teal-200 bg-white p-5 shadow-sm">
                    <h3 class="font-semibold text-teal-950">1. Rebrand book (student-facing name)</h3>
                    <p class="mt-1 text-sm text-teal-900">
                        Renames this whole book for the class (all its chapters). Source ref stays admin-only.
                    </p>

                    <form class="mt-4 space-y-4" @submit.prevent="submitRebrand">
                        <div>
                            <InputLabel for="book_name" value="MentorMaths book name" />
                            <TextInput id="book_name" v-model="rebrandForm.book_name" class="mt-1 block w-full" required :disabled="!needsRebrand && chapter.is_mentormaths" />
                            <InputError :message="rebrandForm.errors.book_name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="book_code" value="Book code" />
                            <TextInput id="book_code" v-model="rebrandForm.book_code" class="mt-1 block w-full" required :disabled="!needsRebrand && chapter.is_mentormaths" />
                            <p class="mt-1 text-xs text-gray-500">Suggested: {{ suggested.code }}</p>
                            <InputError :message="rebrandForm.errors.book_code" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="source_ref" value="Internal source ref" />
                            <select
                                id="source_ref"
                                v-model="rebrandForm.source_ref"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                required
                                :disabled="!needsRebrand && chapter.is_mentormaths"
                            >
                                <option value="" disabled>Choose source…</option>
                                <option
                                    v-for="opt in source_ref_options"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </option>
                            </select>
                            <InputError :message="rebrandForm.errors.source_ref" class="mt-1" />
                        </div>

                        <PrimaryButton
                            v-if="needsRebrand"
                            :disabled="rebrandForm.processing"
                        >
                            {{ rebrandForm.processing ? 'Saving…' : 'Save rebrand & continue' }}
                        </PrimaryButton>
                        <p v-else class="text-sm font-medium text-emerald-800">
                            Already on MentorMaths line
                            <span v-if="chapter.source_ref">({{ chapter.source_ref }})</span>.
                        </p>
                    </form>
                </div>

                <!-- Step 2 / 3 -->
                <div v-if="chapter.is_mentormaths" class="space-y-4">
                    <div v-if="!chapter.items_count" class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
                        No questions imported yet.
                        <Link
                            :href="route('admin.textbooks.show', chapter.id)"
                            class="ml-1 font-semibold text-indigo-700 hover:underline"
                        >
                            Open chapter → import source extracts
                        </Link>
                        then return here to transform.
                    </div>

                    <div v-else-if="gemini" class="rounded-lg border border-violet-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-violet-950">3. Transform to fill-in-blanks</h3>
                        <p class="mt-1 text-sm text-violet-900">
                            Rewrite wording, change numbers/names. Similarity gate blocks near-copies.
                        </p>
                        <div class="mt-4">
                            <GeminiFillBlankConversionPanel
                                :gemini="gemini"
                                :preview-route="route('admin.mentormaths-conversion.convert-gemini-preview', chapter.id)"
                                :apply-route="route('admin.mentormaths-conversion.convert-gemini-apply', chapter.id)"
                            />
                        </div>
                    </div>

                    <div class="rounded-lg border border-emerald-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-emerald-950">4. Publish fill-blank + written</h3>
                        <p class="mt-1 text-sm text-emerald-900">
                            {{ readyCount }} / {{ minReady }} fill-in-blank ready.
                            <template v-if="!meetsMinimum">
                                Publish stays locked until you reach {{ minReady }}.
                            </template>
                            <template v-else-if="hasPublishBlockers && !publishForm.allow_close_stems">
                                Locked until you rewrite {{ publishBlockerCount }} too-similar stem(s), or tick allow below.
                            </template>
                            <template v-else>
                                After publish, this chapter leaves the <strong>local</strong> queue.
                                Production still needs a separate publish after you deploy code + re-run convert there (or migrate content).
                            </template>
                        </p>
                        <label
                            v-if="hasPublishBlockers && meetsMinimum"
                            class="mt-3 flex cursor-pointer items-start gap-2 text-sm text-emerald-950"
                        >
                            <input
                                v-model="publishForm.allow_close_stems"
                                type="checkbox"
                                class="mt-0.5 rounded border-emerald-400 text-emerald-700 focus:ring-emerald-500"
                            >
                            <span>
                                Allow close-to-source stems
                                <span class="block text-xs text-emerald-800/80">
                                    Publish anyway for common/normal sums ({{ publishBlockerCount }} similar stem(s)).
                                </span>
                            </span>
                        </label>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <PrimaryButton
                                type="button"
                                :disabled="publishForm.processing || !canPublish"
                                @click="publish"
                            >
                                {{ publishForm.processing ? 'Publishing…' : 'Publish & remove from queue' }}
                            </PrimaryButton>
                            <Link
                                :href="route('admin.textbooks.show', chapter.id)"
                                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50"
                            >
                                Open full chapter page
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
