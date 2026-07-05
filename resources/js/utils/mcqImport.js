function stripMarkdownFences(text) {
    let json = text.trim();

    const fenceMatch = json.match(/^```(?:json)?\s*([\s\S]*?)```\s*$/i);
    if (fenceMatch) {
        return fenceMatch[1].trim();
    }

    return json.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/i, '').trim();
}

function tryDecode(json) {
    try {
        const data = JSON.parse(json);
        return typeof data === 'object' && data !== null ? data : null;
    } catch {
        return null;
    }
}

function extractJsonObject(text) {
    const direct = tryDecode(text);
    if (direct) {
        return direct;
    }

    const match = text.match(/\{\s*"questions"\s*:\s*\[[\s\S]*\]\s*\}/);
    if (match) {
        const nested = tryDecode(match[0]);
        if (nested) {
            return nested;
        }
    }

    let trimmed = text.trim();
    while (trimmed.length > 2) {
        const attempt = tryDecode(trimmed);
        if (attempt) {
            return attempt;
        }
        if (trimmed.endsWith('}')) {
            trimmed = trimmed.slice(0, -1).trimEnd();
            continue;
        }
        break;
    }

    return null;
}

function normalizeItem(item, index) {
    const questionText = String(item.question ?? item.question_text ?? '').trim();
    let options = item.options ?? [];
    if (!Array.isArray(options)) {
        options = [];
    }

    let correctIndex = item.correct_index !== undefined ? Number(item.correct_index) : null;
    if (correctIndex === null && (item.correct_answer || item.correctAnswer)) {
        const letter = String(item.correct_answer ?? item.correctAnswer).trim().toUpperCase();
        correctIndex = letter.charCodeAt(0) - 65;
    }

    const normalizedOptions = [];
    for (let optIndex = 0; optIndex < options.length; optIndex += 1) {
        const option = options[optIndex];
        let text = '';
        let isCorrect = false;

        if (option && typeof option === 'object') {
            text = String(option.text ?? option.option ?? option.option_text ?? '').trim();
            isCorrect = Boolean(option.is_correct);
            if (!isCorrect && option.key && (item.correct_answer || item.correctAnswer)) {
                const answerKey = String(item.correct_answer ?? item.correctAnswer).trim().toUpperCase();
                isCorrect = String(option.key).trim().toUpperCase() === answerKey;
            }
        } else {
            text = String(option ?? '').trim();
            isCorrect = correctIndex === optIndex;
        }

        if (!text) {
            continue;
        }

        normalizedOptions.push({
            option_text: text,
            is_correct: isCorrect || correctIndex === optIndex,
            sort_order: normalizedOptions.length + 1,
        });
    }

    if (correctIndex !== null && normalizedOptions[correctIndex]) {
        normalizedOptions.forEach((opt, i) => {
            opt.is_correct = i === correctIndex;
        });
    }

    if (normalizedOptions.length && !normalizedOptions.some((opt) => opt.is_correct)) {
        normalizedOptions[0].is_correct = true;
    }

    if (!questionText || normalizedOptions.length < 2) {
        throw new Error(`Question ${index + 1} is incomplete (need question text and at least 2 options).`);
    }

    return {
        question_text: questionText,
        explanation: String(item.explanation ?? '').trim(),
        method_hint: String(item.method_hint ?? item.hint ?? '').trim(),
        difficulty: String(item.difficulty ?? '').trim(),
        options: normalizedOptions,
    };
}

export function parseMcqJson(raw) {
    const cleaned = stripMarkdownFences(raw);
    const data = extractJsonObject(cleaned);

    if (!data) {
        throw new Error('Invalid JSON. Paste a JSON object like {"questions": [...]} from Cursor.');
    }

    const items = Array.isArray(data.questions) ? data.questions : (Array.isArray(data) ? data : []);

    if (!items.length) {
        throw new Error('No questions found in JSON.');
    }

    const parsed = [];
    for (let i = 0; i < items.length; i += 1) {
        if (!items[i] || typeof items[i] !== 'object') {
            continue;
        }
        parsed.push(normalizeItem(items[i], i));
    }

    if (!parsed.length) {
        throw new Error('Could not parse any questions from JSON.');
    }

    return parsed;
}

export function rowsFromImportData(importRows) {
    return importRows.map((row) => ({
        question_text: row.question_text || '',
        explanation: row.explanation || '',
        method_hint: row.method_hint || '',
        difficulty: row.difficulty || '',
        diagram: null,
        diagramPreview: row.diagram_preview_url || null,
        options: (row.options?.length ? row.options : [
            { option_text: '', is_correct: true, sort_order: 1 },
            { option_text: '', is_correct: false, sort_order: 2 },
            { option_text: '', is_correct: false, sort_order: 3 },
            { option_text: '', is_correct: false, sort_order: 4 },
        ]).map((opt, i) => ({
            option_text: opt.option_text || opt.text || '',
            is_correct: Boolean(opt.is_correct),
            sort_order: i + 1,
        })),
    }));
}
