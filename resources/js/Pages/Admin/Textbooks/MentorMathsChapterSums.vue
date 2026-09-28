<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    chapter: { type: Object, required: true },
    current_book_name: { type: String, default: '' },
});

const page = usePage();
const form = useForm({});

const applyName = () => {
    form.post(route('admin.mentormaths-conversion.replace-book', props.chapter.id));
};
</script>

<template>
    <Head :title="chapter.label || 'Book name'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">{{ chapter.label }}</h2>
                    <p class="text-sm text-gray-500">{{ chapter.grade_name }}</p>
                </div>
                <Link
                    :href="route('admin.mentormaths-conversion.index')"
                    class="text-sm text-indigo-600 hover:underline"
                >
                    ← Back to queue
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-xl space-y-4 px-4 sm:px-6">
                <p v-if="page.props.flash?.success" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </p>
                <p v-if="page.props.flash?.error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </p>

                <section class="rounded-xl border border-teal-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-800">Book name everywhere</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900">{{ current_book_name }}</p>
                    <p class="mt-3 text-sm text-slate-600">
                        This is the name used for this chapter in the question bank, study plan, and student pages.
                        <template v-if="!chapter.items_count">
                            This chapter has no questions stored yet.
                        </template>
                    </p>
                    <button
                        type="button"
                        class="mt-4 rounded-md bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50"
                        :disabled="form.processing"
                        @click="applyName"
                    >
                        {{ form.processing ? 'Saving…' : `Use ${current_book_name} for this chapter` }}
                    </button>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
