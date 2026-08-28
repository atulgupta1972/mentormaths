<script setup>
import GeminiFillBlankConversionPanel from '@/Components/GeminiFillBlankConversionPanel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({
    chapter: { type: Object, required: true },
    gemini: { type: Object, default: null },
});

const page = usePage();
</script>

<template>
    <Head :title="`Gemini fill-blank · Ch ${chapter.chapter_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">
                        Gemini fill-in-blank conversion
                    </h2>
                    <p class="text-sm text-gray-500">
                        {{ chapter.grade_name }} · {{ chapter.book_name }} ({{ chapter.book_code }})
                        · Ch {{ chapter.chapter_number }} — {{ chapter.title }}
                        · {{ chapter.items_count }} MCQ(s)
                    </p>
                </div>
                <Link :href="route('admin.textbooks.index')" class="text-sm text-indigo-600 hover:underline">
                    ← Textbook list
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-4 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-950">
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700">
                    <p>
                        Use Gemini to bulk-convert MCQs into fill-in-blank questions.
                        Whole numbers and simple fractions become blanks; words, true/false, and mixed fractions stay MCQ in the same set.
                    </p>
                    <p v-if="chapter.fill_blank_ready_count" class="mt-2 font-medium text-violet-950">
                        {{ chapter.fill_blank_ready_count }} of {{ chapter.items_count }} already converted and ready to publish.
                    </p>
                </div>

                <GeminiFillBlankConversionPanel
                    v-if="gemini"
                    :gemini="gemini"
                    :preview-route="route('admin.textbooks.convert-gemini-preview', chapter.id)"
                    :apply-route="route('admin.textbooks.convert-gemini-apply', chapter.id)"
                />

                <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                    <Link :href="route('admin.textbooks.show', chapter.id)">
                        <PrimaryButton type="button">Open chapter</PrimaryButton>
                    </Link>
                    <p v-if="chapter.fill_blank_set_code" class="text-sm text-slate-600">
                        Fill-blank set: <strong>{{ chapter.fill_blank_set_code }}</strong>
                        — publish from the chapter page when ready.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
