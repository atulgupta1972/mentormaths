function escapeHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

const SUP_MARKER_START = '\uE000';
const SUP_MARKER_END = '\uE001';

function markExponents(text) {
    return String(text)
        .replace(/\^\(([^)]+)\)/g, (_, exp) => `${SUP_MARKER_START}${exp}${SUP_MARKER_END}`)
        .replace(/\^(-?\d+(?:\.\d+)?(?:\/\d+)?)/g, (_, exp) => `${SUP_MARKER_START}${exp}${SUP_MARKER_END}`)
        .replace(/\^([a-zA-Z][a-zA-Z0-9]*)/g, (_, exp) => `${SUP_MARKER_START}${exp}${SUP_MARKER_END}`);
}

function renderSupMarker(part) {
    const exp = part.slice(1, -1);

    return `<sup>${escapeHtml(exp)}</sup>`;
}

export function optionLetter(index) {
    return String.fromCharCode(65 + index);
}

/** Bold numeric parts and render ^exponents as HTML superscripts. */
export function formatMcqText(text) {
    if (!text) {
        return '';
    }

    const marked = markExponents(text);

    return marked
        .split(/(\uE000[^\uE001]+\uE001|\d+(?:\.\d+)?(?:\/\d+)?)/g)
        .map((part) => {
            if (part.startsWith(SUP_MARKER_START) && part.endsWith(SUP_MARKER_END)) {
                return renderSupMarker(part);
            }

            if (/^\d/.test(part)) {
                return `<strong>${escapeHtml(part)}</strong>`;
            }

            return escapeHtml(part);
        })
        .join('');
}
