<?php

namespace App\Services;

use App\Models\GradeLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AdminGradeContext
{
    public const SESSION_KEY = 'admin_grade_level_id';

    /** Classes offered at Maths Foundation (middle + secondary). */
    public const CLASS_SORT_ORDERS = [6, 7, 8, 9, 10];

    public function classLevels()
    {
        return GradeLevel::query()
            ->where('is_active', true)
            ->whereIn('sort_order', self::CLASS_SORT_ORDERS)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'sort_order']);
    }

    public function resolve(Request $request): ?GradeLevel
    {
        if ($request->filled('grade_level_id')) {
            $this->persist($request, $request->integer('grade_level_id') ?: null);
        }

        $id = $request->session()->get(self::SESSION_KEY);

        if (! $id) {
            return null;
        }

        $grade = GradeLevel::find($id);

        if (! $grade || ! in_array($grade->sort_order, self::CLASS_SORT_ORDERS, true)) {
            return null;
        }

        return $grade;
    }

    public function persist(Request $request, ?int $gradeLevelId): void
    {
        if ($gradeLevelId) {
            $grade = GradeLevel::find($gradeLevelId);

            if ($grade && in_array($grade->sort_order, self::CLASS_SORT_ORDERS, true)) {
                $request->session()->put(self::SESSION_KEY, $gradeLevelId);

                return;
            }
        }

        $request->session()->forget(self::SESSION_KEY);
    }

    public function sharedPayload(Request $request): array
    {
        $selected = $this->resolve($request);

        return [
            'levels' => $this->classLevels(),
            'selected' => $selected?->only(['id', 'name', 'sort_order']),
        ];
    }

    public function scopeTopics(Builder $query, ?int $gradeLevelId): Builder
    {
        if (! $gradeLevelId) {
            return $query;
        }

        return $query->whereHas(
            'chapter.syllabusVersion',
            fn (Builder $q) => $q->where('grade_level_id', $gradeLevelId),
        );
    }

    public function scopeQuestions(Builder $query, ?int $gradeLevelId): Builder
    {
        if (! $gradeLevelId) {
            return $query;
        }

        return $query->whereHas(
            'topic.chapter.syllabusVersion',
            fn (Builder $q) => $q->where('grade_level_id', $gradeLevelId),
        );
    }

    public function scopePracticeSets(Builder $query, ?int $gradeLevelId): Builder
    {
        if (! $gradeLevelId) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($gradeLevelId) {
            $q->whereHas(
                'topic.chapter.syllabusVersion',
                fn (Builder $inner) => $inner->where('grade_level_id', $gradeLevelId),
            )->orWhereHas(
                'chapter.syllabusVersion',
                fn (Builder $inner) => $inner->where('grade_level_id', $gradeLevelId),
            );
        });
    }
}
