<?php

namespace App\Services;

use App\Models\BasicsDrillSetting;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;

class BasicsDrillSettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return config('basics_drill.defaults', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function forStudent(Student $student): array
    {
        $enrollment = $student->currentEnrollment();
        $gradeId = $enrollment?->grade_level_id;

        if (! $gradeId) {
            return $this->defaults();
        }

        return $this->forGradeLevelId($gradeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function forGradeLevelId(int $gradeLevelId): array
    {
        $defaults = $this->defaults();
        $row = BasicsDrillSetting::query()->where('grade_level_id', $gradeLevelId)->first();

        if (! $row) {
            return $defaults;
        }

        return [
            'tables_enabled' => $row->tables_enabled,
            'squares_enabled' => $row->squares_enabled,
            'cubes_enabled' => $row->cubes_enabled,
            'table_from' => $row->table_from,
            'table_to' => $row->table_to,
            'multiplier_from' => $row->multiplier_from,
            'multiplier_to' => $row->multiplier_to,
            'square_from' => $row->square_from,
            'square_to' => $row->square_to,
            'cube_from' => $row->cube_from,
            'cube_to' => $row->cube_to,
            'squares_per_day' => $row->squares_per_day,
            'cubes_per_day' => $row->cubes_per_day,
            'seconds_per_blank' => $row->seconds_per_blank,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertForGrade(GradeLevel $grade, array $data): BasicsDrillSetting
    {
        return BasicsDrillSetting::query()->updateOrCreate(
            ['grade_level_id' => $grade->id],
            [
                'tables_enabled' => (bool) ($data['tables_enabled'] ?? true),
                'squares_enabled' => (bool) ($data['squares_enabled'] ?? true),
                'cubes_enabled' => (bool) ($data['cubes_enabled'] ?? true),
                'table_from' => (int) ($data['table_from'] ?? 2),
                'table_to' => (int) ($data['table_to'] ?? 19),
                'multiplier_from' => (int) ($data['multiplier_from'] ?? 2),
                'multiplier_to' => (int) ($data['multiplier_to'] ?? 9),
                'square_from' => (int) ($data['square_from'] ?? 2),
                'square_to' => (int) ($data['square_to'] ?? 30),
                'cube_from' => (int) ($data['cube_from'] ?? 2),
                'cube_to' => (int) ($data['cube_to'] ?? 13),
                'squares_per_day' => (int) ($data['squares_per_day'] ?? 5),
                'cubes_per_day' => (int) ($data['cubes_per_day'] ?? 3),
                'seconds_per_blank' => (int) ($data['seconds_per_blank'] ?? 5),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminIndexRows(): array
    {
        $defaults = $this->defaults();

        return GradeLevel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (GradeLevel $grade) use ($defaults) {
                $settings = $this->forGradeLevelId($grade->id);
                $custom = BasicsDrillSetting::query()->where('grade_level_id', $grade->id)->exists();

                return [
                    'grade_level_id' => $grade->id,
                    'grade_name' => $grade->name,
                    'has_custom_settings' => $custom,
                    'settings' => $settings,
                    'defaults' => $defaults,
                ];
            })
            ->all();
    }

    public function isEnabledForEnrollment(?StudentEnrollment $enrollment): bool
    {
        if (! $enrollment) {
            return false;
        }

        $settings = $this->forGradeLevelId($enrollment->grade_level_id);

        return ($settings['tables_enabled'] ?? false)
            || ($settings['squares_enabled'] ?? false)
            || ($settings['cubes_enabled'] ?? false);
    }
}
