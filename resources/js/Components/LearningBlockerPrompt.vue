<script setup>
import Modal from '@/Components/Modal.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const page = usePage();
const show = ref(false);

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
    if (blocker.value) {
        show.value = true;
    }
};

const onDocumentClick = (event) => {
    if (! isStudent.value || ! blocker.value) {
        return;
    }

    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    const target = event.target;
    if (! target || typeof target.closest !== 'function') {
        return;
    }

    if (target.closest('dialog')) {
        return;
    }

    const assignControl = target.closest('[data-learning-guard="assign"]');
    if (assignControl && blocker.value.blocks_assign) {
        event.preventDefault();
        event.stopPropagation();
        openPrompt();

        return;
    }

    const link = target.closest('a[href]');
    if (! link || ! isGatedLearnHref(link.getAttribute('href') || link.href)) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    openPrompt();
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

onMounted(() => document.addEventListener('click', onDocumentClick, true));
onUnmounted(() => document.removeEventListener('click', onDocumentClick, true));
</script>

<template>
    <Modal :show="show && Boolean(blocker)" max-width="md" @close="show = false">
        <div v-if="blocker" class="p-6">
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
                    @click="show = false"
                >
                    Not now
                </button>
                <button
                    v-if="alreadyHere"
                    type="button"
                    class="rounded-md bg-amber-700 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-800"
                    @click="show = false"
                >
                    {{ blocker.action_label }}
                </button>
                <Link
                    v-else
                    :href="blocker.action_url"
                    class="rounded-md bg-amber-700 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-800"
                    @click="show = false"
                >
                    {{ blocker.action_label }}
                </Link>
            </div>
        </div>
    </Modal>
</template>
