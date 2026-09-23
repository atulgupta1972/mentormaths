<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import {
    hasAttemptImmersiveOk,
    isAttemptFullscreenActive,
    isAttemptImmersiveSatisfied,
    isBenignFullscreenExit,
    markAttemptImmersiveOk,
    requestAttemptFullscreen,
} from '@/utils/attemptFullscreen';
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    title: {
        type: String,
        default: 'Enter fullscreen to continue',
    },
    message: {
        type: String,
        default: 'Stay in fullscreen so only Mentor Maths is on screen. Do not switch tabs or open other apps — your teacher is informed of each leave.',
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
let readyOnce = hasAttemptImmersiveOk();

const syncState = () => {
    const satisfied = isAttemptImmersiveSatisfied();

    // Do not yank the fill-blank keyboard / re-prompt while the student is typing.
    if (!satisfied && readyOnce && isBenignFullscreenExit()) {
        needsFullscreen.value = false;
        emit('ready');

        return;
    }

    needsFullscreen.value = !satisfied;

    if (satisfied) {
        readyOnce = true;
        markAttemptImmersiveOk();
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

        readyOnce = true;
        syncState();
    } finally {
        entering.value = false;
    }
};

const onFocusIn = (event) => {
    const tag = event.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || event.target?.isContentEditable) {
        // Keep gate closed while typing fill-blanks (iPad keyboard exits API fullscreen).
        if (readyOnce || isAttemptFullscreenActive() || hasAttemptImmersiveOk()) {
            needsFullscreen.value = false;
            emit('ready');
        }
    }
};

onMounted(() => {
    syncState();
    document.addEventListener('fullscreenchange', syncState);
    document.addEventListener('webkitfullscreenchange', syncState);
    document.addEventListener('focusin', onFocusIn, true);

    if (props.autoEnter && !isAttemptImmersiveSatisfied()) {
        // Best-effort: succeeds when Start/Continue already opened fullscreen, or browser allows it.
        enterFullscreen();
    }
});

onUnmounted(() => {
    document.removeEventListener('fullscreenchange', syncState);
    document.removeEventListener('webkitfullscreenchange', syncState);
    document.removeEventListener('focusin', onFocusIn, true);
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
