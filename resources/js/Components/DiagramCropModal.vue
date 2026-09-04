<script setup>
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    imageUrl: { type: String, default: '' },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'cropped']);

const stageRef = ref(null);
const imageRef = ref(null);
const naturalWidth = ref(0);
const naturalHeight = ref(0);
const displayWidth = ref(0);
const displayHeight = ref(0);
const loadError = ref('');
const applying = ref(false);

/** Crop box in display pixels relative to the image element. */
const crop = ref(null);
const dragMode = ref(null);
const dragStart = ref(null);
const cropAtDragStart = ref(null);

const MIN_SIZE = 24;

const hasCrop = computed(() => {
    const box = crop.value;

    return Boolean(box && box.width >= MIN_SIZE && box.height >= MIN_SIZE);
});

const scaleX = computed(() => (displayWidth.value > 0 ? naturalWidth.value / displayWidth.value : 1));
const scaleY = computed(() => (displayHeight.value > 0 ? naturalHeight.value / displayHeight.value : 1));

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

const measureImage = () => {
    const img = imageRef.value;
    if (!img) {
        return;
    }

    naturalWidth.value = img.naturalWidth || 0;
    naturalHeight.value = img.naturalHeight || 0;
    displayWidth.value = img.clientWidth || 0;
    displayHeight.value = img.clientHeight || 0;

    if (naturalWidth.value > 0 && displayWidth.value > 0) {
        // Default selection: middle band (typical for textbook page screenshots).
        const w = displayWidth.value * 0.86;
        const h = displayHeight.value * 0.34;
        crop.value = {
            x: (displayWidth.value - w) / 2,
            y: displayHeight.value * 0.55,
            width: w,
            height: h,
        };
    }
};

const resetCrop = () => {
    measureImage();
};

const close = () => {
    if (props.processing || applying.value) {
        return;
    }

    emit('close');
};

const pointerPos = (event) => {
    const img = imageRef.value;
    if (!img) {
        return { x: 0, y: 0 };
    }

    const rect = img.getBoundingClientRect();
    const clientX = event.touches?.[0]?.clientX ?? event.clientX;
    const clientY = event.touches?.[0]?.clientY ?? event.clientY;

    return {
        x: clamp(clientX - rect.left, 0, displayWidth.value),
        y: clamp(clientY - rect.top, 0, displayHeight.value),
    };
};

const startDrag = (mode, event) => {
    if (!crop.value && mode !== 'create') {
        return;
    }

    event.preventDefault();
    dragMode.value = mode;
    dragStart.value = pointerPos(event);
    cropAtDragStart.value = crop.value ? { ...crop.value } : null;

    if (mode === 'create') {
        const pos = dragStart.value;
        crop.value = { x: pos.x, y: pos.y, width: 0, height: 0 };
    }
};

const onPointerMove = (event) => {
    if (!dragMode.value || !dragStart.value) {
        return;
    }

    event.preventDefault();
    const pos = pointerPos(event);
    const start = dragStart.value;
    const base = cropAtDragStart.value;

    if (dragMode.value === 'create') {
        const x = Math.min(start.x, pos.x);
        const y = Math.min(start.y, pos.y);
        crop.value = {
            x,
            y,
            width: Math.abs(pos.x - start.x),
            height: Math.abs(pos.y - start.y),
        };

        return;
    }

    if (!base) {
        return;
    }

    if (dragMode.value === 'move') {
        const dx = pos.x - start.x;
        const dy = pos.y - start.y;
        crop.value = {
            ...base,
            x: clamp(base.x + dx, 0, displayWidth.value - base.width),
            y: clamp(base.y + dy, 0, displayHeight.value - base.height),
        };

        return;
    }

    let { x, y, width, height } = base;
    const right = base.x + base.width;
    const bottom = base.y + base.height;

    if (dragMode.value.includes('w')) {
        x = clamp(pos.x, 0, right - MIN_SIZE);
        width = right - x;
    }
    if (dragMode.value.includes('e')) {
        width = clamp(pos.x - base.x, MIN_SIZE, displayWidth.value - base.x);
    }
    if (dragMode.value.includes('n')) {
        y = clamp(pos.y, 0, bottom - MIN_SIZE);
        height = bottom - y;
    }
    if (dragMode.value.includes('s')) {
        height = clamp(pos.y - base.y, MIN_SIZE, displayHeight.value - base.y);
    }

    crop.value = { x, y, width, height };
};

const endDrag = () => {
    dragMode.value = null;
    dragStart.value = null;
    cropAtDragStart.value = null;

    if (crop.value && (crop.value.width < MIN_SIZE || crop.value.height < MIN_SIZE)) {
        crop.value = null;
    }
};

