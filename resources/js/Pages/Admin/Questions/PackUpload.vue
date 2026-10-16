<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    chapters: { type: Array, default: () => [] },
    chapterTopics: { type: Array, default: () => [] },
    topicsByChapter: { type: Object, default: () => ({}) },
    selectedChapterId: { type: [Number, String, null], default: null },
    selectedTopicId: { type: [Number, String, null], default: null },
    selectedGrade: { type: Object, default: null },
    masterProfiles: { type: Array, default: () => [] },
    samplePacks: { type: Array, default: () => [] },
    selectedSamplePack: { type: String, default: '' },
});

const page = usePage();
const packInput = ref(null);
const selectedPackName = ref('');

const initialSample = props.selectedSamplePack || '';
const initialSampleMeta = (props.samplePacks || []).find((pack) => pack.key === initialSample) || null;

const form = useForm({
    pack: null,
    sample_pack: initialSample,
    syllabus_chapter_id: props.selectedChapterId || '',
    syllabus_topic_id: props.selectedTopicId || '',
    output_type: initialSampleMeta?.default_output_type || 'fill_in_blank',
    master_profile: props.masterProfiles[0]?.value || '',
    notes: '',
});

const activeSamplePack = computed(
    () => (props.samplePacks || []).find((pack) => pack.key === form.sample_pack) || null,
);

const canSubmit = computed(() => (
    Boolean(form.syllabus_chapter_id)
    && (Boolean(form.pack) || Boolean(form.sample_pack))
));

const topics = computed(() => {
    if (!form.syllabus_chapter_id) {
        return [];
    }

    return props.topicsByChapter?.[form.syllabus_chapter_id]
        || props.chapterTopics
        || [];
});

const activeProfile = computed(
    () => props.masterProfiles.find((profile) => profile.value === form.master_profile) || null,
);

watch(() => form.syllabus_chapter_id, (chapterId, previous) => {
    if (String(chapterId) === String(previous)) {
        return;
    }

    form.syllabus_topic_id = '';

    if (!chapterId) {
        return;
    }

    router.get(
        route('admin.questions.pack-upload'),
        { syllabus_chapter_id: chapterId },
        { preserveState: true, replace: true, preserveScroll: true },
    );
});

const onPackSelected = (event) => {
    const file = event.target.files?.[0] || null;
    form.pack = file;
    selectedPackName.value = file?.name || '';
    if (file) {
        form.sample_pack = '';
    }
};

watch(() => form.sample_pack, (key) => {
    const pack = (props.samplePacks || []).find((row) => row.key === key);
    if (!pack) {
        return;
    }
    form.pack = null;
    selectedPackName.value = '';
    if (packInput.value) {
        packInput.value.value = '';
    }
    if (pack.default_output_type) {
        form.output_type = pack.default_output_type;
    }
});

const submit = () => {
    form.post(route('admin.questions.pack-upload.store'), {
        forceFormData: true,
        onSuccess: () => {
            form.pack = null;
            form.sample_pack = '';
            selectedPackName.value = '';
            if (packInput.value) {
                packInput.value.value = '';
            }
        },
    });
};
</script>

