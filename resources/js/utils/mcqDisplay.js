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

    const escaped = escapeHtml(String(text));

    return escaped.replace(/(\d+(?:\.\d+)?(?:\/\d+)?)/g, '<strong>$1</strong>');
}
