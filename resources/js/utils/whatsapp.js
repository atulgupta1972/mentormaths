/** WhatsApp click-to-chat text param — keep URLs short to avoid broken links. */
const MAX_URL_TEXT_LENGTH = 1200;

/**
 * Format mobile for India WhatsApp (country code, no +).
 */
export function normalizeWhatsAppNumber(mobile) {
    if (!mobile) {
        return null;
    }

    const digits = String(mobile).replace(/\D/g, '');

    if (digits.length === 10) {
        return `91${digits}`;
    }

    if (digits.length === 12 && digits.startsWith('91')) {
        return digits;
    }

    if (digits.length >= 10) {
        return digits;
    }

    return null;
}

export function formatDisplayMobile(mobile) {
    const normalized = normalizeWhatsAppNumber(mobile);

    if (!normalized) {
        return mobile || '';
    }

    if (normalized.length === 12 && normalized.startsWith('91')) {
        return `+91 ${normalized.slice(2, 7)} ${normalized.slice(7)}`;
    }

    return `+${normalized}`;
}

export function truncateMessageForUrl(message, maxLength = MAX_URL_TEXT_LENGTH) {
    if (!message || message.length <= maxLength) {
        return message;
    }

    return `${message.slice(0, maxLength - 3).trimEnd()}...`;
}

/**
 * wa.me opens the phone app on mobile. On desktop it may open WhatsApp Desktop
 * or redirect to Web — avoids requiring an active web.whatsapp.com session first.
 */
export function buildWhatsAppUrl(mobile, message) {
    const number = normalizeWhatsAppNumber(mobile);

    if (!number) {
        return null;
    }

    const text = encodeURIComponent(truncateMessageForUrl(message));

    return `https://wa.me/${number}?text=${text}`;
}

export async function copyWhatsAppMessage(message) {
    if (!message) {
        return false;
    }

    try {
        await navigator.clipboard.writeText(message);

        return true;
    } catch {
        const el = document.createElement('textarea');
        el.value = message;
        el.style.position = 'fixed';
        el.style.left = '-9999px';
        document.body.appendChild(el);
        el.select();

        let ok = false;

        try {
            ok = document.execCommand('copy');
        } finally {
            document.body.removeChild(el);
        }

        return ok;
    }
}

export function openWhatsApp(mobile, message) {
    const url = buildWhatsAppUrl(mobile, message);

    if (!url) {
        return false;
    }

    window.open(url, '_blank', 'noopener,noreferrer');

    return true;
}

export function openWhatsAppBatch(contacts, message, delayMs = 600) {
    const valid = contacts.filter((c) => normalizeWhatsAppNumber(c.mobile));

    valid.forEach((contact, index) => {
        setTimeout(() => {
            openWhatsApp(contact.mobile, message);
        }, index * delayMs);
    });

    return valid.length;
}
