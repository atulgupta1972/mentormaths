/**
 * Copy text to clipboard. Works on HTTP (non-secure) via textarea + execCommand fallback.
 *
 * @param {string} text
 * @param {HTMLTextAreaElement|HTMLInputElement|null} [fallbackField]
 * @returns {Promise<{ok: boolean, message?: string}>}
 */
export async function copyTextToClipboard(text, fallbackField = null) {
    const value = String(text ?? '');

    if (!value) {
        return { ok: false, message: 'Nothing to copy.' };
    }

    try {
        if (navigator.clipboard?.writeText && window.isSecureContext) {
            await navigator.clipboard.writeText(value);

            return { ok: true };
        }
    } catch {
        // Fall through.
    }

    if (fallbackField) {
        try {
            fallbackField.focus();
            fallbackField.select();
            const ok = document.execCommand('copy');
            if (ok) {
                return { ok: true };
            }
        } catch {
            // Fall through.
        }
    }

    const ta = document.createElement('textarea');
    ta.value = value;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '0';
    ta.style.left = '0';
    ta.style.width = '1px';
    ta.style.height = '1px';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();

    try {
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if (ok) {
            return { ok: true };
        }
    } catch {
        document.body.removeChild(ta);
    }

    return {
        ok: false,
        message: 'Could not copy automatically. Select the prompt text and press Ctrl+C.',
    };
}
