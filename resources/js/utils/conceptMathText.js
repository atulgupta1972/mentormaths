/**
 * Split concept-path prose into plain text + stacked-fraction tokens.
 * Supports: "4 1/10", "1/10", "4\frac{1}{10}", "\frac{1}{10}".
 *
 * @param {string|null|undefined} text
 * @returns {list<{type: 'text', value: string}|{type: 'fraction', whole: string|null, num: string, den: string}>}
 */
export function tokenizeConceptMath(text) {
    const src = String(text ?? '');
    if (src === '') {
        return [];
    }

    const pattern = /(\d+)\s*\\frac\s*\{\s*(\d+)\s*\}\s*\{\s*(\d+)\s*\}|\\frac\s*\{\s*(\d+)\s*\}\s*\{\s*(\d+)\s*\}|(\d+)\s+(\d+)\s*\/\s*(\d+)|(?<![\d.])(\d+)\s*\/\s*(\d+)(?![\d.])/g;
    const tokens = [];
    let last = 0;
    let match;

    while ((match = pattern.exec(src)) !== null) {
        if (match.index > last) {
            tokens.push({ type: 'text', value: src.slice(last, match.index) });
        }

        if (match[1] !== undefined) {
            tokens.push({ type: 'fraction', whole: match[1], num: match[2], den: match[3] });
        } else if (match[4] !== undefined) {
            tokens.push({ type: 'fraction', whole: null, num: match[4], den: match[5] });
        } else if (match[6] !== undefined) {
            tokens.push({ type: 'fraction', whole: match[6], num: match[7], den: match[8] });
        } else {
            tokens.push({ type: 'fraction', whole: null, num: match[9], den: match[10] });
        }

        last = match.index + match[0].length;
    }

    if (last < src.length) {
        tokens.push({ type: 'text', value: src.slice(last) });
    }

    return tokens;
}
