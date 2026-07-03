/**
 * Format mobile for India WhatsApp (wa.me needs country code, no +).
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

export function buildWhatsAppUrl(mobile, message) {
    const number = normalizeWhatsAppNumber(mobile);

    if (!number) {
        return null;
    }

    return `https://wa.me/${number}?text=${encodeURIComponent(message)}`;
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
