<?php

namespace App\Services;

use App\Models\BasicsFactStat;
use App\Models\Student;
use Illuminate\Support\Facades\Schema;

class BasicsDrillReportService
{
    /**
     * @return array<string, mixed>|null
     */
    public function summaryForStudent(Student $student): ?array
    {
        if (! Schema::hasTable('basics_fact_stats')) {
            return null;
        }

        $stats = BasicsFactStat::query()
            ->where('student_id', $student->id)
            ->get();

        if ($stats->isEmpty()) {
            return null;
        }

        $mastered = $stats
            ->filter(fn (BasicsFactStat $stat) => $stat->times_correct > 0 && ! $stat->needs_review)
            ->count();

        return [
            'facts_practised' => $stats->count(),
            'mastered_count' => $mastered,
            'needs_review_count' => $stats->where('needs_review', true)->count(),
            'total_failures' => (int) $stats->sum('times_failed'),
            'weak_facts' => $this->weakFacts($student, 10),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function weakFacts(Student $student, int $limit = 10): array
    {
        if (! Schema::hasTable('basics_fact_stats')) {
            return [];
        }

        return BasicsFactStat::query()
            ->where('student_id', $student->id)
            ->where(function ($query) {
                $query->where('needs_review', true)
                    ->orWhere('times_failed', '>', 0);
            })
            ->orderByDesc('needs_review')
            ->orderByDesc('times_failed')
            ->limit($limit)
            ->get()
            ->map(fn (BasicsFactStat $stat) => [
                'fact_type' => $stat->fact_type,
                'fact_key' => $stat->fact_key,
                'label' => $this->factLabel($stat),
                'times_failed' => $stat->times_failed,
                'needs_review' => $stat->needs_review,
                'times_correct' => $stat->times_correct,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function weakFactsForWeeklyEmail(Student $student, int $limit = 5): array
    {
        return $this->weakFacts($student, $limit);
    }

    private function factLabel(BasicsFactStat $stat): string
    {
        if (preg_match('/^(\d+)x(\d+)$/', $stat->fact_key, $matches)) {
            return "{$matches[1]} × {$matches[2]}";
        }

        if (preg_match('/^sq(\d+)$/', $stat->fact_key, $matches)) {
            return "{$matches[1]}²";
        }

        if (preg_match('/^cb(\d+)$/', $stat->fact_key, $matches)) {
            return "{$matches[1]}³";
        }

        return $stat->fact_key;
    }
}
