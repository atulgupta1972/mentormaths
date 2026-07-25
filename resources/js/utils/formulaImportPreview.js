/**
 * Parse Cursor formula/concept MCQ JSON into preview rows (no save).
 *
 * @param {string} raw
 * @param {Array<{id?: number, name: string}>} knownTopics
 * @returns {{ rows: Array<object>, error: string|null }}
 */
export function parseFormulaImportPreview(raw, knownTopics = []) {
    const text = String(raw || '').trim();
    if (!text) {
        return { rows: [], error: 'Paste JSON first.' };
    }

    let data;
    try {
        data = JSON.parse(text);
    } catch {
        return { rows: [], error: 'Invalid JSON — paste the full JSON object from Cursor.' };
    }

    const items = Array.isArray(data?.questions)
        ? data.questions
        : (Array.isArray(data) ? data : null);

    if (!items?.length) {
        return { rows: [], error: 'No questions found. Expect { "questions": [ ... ] }.' };
    }

    const topicNames = knownTopics.map((topic) => String(topic.name || '').trim().toLowerCase());

    const rows = [];
    items.forEach((item, index) => {
        if (!item || typeof item !== 'object') {
            return;
        }

        const question = String(item.question ?? item.question_text ?? '').trim();
        if (!question) {
            return;
        }

        let options = Array.isArray(item.options) ? item.options : [];
        options = options.map((opt) => {
            if (opt && typeof opt === 'object') {
                return String(opt.text ?? opt.option_text ?? opt.option ?? '').trim();
            }

            return String(opt ?? '').trim();
        }).filter(Boolean);

        let correctIndex = Number.isInteger(item.correct_index) ? item.correct_index : null;
        if (correctIndex === null && (item.correct_answer || item.correctAnswer)) {
            const letter = String(item.correct_answer ?? item.correctAnswer).trim().toUpperCase();
            correctIndex = letter.charCodeAt(0) - 'A'.charCodeAt(0);
        }
        if (correctIndex === null || correctIndex < 0 || correctIndex >= options.length) {
            correctIndex = 0;
        }

        const topicName = String(item.topic ?? item.topic_name ?? '').trim();
        const topicMatched = topicName === ''
            || topicNames.includes(topicName.toLowerCase());

        rows.push({
            key: `${index}-${question.slice(0, 24)}`,
            topic: topicName,
            topic_matched: topicMatched,
            question,
            options,
            correct_index: correctIndex,
            explanation: String(item.explanation ?? '').trim(),
            difficulty: String(item.difficulty ?? '').trim(),
        });
    });

    if (!rows.length) {
        return { rows: [], error: 'Could not read any valid formula cards from the JSON.' };
    }

    return { rows, error: null };
}

/**
 * @param {Array<object>} rows
 * @returns {string}
 */
export function formulaPreviewRowsToJson(rows) {
    return JSON.stringify({
        questions: rows.map((row) => ({
            topic: row.topic || undefined,
            question: row.question,
            options: row.options,
            correct_index: row.correct_index,
            explanation: row.explanation || undefined,
            difficulty: row.difficulty || undefined,
        })),
    }, null, 2);
}
