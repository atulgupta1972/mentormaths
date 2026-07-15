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
        default: 'Chapter tests run in fullscreen. This helps you focus and reduces copying questions elsewhere.',
    },
});

const emit = defineEmits(['ready']);

const needsFullscreen = ref(false);
const errorMessage = ref('');

const syncState = () => {
    needsFullscreen.value = !isAttemptFullscreenActive();

    if (!needsFullscreen.value) {
        emit('ready');
    }
};

const enterFullscreen = async () => {
    errorMessage.value = '';
    const ok = await requestAttemptFullscreen();

    if (!ok) {
        errorMessage.value = 'Fullscreen was blocked. Allow it in your browser, or use a larger screen.';
        return;
    }

    syncState();
};

onMounted(() => {
    syncState();
    document.addEventListener('fullscreenchange', syncState);
});

onUnmounted(() => {
    document.removeEventListener('fullscreenchange', syncState);
});
</script>

<template>
    <div
        v-if="needsFullscreen"
        class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/95 p-6 text-center text-white"
    >
        <div class="max-w-md space-y-4">
            <p class="text-lg font-semibold">{{ title }}</p>
            <p class="text-sm text-white/85">{{ message }}</p>
            <p v-if="errorMessage" class="text-sm text-rose-200">{{ errorMessage }}</p>
            <PrimaryButton type="button" class="!bg-white !text-slate-900 hover:!bg-slate-100" @click="enterFullscreen">
                Enter fullscreen
            </PrimaryButton>
        </div>
    </div>
</template>