const applyCrop = async () => {
    if (!hasCrop.value || !imageRef.value || applying.value || props.processing) {
        return;
    }

    applying.value = true;
    loadError.value = '';

    try {
        const box = crop.value;
        const sx = Math.round(box.x * scaleX.value);
        const sy = Math.round(box.y * scaleY.value);
        const sw = Math.max(1, Math.round(box.width * scaleX.value));
        const sh = Math.max(1, Math.round(box.height * scaleY.value));

        const canvas = document.createElement('canvas');
        canvas.width = sw;
        canvas.height = sh;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            throw new Error('Could not crop this image in the browser.');
        }

        ctx.drawImage(imageRef.value, sx, sy, sw, sh, 0, 0, sw, sh);

        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob((result) => {
                if (!result) {
                    reject(new Error('Could not create the cropped image.'));

                    return;
                }

                resolve(result);
            }, 'image/png');
        });

        const file = new File([blob], `diagram-crop-${Date.now()}.png`, { type: 'image/png' });
        emit('cropped', file);
    } catch (error) {
        loadError.value = error?.message || 'Crop failed. Try Replace figure with a cropped image instead.';
    } finally {
        applying.value = false;
    }
};

watch(
    () => [props.show, props.imageUrl],
    async ([show]) => {
        loadError.value = '';
        crop.value = null;
        if (!show) {
            return;
        }

        await nextTick();
        // Wait a tick for modal layout before measuring.
        requestAnimationFrame(() => measureImage());
    },
);

onBeforeUnmount(() => {
    endDrag();
});
</script>

<template>
    <Modal :show="show" max-width="4xl" @close="close">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-900">Crop figure</h3>
            <p class="mt-1 text-sm text-slate-600">
                Drag on the page to select only the diagram. Move or resize the box, then apply.
            </p>
        </div>

        <div class="space-y-3 px-5 py-4">
            <p v-if="loadError" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900">
                {{ loadError }}
            </p>

            <div
                ref="stageRef"
                class="overflow-auto rounded-lg border border-slate-200 bg-slate-100 p-3 text-center"
                style="max-height: min(70vh, 640px);"
                @mousemove="onPointerMove"
                @mouseup="endDrag"
                @mouseleave="endDrag"
                @touchmove.prevent="onPointerMove"
                @touchend="endDrag"
            >
                <div class="relative inline-block max-w-full align-top">
                    <img
                        ref="imageRef"
                        :src="imageUrl"
                        alt="Figure to crop"
                        class="block max-h-[64vh] max-w-full select-none"
                        draggable="false"
                        @load="measureImage"
                        @error="loadError = 'Could not load this figure for cropping.'"
                        @mousedown="startDrag('create', $event)"
                        @touchstart="startDrag('create', $event)"
                    >

                    <div
                        v-if="crop && displayWidth"
                        class="pointer-events-none absolute border-2 border-indigo-500 bg-indigo-500/10"
                        :style="{
                            left: `${crop.x}px`,
                            top: `${crop.y}px`,
                            width: `${crop.width}px`,
                            height: `${crop.height}px`,
                        }"
                    >
                        <div
                            class="pointer-events-auto absolute inset-0 cursor-move"
                            @mousedown.stop="startDrag('move', $event)"
                            @touchstart.stop="startDrag('move', $event)"
                        />
                        <button
                            v-for="handle in ['nw', 'ne', 'sw', 'se', 'n', 's', 'e', 'w']"
                            :key="handle"
                            type="button"
                            class="pointer-events-auto absolute h-3 w-3 rounded-sm border border-white bg-indigo-600"
                            :class="{
                                'left-0 top-0 -translate-x-1/2 -translate-y-1/2 cursor-nwse-resize': handle === 'nw',
                                'right-0 top-0 translate-x-1/2 -translate-y-1/2 cursor-nesw-resize': handle === 'ne',
                                'bottom-0 left-0 -translate-x-1/2 translate-y-1/2 cursor-nesw-resize': handle === 'sw',
                                'bottom-0 right-0 translate-x-1/2 translate-y-1/2 cursor-nwse-resize': handle === 'se',
                                'left-1/2 top-0 -translate-x-1/2 -translate-y-1/2 cursor-ns-resize': handle === 'n',
                                'bottom-0 left-1/2 -translate-x-1/2 translate-y-1/2 cursor-ns-resize': handle === 's',
                                'right-0 top-1/2 translate-x-1/2 -translate-y-1/2 cursor-ew-resize': handle === 'e',
                                'left-0 top-1/2 -translate-x-1/2 -translate-y-1/2 cursor-ew-resize': handle === 'w',
                            }"
                            @mousedown.stop="startDrag(handle, $event)"
                            @touchstart.stop="startDrag(handle, $event)"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
            <SecondaryButton type="button" :disabled="processing || applying" @click="resetCrop">
                Reset box
            </SecondaryButton>
            <div class="flex flex-wrap gap-2">
                <SecondaryButton type="button" :disabled="processing || applying" @click="close">
                    Cancel
                </SecondaryButton>
                <PrimaryButton
                    type="button"
                    :disabled="!hasCrop || processing || applying"
                    @click="applyCrop"
                >
                    {{ processing || applying ? 'Saving…' : 'Apply crop' }}
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
