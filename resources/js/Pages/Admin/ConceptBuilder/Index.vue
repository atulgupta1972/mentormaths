<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    uploaderMode: { type: Boolean, default: false },
    gradeLevel: { type: Object, default: null },
    chapters: { type: Array, default: () => [] },
    createUrl: { type: String, default: '' },
});

const page = usePage();

const groupedByBoard = computed(() => {
    const groups = [];
    let current = null;

    for (const row of props.chapters) {
        const key = row.board_code || row.board_name || 'Syllabus';
        if (! current || current.key !== key) {
            current = {
                key,
                label: row.board_code ? `${row.board_code} syllabus` : (row.board_name || 'Syllabus'),
                rows: [],
            };
            groups.push(current);
        }
        current.rows.push(row);
    }

    return groups;
});
</script>

<template>
    <Head title="Concept builder" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">Concept builder</h2>
                    <p class="text-sm text-gray-500">
                        Class-wise syllabus chapters → build teach/check concept cards from the uploaded PDF.
                        {{ gradeLevel ? `Showing ${gradeLevel.name}.` : 'Select a class from the top bar.' }}
                    </p>
                </div>
                <Link v-if="createUrl" :href="createUrl">
                    <PrimaryButton type="button">Upload chapter PDF</PrimaryButton>
                </Link>
                <p v-else-if="uploaderMode" class="text-xs text-slate-500">
                    Missing PDF? Open the chapter row below to upload, or ask admin to create the chapter first.
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg border border-violet-200 bg-violet-50/70 px-4 py-3 text-sm text-violet-950">
                    PDF ready? Click <strong>Build concepts</strong>. After approve, click <strong>Run concepts</strong> to walk through the teach/check cards (admin / uploader preview).
                </div>

                <div v-if="!gradeLevel" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    Choose a class in the top bar to list syllabus chapters.
                </div>

                <div v-else-if="!chapters.length" class="rounded-lg border border-slate-200 bg-white px-4 py-6 text-sm text-slate-600">
                    No syllabus chapters found for this class / year. Set up the syllabus first under Setup.
                </div>

                <div
                    v-for="group in groupedByBoard"
                    :key="group.key"
                    class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                >
                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-bold uppercase tracking-wide text-slate-700">
                        {{ group.label }}
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <li
                            v-for="row in group.rows"
                            :key="row.syllabus_chapter_id"
                            class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900">{{ row.label }}</p>
                                <p class="mt-1 text-xs text-slate-600">
                                    <template v-if="row.uploads?.length">
                                        <span
                                            v-for="(upload, idx) in row.uploads"
                                            :key="upload.id"
                                            class="mr-2 inline-flex items-center gap-1"
                                        >
                                            <span v-if="idx">·</span>
                                            {{ upload.book_code || upload.book_name }}
                                            <span
                                                class="rounded-full px-1.5 py-px text-[10px] font-semibold"
                                                :class="upload.has_pdf ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'"
                                            >
                                                {{ upload.has_pdf ? 'PDF ready' : 'No PDF' }}
                                            </span>
                                            <span
                                                v-if="upload.concept_path_status"
                                                class="rounded-full bg-violet-100 px-1.5 py-px text-[10px] font-semibold text-violet-900"
                                            >
                                                {{ upload.concept_path_status_label }}
                                            </span>
                                        </span>
                                    </template>
                                    <template v-else>
                                        No textbook chapter linked yet — upload PDF first.
                                    </template>
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Link
                                    v-if="row.run_url"
                                    :href="row.run_url"
                                    class="rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-white hover:bg-emerald-800"
                                >
                                    Run concepts
                                </Link>
                                <Link
                                    v-if="row.has_pdf"
                                    :href="row.primary_action_url"
                                    class="rounded-md px-3 py-1.5 text-xs font-bold uppercase tracking-wide"
                                    :class="row.run_url
                                        ? 'border border-violet-300 bg-white text-violet-800 hover:bg-violet-50'
                                        : 'bg-violet-700 text-white hover:bg-violet-800'"
                                >
                                    {{ row.run_url ? 'Edit concepts' : 'Build concepts' }}
                                </Link>
                                <Link
                                    v-else-if="row.uploads?.[0]?.upload_url || createUrl"
                                    :href="row.uploads?.[0]?.upload_url || createUrl"
                                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-slate-800 hover:bg-slate-50"
                                >
                                    {{ row.uploads?.length ? 'Open chapter · upload PDF' : 'Upload chapter PDF' }}
                                </Link>
                                <span
                                    v-else
                                    class="rounded-md border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-900"
                                >
                                    Ask admin to create chapter
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