<template>
    <Head title="Upload pack" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Upload pack</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Pick a ready pack from the dropdown (e.g. Fraction simplification cards), or upload your own zip.
                        Choose MCQ / fill-in-blank / written, then Learner / Achiever / Expert.
                    </p>
                </div>
                <Link
                    v-if="form.syllabus_chapter_id"
                    :href="route('admin.questions.chapters.show', form.syllabus_chapter_id)"
                    class="text-sm font-medium text-indigo-700 hover:underline"
                >
                    Back to chapter
                </Link>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
                >
                    {{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash?.error || page.props.errors?.pack"
                    class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
                >
                    {{ page.props.flash?.error || page.props.errors?.pack }}
                </div>

                <form class="space-y-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm" @submit.prevent="submit">
                    <div>
                        <InputLabel value="Chapter" />
                        <select
                            v-model="form.syllabus_chapter_id"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                            required
                        >
                            <option value="">Select chapter…</option>
                            <option
                                v-for="chapter in chapters"
                                :key="chapter.id"
                                :value="chapter.id"
                            >
                                {{ chapter.label }}
                            </option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.syllabus_chapter_id" />
                    </div>

                    <div>
                        <InputLabel value="Topic (optional)" />
                        <select
                            v-model="form.syllabus_topic_id"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                            :disabled="!form.syllabus_chapter_id"
                        >
                            <option value="">Whole chapter</option>
                            <option
                                v-for="topic in topics"
                                :key="topic.id"
                                :value="topic.id"
                            >
                                {{ topic.name }}
                            </option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Leave blank to put the pack on the chapter. Pick a topic for a topic practice / written sheet.
                        </p>
                        <InputError class="mt-1" :message="form.errors.syllabus_topic_id" />
                    </div>

                    <div>
                        <InputLabel value="Ready pack (first run)" />
                        <select
                            v-model="form.sample_pack"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                        >
                            <option value="">None — upload my own zip</option>
                            <option
                                v-for="pack in samplePacks"
                                :key="pack.key"
                                :value="pack.key"
                            >
                                {{ pack.label }} ({{ pack.question_count }} sums)
                            </option>
                        </select>
                        <p v-if="activeSamplePack" class="mt-1 text-xs text-teal-800">
                            {{ activeSamplePack.description }}
                            · Save as will default to {{ activeSamplePack.default_output_type === 'mcq' ? 'MCQ' : activeSamplePack.default_output_type }}.
                        </p>
                        <p v-else class="mt-1 text-xs text-gray-500">
                            Use this to load built-in teaching packs without finding the zip file.
                        </p>
                        <InputError class="mt-1" :message="form.errors.sample_pack" />
                    </div>

                    <div>
                        <InputLabel value="Save as" />
                        <div class="mt-2 grid gap-3 sm:grid-cols-3">
                            <label
                                class="flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3"
                                :class="form.output_type === 'mcq' ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200'"
                            >
                                <input v-model="form.output_type" type="radio" value="mcq" class="mt-1" />
                                <span>
                                    <span class="block text-sm font-semibold text-gray-900">MCQ set</span>
                                    <span class="mt-0.5 block text-xs text-gray-600">Online — pick an option</span>
                                </span>
                            </label>
                            <label
                                class="flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3"
                                :class="form.output_type === 'fill_in_blank' ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200'"
                            >
                                <input v-model="form.output_type" type="radio" value="fill_in_blank" class="mt-1" />
                                <span>
                                    <span class="block text-sm font-semibold text-gray-900">Fill-in-blank set</span>
                                    <span class="mt-0.5 block text-xs text-gray-600">Online — type answers</span>
                                </span>
                            </label>
                            <label
                                class="flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3"
                                :class="form.output_type === 'written' ? 'border-violet-400 bg-violet-50' : 'border-gray-200'"
                            >
                                <input v-model="form.output_type" type="radio" value="written" class="mt-1" />
                                <span>
                                    <span class="block text-sm font-semibold text-gray-900">Written PDF</span>
                                    <span class="mt-0.5 block text-xs text-gray-600">Print / upload work</span>
                                </span>
                            </label>
                        </div>
                        <InputError class="mt-1" :message="form.errors.output_type" />
                    </div>

                    <div>
                        <InputLabel value="Level" />
                        <select
                            v-model="form.master_profile"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                            required
                        >
                            <option
                                v-for="profile in masterProfiles"
                                :key="profile.value"
                                :value="profile.value"
                            >
                                {{ profile.label }} — {{ profile.tagline }}
                            </option>
                        </select>
                        <p v-if="activeProfile" class="mt-1 text-xs text-gray-500">
                            Set code tier: {{ activeProfile.label }}. Sums stay exactly as in your zip (counts are not auto-filled).
                        </p>
                        <InputError class="mt-1" :message="form.errors.master_profile" />
                    </div>

                    <div>
                        <InputLabel value="Or upload your own zip" />
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                class="rounded-md border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-900 hover:bg-indigo-100"
                                :disabled="Boolean(form.sample_pack)"
                                @click="packInput?.click()"
                            >
                                Choose .zip
                            </button>
                            <span class="text-sm text-gray-600">
                                {{ form.sample_pack
                                    ? `Using ready pack: ${activeSamplePack?.label || form.sample_pack}`
                                    : (selectedPackName || 'No file chosen') }}
                            </span>
                        </div>
                        <input
                            ref="packInput"
                            type="file"
                            accept=".zip,application/zip"
                            class="hidden"
                            @change="onPackSelected"
                        />
                        <p class="mt-2 text-xs text-gray-500">
                            Include <code class="rounded bg-gray-100 px-1">questions.json</code>.
                            Add diagram images if needed (e.g. <code class="rounded bg-gray-100 px-1">q1.png</code>).
                            For written, you may also include <code class="rounded bg-gray-100 px-1">sheet.pdf</code> (or any PDF) — otherwise one is generated.
                        </p>
                        <InputError class="mt-1" :message="form.errors.pack" />
                    </div>

                    <div v-if="form.output_type === 'written'">
                        <InputLabel value="Notes (optional)" />
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm"
                            placeholder="Internal note for this written sheet"
                        />
                        <InputError class="mt-1" :message="form.errors.notes" />
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
                        <PrimaryButton :disabled="form.processing || !canSubmit">
                            {{ form.processing ? 'Uploading…' : (form.sample_pack ? 'Load ready pack' : 'Upload sums') }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
