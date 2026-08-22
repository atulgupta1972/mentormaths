<script setup>
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    /** POST route URL */
    action: { type: String, required: true },
    /** Extra form fields (e.g. question_id for batch) */
    fields: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    /** When true, caller handles confirm via emit — unused; we use in-page modal */
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['reported']);

const showConfirm = ref(false);
const form = useForm({ ...props.fields });

const openConfirm = () => {
    if (props.disabled || form.processing) {
        return;
    }
    showConfirm.value = true;
};

const cancel = () => {
    showConfirm.value = false;
};

const confirm = () => {
    Object.assign(form, props.fields);
    form.post(props.action, {
        preserveScroll: true,
        onSuccess: () => {
            showConfirm.value = false;
            emit('reported');
        },
    });
};

const buttonLabel = computed(() => (
    form.processing ? 'Sending…' : 'Question seems misprint / incomplete'
));
</script>

<template>
    <div>
        <SecondaryButton
            type="button"
            class="!border-amber-300 !text-amber-900 hover:!bg-amber-50"
            :disabled="disabled || form.processing"
            @click="openConfirm"
        >
            {{ buttonLabel }}
        </SecondaryButton>

        <Modal :show="showConfirm" max-width="md" @close="cancel">
            <div class="p-5">
                <h3 class="text-lg font-semibold text-gray-900">Report misprint or incomplete sum?</h3>
                <p class="mt-2 text-sm text-gray-700">
                    This goes to your teacher to fix. No marks will be lost. After it is corrected, it comes back for you to attempt again.
                </p>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <SecondaryButton type="button" :disabled="form.processing" @click="cancel">
                        Cancel
                    </SecondaryButton>
                    <SecondaryButton
                        type="button"
                        class="!border-amber-400 !bg-amber-100 !text-amber-950"
                        :disabled="form.processing"
                        @click="confirm"
                    >
                        {{ form.processing ? 'Sending…' : 'Yes, report this sum' }}
                    </SecondaryButton>
                </div>
            </div>
        </Modal>
    </div>
</template>
