import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const visible = ref(false);
const notifications = ref([]);

export function useAssignmentWhatsAppFlash() {
    const page = usePage();

    watch(
        () => page.props.flash?.whatsapp_notifications,
        (next) => {
            if (Array.isArray(next) && next.length > 0) {
                notifications.value = next;
                visible.value = true;
            }
        },
        { immediate: true },
    );

    const dismiss = () => {
        visible.value = false;
    };

    return {
        visible,
        notifications,
        dismiss,
    };
}
