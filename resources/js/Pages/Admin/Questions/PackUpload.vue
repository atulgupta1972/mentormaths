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
});

const page = usePage();
const packInput = ref(null);
const selectedPackName = ref('');

const form = useForm({
    pack: null,
    syllabus_chapter_id: props.selectedChapterId || '',
    syllabus_topic_id: props.selectedTopicId || '',
    output_type: 'fill_in_blank',
    master_profile: props.masterProfiles[0]?.value || '',
    notes: '',
});

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
};

const submit = () => {
    form.post(route('admin.questions.pack-upload.store'), {
        forceFormData: true,
        onSuccess: () => {
            form.pack = null;
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
                        Zip your JSON (+ pictures / PDF if needed). Choose fill-in-blank or written, pick Learner / Achiever / Expert — only the sums you upload are saved.
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
                        <InputLabel value="Save as" />
                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                            <label
                                class="flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3"
                                :class="form.output_type === 'fill_in_blank' ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200'"
                            >
                                <input v-model="form.output_type" type="radio" value="fill_in_blank" class="mt-1" />
                                <span>
                                    <span class="block text-sm font-semibold text-gray-900">Fill-in-blank set</span>
                                    <span class="mt-0.5 block text-xs text-gray-600">Online practice — students type answers</span>
                                </span>
                            </label>
                            <label
                                class="flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3"
                                :class="form.output_type === 'written' ? 'border-violet-400 bg-violet-50' : 'border-gray-200'"
                            >
                                <input v-model="form.output_type" type="radio" value="written" class="mt-1" />
                                <span>
                                    <span class="block text-sm font-semibold text-gray-900">Written PDF sheet</span>
                                    <span class="mt-0.5 block text-xs text-gray-600">Print / upload work — uses your PDF if in the zip</span>
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
                        <InputLabel value="Zip pack" />
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                class="rounded-md border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-900 hover:bg-indigo-100"
                                @click="packInput?.click()"
                            >
                                Choose .zip
                            </button>
                            <span class="text-sm text-gray-600">
                                {{ selectedPackName || 'No file chosen' }}
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
                        <PrimaryButton :disabled="form.processing || !form.pack || !form.syllabus_chapter_id">
                            {{ form.processing ? 'Uploading…' : 'Upload sums' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
