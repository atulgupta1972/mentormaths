<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();

const props = defineProps({
    variant: {
        type: String,
        default: 'uploader',
        validator: (v) => ['admin', 'uploader'].includes(v),
    },
});

const guideUrl = computed(() => page.props.contentUploaderGuideUrl ?? '/guides/content-uploader-guide.html');

const isAdmin = computed(() => props.variant === 'admin');

const steps = computed(() => (isAdmin.value
    ? ['Set rates in matrix', 'Assign chapters to uploader', 'Review & publish when submitted']
    : ['Read assignment email', 'Agree to rate', 'Import MCQ JSON', 'Verify every question', 'Submit for publish']));
</script>

<template>
    <section class="rounded-xl border border-teal-200 bg-gradient-to-br from-teal-50 to-cyan-50 p-4 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <p class="text-xs font-bold uppercase tracking-wide text-teal-800">
                    {{ isAdmin ? 'Admin' : 'Content uploader' }} · Chapter guide
                </p>
                <h3 class="mt-1 text-base font-semibold text-teal-950">
                    How to upload textbook chapters (step-by-step)
                </h3>
                <ol class="mt-2 list-decimal space-y-0.5 pl-4 text-sm text-teal-900">
                    <li v-for="step in steps" :key="step">{{ step }}</li>
                </ol>
            </div>
            <div class="flex shrink-0 flex-col gap-2">
                <a
                    :href="guideUrl"
                    target="_blank"
                    rel="noopener"
                    class="rounded-lg bg-teal-700 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-teal-800"
                >
                    Open screen guide →
                </a>
                <Link
                    v-if="isAdmin && route().has('admin.content-tasks.index')"
                    :href="route('admin.content-tasks.index')"
                    class="rounded-lg border border-teal-300 bg-white px-4 py-2 text-center text-sm font-medium text-teal-900 hover:bg-teal-50"
                >
                    Content tasks
                </Link>
                <Link
                    v-else-if="!isAdmin && route().has('content.tasks.index')"
                    :href="route('content.tasks.index')"
                    class="rounded-lg border border-teal-300 bg-white px-4 py-2 text-center text-sm font-medium text-teal-900 hover:bg-teal-50"
                >
                    My content tasks
                </Link>
            </div>
        </div>
    </section>
</template>
