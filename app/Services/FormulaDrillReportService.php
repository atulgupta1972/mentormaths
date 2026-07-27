<?php

namespace App\Services;

use App\Models\FormulaQuestionStat;
use App\Models\Student;
use App\Support\FormulaDrillSchema;
use Illuminate\Support\Collection;

class FormulaDrillReportService
{
    public function __construct(
        private FormulaDrillPoolService $poolService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function summaryForStudent(Student $student): ?array
    {
        if (! FormulaDrillSchema::isReady()) {
            return null;
        }

        $poolSize = $this->poolService->poolSize($student);

        $stats = FormulaQuestionStat::query()
            ->where('student_id', $student->id)
            ->with(['question:id,question_text,syllabus_topic_id'])
            ->get();

        $mastered = $stats->where('times_correct', '>', 0)->where('needs_review', false)->count();
        $needsReview = $stats->where('needs_review', true)->count();
        $totalFailures = (int) $stats->sum('total_failures');

        return [
            'pool_size' => $poolSize,
            'mastered_count' => $mastered,
            'needs_review_count' => $needsReview,
            'total_failures' => $totalFailures,
            'mastery_percent' => $poolSize > 0 ? (int) round(($mastered / $poolSize) * 100) : 0,
            'weak_formulas' => $this->weakFormulas($student, 10),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function weakFormulas(Student $student, int $limit = 10): array
    {
        if (! FormulaDrillSchema::isReady()) {
            return [];
        }

        return FormulaQuestionStat::query()
            ->where('student_id', $student->id)
            ->where(function ($query) {
                $query->where('needs_review', true)
                    ->orWhere('total_failures', '>', 0);
            })
            ->with(['question:id,question_text'])
            ->orderByDesc('needs_review')
            ->orderByDesc('total_failures')
            ->limit($limit)
            ->get()
            ->map(fn (FormulaQuestionStat $stat) => [
                'question_id' => $stat->question_id,
                'question_text' => $stat->question?->question_text,
                'total_failures' => $stat->total_failures,
                'needs_review' => $stat->needs_review,
                'times_correct' => $stat->times_correct,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function weakFormulasForWeeklyEmail(Student $student, int $limit = 5): array
    {
        return $this->weakFormulas($student, $limit);
    }
}
