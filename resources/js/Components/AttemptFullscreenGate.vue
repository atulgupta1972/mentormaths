<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { isAttemptFullscreenActive, requestAttemptFullscreen } from '@/utils/attemptFullscreen';
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    title: {
        type: String,
        default: 'Enter fullscreen to continue',
    },
    message: {
        type: String,
        default: 'Stay in fullscreen so only Mentor Maths is on screen. Leaving fullscreen, other tabs, or side panels (like Gemini) counts as a leave.',
    },
    /** Try to enter fullscreen as soon as this gate mounts (works when Start already began a gesture). */
    autoEnter: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['ready', 'lost']);

const needsFullscreen = ref(false);
const errorMessage = ref('');
const entering = ref(false);

const syncState = () => {
    const active = isAttemptFullscreenActive();
    needsFullscreen.value = !active;

    if (active) {
        errorMessage.value = '';
        emit('ready');
    } else {
        emit('lost');
    }
};

const enterFullscreen = async () => {
    if (entering.value) {
        return;
    }

    entering.value = true;
    errorMessage.value = '';

    try {
        const ok = await requestAttemptFullscreen();

        if (!ok) {
            errorMessage.value = 'Fullscreen was blocked. Allow it in your browser, then try again. Close side panels (Gemini / Copilot) first.';
            syncState();
            return;
        }

        syncState();
    } finally {
        entering.value = false;
    }
};

onMounted(() => {
    syncState();
    document.addEventListener('fullscreenchange', syncState);
    document.addEventListener('webkitfullscreenchange', syncState);

    if (props.autoEnter && !isAttemptFullscreenActive()) {
        // Best-effort: succeeds when Start/Continue already opened fullscreen, or browser allows it.
        enterFullscreen();
    }
});

onUnmounted(() => {
    document.removeEventListener('fullscreenchange', syncState);
    document.removeEventListener('webkitfullscreenchange', syncState);
});
</script>

<template>
    <div
        v-if="needsFullscreen"
        class="fixed inset-0 z-[120] flex cursor-pointer items-center justify-center bg-slate-950/95 p-6 text-center text-white"
        role="dialog"
        aria-modal="true"
        @click="enterFullscreen"
    >
        <div class="max-w-md space-y-4" @click.stop>
            <p class="text-lg font-semibold">{{ title }}</p>
            <p class="text-sm text-white/85">{{ message }}</p>
            <p class="text-xs text-white/60">Tap anywhere or the button below — fullscreen starts with your click.</p>
            <p v-if="errorMessage" class="text-sm text-rose-200">{{ errorMessage }}</p>
            <PrimaryButton
                type="button"
                class="!bg-white !text-slate-900 hover:!bg-slate-100"
                :disabled="entering"
                @click="enterFullscreen"
            >
                {{ entering ? 'Opening…' : 'Enter fullscreen' }}
            </PrimaryButton>
        </div>
    </div>
</template>
