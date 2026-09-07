/**
 * Concept-path fill-blank / fill-degrees answer matching.
 * Accepts common turn wording so Class 5 isn't stuck on "half" vs "1/2" vs "full".
 */

export function normalizeConceptAnswer(value) {
    let s = String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/[’']/g, "'");

    s = s.replace(/\s+/g, ' ');
    s = s.replace(/\s*°\s*$/u, '');
    s = s.replace(/\s*degrees?\s*$/i, '');
    s = s.replace(/\s*turns?\s*$/i, '');
    s = s.trim();

    const synonyms = {
        half: '1/2',
        '1/2': '1/2',
        '½': '1/2',
        '0.5': '1/2',
        quarter: '1/4',
        '1/4': '1/4',
        '¼': '1/4',
        '0.25': '1/4',
        'three quarter': '3/4',
        'three-quarter': '3/4',
        'three quarters': '3/4',
        'three-quarters': '3/4',
        '3/4': '3/4',
        '¾': '3/4',
        '0.75': '3/4',
        full: '1',
        one: '1',
        complete: '1',
        whole: '1',
        '1': '1',
    };

    if (synonyms[s]) {
        return synonyms[s];
    }

    return s.replace(/\s+/g, '');
}

/**
 * @param {unknown} typed
 * @param {unknown} correct
 * @param {unknown} accepted
 */
export function conceptAnswersMatch(typed, correct, accepted = []) {
    const typedNorm = normalizeConceptAnswer(typed);
    if (typedNorm === '') {
        return false;
    }

    const list = [correct];
    if (Array.isArray(accepted)) {
        list.push(...accepted);
    } else if (typeof accepted === 'string' && accepted.trim() !== '') {
        list.push(...accepted.split(','));
    }

    return list.some((candidate) => {
        const c = normalizeConceptAnswer(candidate);
        return c !== '' && c === typedNorm;
    });
}

/**
 * @param {unknown} value
 */
export function acceptedAnswersToText(value) {
    if (Array.isArray(value)) {
        return value.map((v) => String(v).trim()).filter(Boolean).join(', ');
    }
    if (typeof value === 'string') {
        return value.trim();
    }
    return '';
}

/**
 * @param {string} text
 * @returns {string[]}
 */
export function parseAcceptedAnswersText(text) {
    return String(text || '')
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean)
        .slice(0, 12);
}
