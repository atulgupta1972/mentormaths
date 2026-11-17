/**
 * Match import/JSON topic labels to syllabus topics.
 * Accepts LaTeX-ish ($a^2$) and Unicode (a²), and ignores trailing commas.
 */
export function normalizeTopicName(name) {
    let text = String(name ?? '').trim();
    if (!text) {
        return '';
    }

    text = text.replace(/\$([^$]*)\$/g, '$1');
    text = text.replace(/\^2/g, '²').replace(/\^3/g, '³');
    text = text.replace(/\$+/g, '');
    text = text.replace(/[,\s]+$/g, '');
    text = text.replace(/\s+/g, ' ').trim().toLowerCase();

    return text;
}

/** Student/admin-facing label without raw $a^2$ markup. */
export function displayTopicName(name) {
    let text = String(name ?? '').trim();
    if (!text) {
        return '';
    }

    text = text.replace(/\$([^$]*)\$/g, '$1');
    text = text.replace(/\^2/g, '²').replace(/\^3/g, '³');
    text = text.replace(/\$+/g, '');
    text = text.replace(/[,\s]+$/g, '');
    text = text.replace(/\s+/g, ' ').trim();

    return text;
}
