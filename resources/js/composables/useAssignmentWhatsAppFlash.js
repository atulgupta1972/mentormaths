import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { openWhatsApp } from '@/utils/whatsapp';

const OPEN_DELAY_MS = 700;

export function useAssignmentWhatsAppFlash() {
    const page = usePage();

    const openNotifications = (notifications) => {
        if (!Array.isArray(notifications) || notifications.length === 0) {
            return;
        }

        notifications.forEach((notification, index) => {
            setTimeout(() => {
                openWhatsApp(notification.mobile, notification.message);
            }, index * OPEN_DELAY_MS);
        });
    };

    watch(
        () => page.props.flash?.whatsapp_notifications,
        (notifications) => openNotifications(notifications),
        { immediate: true },
    );
}
