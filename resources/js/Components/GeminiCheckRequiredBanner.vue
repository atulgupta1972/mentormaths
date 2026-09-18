<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    pendingCount: { type: Number, default: 0 },
    pendingTasks: { type: Array, default: () => [] },
    compact: { type: Boolean, default: false },
    showFlash: { type: Boolean, default: true },
});

const page = usePage();
const flashError = computed(() => page.props.flash?.error || '');

const chapterLabel = (task) => {
    if (task.chapter_label) {
        return task.chapter_label;
    }

    const chapter = task.chapter;
    if (!chapter) {
        return 'Chapter';
    }

    const parts = [
        chapter.grade_name,
        chapter.textbook_name,
        chapter.chapter_number ? `Ch ${chapter.chapter_number}` : null,
        chapter.title,
    ].filter(Boolean);

    return parts.join(' · ') || 'Chapter';
};

const count = computed(() => Number(props.pendingCount || props.pendingTasks.length || 0));
const heading = computed(() => (
    count.value === 1
        ? 'Finish Gemini check on 1 uploaded chapter before any new upload'
        : `Finish Gemini check on ${count.value} uploaded chapters before any new upload`
));
</script>

<template>
    <div
        v-if="count > 0 || flashError"
        class="space-y-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
        :class="compact ? 'px-3 py-2' : 'px-4 py-3'"
    >
        <div v-if="showFlash && flashError" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900">
            {{ flashError }}
        </div>
        <p class="font-semibold">{{ heading }}</p>
        <p>
            New questions, PDF uploads, and chapter imports stay locked until Gemini is done on every highlighted chapter.
        </p>
        <ul v-if="pendingTasks.length" class="space-y-1">
            <li
                v-for="task in pendingTasks"
                :key="task.id"
                class="flex flex-wrap items-center justify-between gap-2"
            >
                <span>
                    {{ chapterLabel(task) }}
                    <span
                        v-if="task.gemini_progress?.can_gemini"
                        class="ml-1 inline-flex rounded-full bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-900"
                    >
                        {{ task.gemini_progress.verified }}/{{ task.gemini_progress.total }}
                    </span>
                </span>
                <Link
                    :href="route('content.tasks.show', task.id)"
                    class="font-semibold text-indigo-700 hover:underline"
                >
                    Complete Gemini check →
                </Link>
            </li>
        </ul>
        <p v-else>
            Open
            <Link :href="route('content.tasks.index')" class="font-semibold text-indigo-700 hover:underline">My content tasks</Link>
            and run Gemini on the pending chapters first.
        </p>
    </div>
</template>
