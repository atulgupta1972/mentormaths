function escapeHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

export function optionLetter(index) {
    return String.fromCharCode(65 + index);
}

/** Bold numeric parts in MCQ text (integers, decimals, simple fractions). */
export function formatMcqText(text) {
    if (!text) {
        return '';
    }

    // Split on numbers first, then escape each segment. Escaping before bolding
    // used to wrap digits inside entities like &#39; and break apostrophes in the UI.
    return String(text)
        .split(/(\d+(?:\.\d+)?(?:\/\d+)?)/g)
        .map((part, index) => {
            const escaped = escapeHtml(part);

            return index % 2 === 1 ? `<strong>${escaped}</strong>` : escaped;
        })
        .join('');
}
