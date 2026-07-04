export function questionHubClassUrl(gradeLevelId, boardId) {
    if (!gradeLevelId || !boardId) {
        return route('admin.questions.index');
    }

    return `${route('admin.questions.classes.show', gradeLevelId)}?board_id=${boardId}`;
}

export function questionHubChapterUrl(chapterId) {
    if (!chapterId) {
        return route('admin.questions.index');
    }

    return route('admin.questions.chapters.show', chapterId);
}
