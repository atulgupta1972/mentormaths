export function scorePercent(score, max) {
    if (score == null || !max) {
        return null;
    }

    return Math.round((Number(score) / Number(max)) * 100);
}

/**
 * Performance label shown when a student finishes a practice set or test.
 *
 * @returns {{ label: string, percent: number|null, tone: 'rework'|'good'|'very_good'|'excellent' }|null}
 */
export function scorePerformanceGrade(score, max) {
    const percent = scorePercent(score, max);

    if (percent == null) {
        return null;
    }

    if (percent < 70) {
        return { label: 'Rework on mistakes', percent, tone: 'rework' };
    }

    if (percent < 80) {
        return { label: 'Good', percent, tone: 'good' };
    }

    if (percent < 90) {
        return { label: 'Very Good', percent, tone: 'very_good' };
    }

    return { label: 'Excellent', percent, tone: 'excellent' };
}

export const scorePerformanceGradeClasses = {
    rework: 'bg-amber-100 text-amber-900 ring-amber-200',
    good: 'bg-sky-100 text-sky-900 ring-sky-200',
    very_good: 'bg-emerald-100 text-emerald-900 ring-emerald-200',
    excellent: 'bg-violet-100 text-violet-900 ring-violet-200',
};

export function formatScoreLabel(score, max, { includeFraction = true } = {}) {
    const percent = scorePercent(score, max);

    if (percent == null) {
        return null;
    }

    if (includeFraction && max != null && score != null) {
        return `${percent}% (${score}/${max})`;
    }

    return `${percent}%`;
}

export function aggregateScoreLabel(rows) {
    let scoreTotal = 0;
    let maxTotal = 0;

    for (const row of rows ?? []) {
        const score = row.latest_score ?? row.score;
        const max = row.latest_max_score ?? row.max_score;

        if (score == null || !max) {
            continue;
        }

        scoreTotal += Number(score);
        maxTotal += Number(max);
    }

    return {
        scoreTotal,
        maxTotal,
        percent: scorePercent(scoreTotal, maxTotal),
        label: formatScoreLabel(scoreTotal, maxTotal),
    };
}
