<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const page = usePage();
const show = ref(false);
let openTimer = 0;

const blocker = computed(() => page.props.learningBlocker ?? null);

const isStudent = computed(() => Boolean(page.props.auth?.isStudent) && ! page.props.auth?.isAdmin);

const alreadyHere = computed(() => {
    const target = blocker.value?.action_url;
    if (! target || typeof window === 'undefined') {
        return false;
    }

    try {
        return new URL(target, window.location.origin).pathname === window.location.pathname;
    } catch {
        return false;
    }
});

const actionLabel = computed(() => {
    if (alreadyHere.value && blocker.value?.key === 'chapter_terms') {
        return 'Show the chapter to tag';
    }

    return blocker.value?.action_label || 'Continue';
});

const isGatedLearnHref = (href) => {
    if (! href || href.startsWith('#')) {
        return false;
    }

    try {
        const path = new URL(href, window.location.origin).pathname;

        return /\/student\/(concept-path|assignments|attempts|written-assignments)(\/|$)/.test(path);
    } catch {
        return false;
    }
};

const openPrompt = () => {
    if (! blocker.value) {
        return;
    }

    window.clearTimeout(openTimer);
    // Wait until the click that opened this has finished, or the overlay closes itself.
    openTimer = window.setTimeout(() => {
        show.value = true;
    }, 0);
};

const closePrompt = () => {
    window.clearTimeout(openTimer);
    show.value = false;
};

const stayAndFocus = () => {
    const key = blocker.value?.key;
    closePrompt();
    window.dispatchEvent(new CustomEvent('learning-blocker-focus', { detail: { key } }));
};

const onDocumentClick = (event) => {
    if (! isStudent.value || ! blocker.value || show.value) {
        return;
    }

    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    const target = event.target;
    if (! target || typeof target.closest !== 'function') {
        return;
    }

    const learnControl = target.closest('[data-learning-guard="learn"]');
    const assignControl = target.closest('[data-learning-guard="assign"]');
    const link = target.closest('a[href]');
    const blockedLearn = Boolean(learnControl) || (link && isGatedLearnHref(link.getAttribute('href') || link.href));
    const blockedAssign = Boolean(assignControl) && blocker.value.blocks_assign;

    if (! blockedLearn && ! blockedAssign) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    openPrompt();
};

const onKeydown = (event) => {
    if (event.key === 'Escape' && show.value) {
        closePrompt();
    }
};

watch(
    () => page.props.flash?.learning_blocker_prompt,
    (prompt) => {
        if (prompt) {
            openPrompt();
        }
    },
    { immediate: true },
);

onMounted(() => {
    document.addEventListener('click', onDocumentClick, true);
    document.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    window.clearTimeout(openTimer);
    document.removeEventListener('click', onDocumentClick, true);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div
        v-if="show && blocker"
        class="fixed inset-0 z-[80] flex items-end justify-center bg-slate-900/50 px-4 py-6 sm:items-center"
        role="dialog"
        aria-modal="true"
        :aria-label="blocker.title"
        @click.self="closePrompt"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl" @click.stop>
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">
                Before concepts and tests
            </p>
            <h2 class="mt-1 text-lg font-semibold text-gray-900">
                {{ blocker.title }}
            </h2>
            <p class="mt-2 text-sm leading-relaxed text-gray-700">
                {{ blocker.message }}
            </p>
            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    @click="closePrompt"
                >
                    Not now
                </button>
                <button
                    v-if="alreadyHere"
                    type="button"
                    class="rounded-md bg-amber-700 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-800"
                    @click="stayAndFocus"
                >
                    {{ actionLabel }}
                </button>
                <Link
                    v-else
                    :href="blocker.action_url"
                    class="rounded-md bg-amber-700 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-800"
                    @click="closePrompt"
                >
                    {{ actionLabel }}
                </Link>
            </div>
        </div>
    </div>
</template>
