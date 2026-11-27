const FORMATS = ['integer', 'decimal', 'fraction', 'text'];

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

function resolveAnswerFormat(format, correctAnswer) {
    if (format === 'text') {
        return 'text';
    }

    if (/^(<=|>=|!=|[<=>≤≥≠])$/.test(correctAnswer)) {
        return 'text';
    }

    if (format === 'fraction' && !/^-?\d+(?:\.\d+)?(?:\s+\d+\s*\/\s*\d+|\s*\/\s*\d+)?$/.test(correctAnswer)) {
        return 'text';
    }

    return format;
}

function normalizeItem(item, index) {
    const questionText = String(item.question ?? item.question_text ?? '').trim();
    const answerFormat = String(item.answer_format ?? item.format ?? 'integer').trim().toLowerCase();
    const correctAnswer = String(item.correct_answer ?? item.answer ?? '').trim();

    if (!questionText) {
        throw new Error(`Question ${index + 1} is missing question text.`);
    }

    if (!FORMATS.includes(answerFormat)) {
        throw new Error(`Question ${index + 1} must use answer_format integer, decimal, fraction, or text.`);
    }

    if (!correctAnswer) {
        throw new Error(`Question ${index + 1} is missing correct_answer.`);
    }

    const resolvedFormat = resolveAnswerFormat(answerFormat, correctAnswer);

    return {
        question_text: questionText,
        topic_name: String(item.topic ?? item.topic_name ?? '').trim(),
        syllabus_topic_id: item.syllabus_topic_id ?? item.topic_id ?? null,
        answer_format: resolvedFormat,
        correct_answer: correctAnswer,
        decimal_places: item.decimal_places ?? null,
        explanation: String(item.explanation ?? '').trim(),
        method_hint: String(item.method_hint ?? item.hint ?? '').trim(),
        difficulty: String(item.difficulty ?? '').trim(),
        needs_diagram: Boolean(item.needs_diagram ?? item.with_figure ?? false),
        source_label: String(item.source_label ?? '').trim(),
    };
}

function isMcqItem(item) {
    const type = String(item.type ?? item.kind ?? '').trim().toLowerCase();

    if (type === 'mcq' || type === 'multiple_choice') {
        return true;
    }

    if (type === 'fill_in_blank' || type === 'fill_blank' || type === 'fill-in-blank') {
        return false;
    }

    return Array.isArray(item.options) && item.options.length >= 2;
}

function normalizeMcqItem(item, index) {
    const questionText = String(item.question ?? item.question_text ?? '').trim();

    if (!questionText) {
        throw new Error(`Question ${index + 1} is missing question text.`);
    }

    const rawOptions = Array.isArray(item.options) ? item.options : [];
    let correctIndex = Number.isInteger(item.correct_index) ? item.correct_index : null;

    if (correctIndex === null && item.correct_answer) {
        const letter = String(item.correct_answer).trim().toUpperCase();
        if (/^[A-D]$/.test(letter)) {
            correctIndex = letter.charCodeAt(0) - 65;
        }
    }

    const options = rawOptions.map((option, optIndex) => {
        const text = typeof option === 'string'
            ? option
            : String(option?.text ?? option?.option ?? option?.option_text ?? '');

        return {
            option_text: text.trim(),
            is_correct: correctIndex === null
                ? Boolean(option?.is_correct)
                : optIndex === correctIndex,
        };
    }).filter((option) => option.option_text);

    if (options.length < 2) {
        throw new Error(`Question ${index + 1} is an MCQ and needs at least 2 options.`);
    }

    if (!options.some((option) => option.is_correct)) {
        options[0].is_correct = true;
    }

    return {
        kind: 'mcq',
        question_text: questionText,
        topic_name: String(item.topic ?? item.topic_name ?? '').trim(),
        syllabus_topic_id: item.syllabus_topic_id ?? item.topic_id ?? null,
        answer_format: 'integer',
        correct_answer: '',
        decimal_places: null,
        explanation: String(item.explanation ?? '').trim(),
        method_hint: String(item.method_hint ?? item.hint ?? '').trim(),
        difficulty: String(item.difficulty ?? '').trim(),
        needs_diagram: Boolean(item.needs_diagram ?? false),
        source_label: String(item.source_label ?? '').trim(),
        options,
    };
}

export function parseQuestionPack(raw) {
    const cleaned = stripMarkdownFences(raw);
    const data = extractJsonObject(cleaned);

    if (!data) {
        throw new Error('Invalid JSON. Paste a {"questions": [...]} object from Cursor.');
    }

    const items = Array.isArray(data.questions) ? data.questions : (Array.isArray(data) ? data : []);

    if (!items.length) {
        throw new Error('No questions found in JSON.');
    }

    return items.map((item, index) => (
        isMcqItem(item)
            ? normalizeMcqItem(item, index)
            : { ...normalizeItem(item, index), kind: 'fill_in_blank', options: [] }
    ));
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
        needs_diagram: Boolean(row.needs_diagram),
        kind: row.kind === 'mcq' ? 'mcq' : 'fill_in_blank',
        source_label: row.source_label || '',
        options: Array.isArray(row.options) && row.options.length
            ? row.options.map((option) => ({
                option_text: option.option_text || '',
                is_correct: Boolean(option.is_correct),
            }))
            : [
                { option_text: '', is_correct: true },
                { option_text: '', is_correct: false },
                { option_text: '', is_correct: false },
                { option_text: '', is_correct: false },
            ],
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
        needs_diagram: false,
        kind: 'fill_in_blank',
        source_label: '',
        options: [
            { option_text: '', is_correct: true },
            { option_text: '', is_correct: false },
            { option_text: '', is_correct: false },
            { option_text: '', is_correct: false },
        ],
    };
}

export const fillBlankFormats = FORMATS;
