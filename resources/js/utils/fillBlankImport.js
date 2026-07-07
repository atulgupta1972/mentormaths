const FORMATS = ['integer', 'decimal', 'fraction'];

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

    return null;
}

function normalizeItem(item, index) {
    const questionText = String(item.question ?? item.question_text ?? '').trim();
    const answerFormat = String(item.answer_format ?? item.format ?? 'integer').trim().toLowerCase();
    const correctAnswer = String(item.correct_answer ?? item.answer ?? '').trim();

    if (!questionText) {
        throw new Error(`Question ${index + 1} is missing question text.`);
    }

    if (!FORMATS.includes(answerFormat)) {
        throw new Error(`Question ${index + 1} must use answer_format integer, decimal, or fraction.`);
    }

    if (!correctAnswer) {
        throw new Error(`Question ${index + 1} is missing correct_answer.`);
    }

    return {
        question_text: questionText,
        topic_name: String(item.topic ?? item.topic_name ?? '').trim(),
        syllabus_topic_id: item.syllabus_topic_id ?? item.topic_id ?? null,
        answer_format: answerFormat,
        correct_answer: correctAnswer,
        decimal_places: item.decimal_places ?? null,
        explanation: String(item.explanation ?? '').trim(),
        method_hint: String(item.method_hint ?? item.hint ?? '').trim(),
        difficulty: String(item.difficulty ?? '').trim(),
    };
}

export function parseFillBlankJson(raw) {
    const cleaned = stripMarkdownFences(raw);
    const data = extractJsonObject(cleaned);

    if (!data) {
        throw new Error('Invalid JSON. Paste a {"questions": [...]} object from Cursor.');
    }

    const items = Array.isArray(data.questions) ? data.questions : (Array.isArray(data) ? data : []);

    if (!items.length) {
        throw new Error('No questions found in JSON.');
    }

    return items.map((item, index) => normalizeItem(item, index));
}

export function rowsFromImportData(importRows) {
    return importRows.map((row) => ({
        question_text: row.question_text || '',
        topic_name: row.topic_name || '',
        syllabus_topic_id: row.syllabus_topic_id || '',
        answer_format: row.answer_format || 'integer',
        correct_answer: row.correct_answer || '',
        decimal_places: row.decimal_places ?? null,
        explanation: row.explanation || '',
        method_hint: row.method_hint || '',
        difficulty: row.difficulty || '',
    }));
}

export function defaultFillBlankRow(topicName = '') {
    return {
        question_text: '',
        topic_name: topicName,
        syllabus_topic_id: '',
        answer_format: 'integer',
        correct_answer: '',
        decimal_places: null,
        explanation: '',
        method_hint: '',
        difficulty: 'Medium',
    };
}

export const fillBlankFormats = FORMATS;
