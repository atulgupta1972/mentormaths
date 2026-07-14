export function scorePercent(score, max) {
    if (score == null || !max) {
        return null;
    }

    return Math.round((Number(score) / Number(max)) * 100);
}

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
