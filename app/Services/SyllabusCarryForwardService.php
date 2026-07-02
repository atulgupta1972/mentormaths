<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyllabusCarryForwardService
{
    public function carryForward(SyllabusVersion $source, AcademicYear $targetYear): SyllabusVersion
    {
        $exists = SyllabusVersion::query()
            ->where('board_id', $source->board_id)
            ->where('grade_level_id', $source->grade_level_id)
            ->where('subject_id', $source->subject_id)
            ->where('academic_year_id', $targetYear->id)
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException("Syllabus already exists for {$targetYear->name}.");
        }

        $source->load(['chapters.topics', 'academicYear']);

        return DB::transaction(function () use ($source, $targetYear) {
            $newVersion = SyllabusVersion::create([
                'board_id' => $source->board_id,
                'grade_level_id' => $source->grade_level_id,
                'subject_id' => $source->subject_id,
                'academic_year_id' => $targetYear->id,
                'status' => SyllabusVersion::STATUS_DRAFT,
                'notes' => "Carried forward from {$source->academicYear->name}",
                'copied_from_id' => $source->id,
            ]);

            foreach ($source->chapters as $chapter) {
                $newChapter = SyllabusChapter::create([
                    'syllabus_version_id' => $newVersion->id,
                    'chapter_number' => $chapter->chapter_number,
                    'name' => $chapter->name,
                    'sort_order' => $chapter->sort_order,
                ]);

                foreach ($chapter->topics as $topic) {
                    SyllabusTopic::create([
                        'syllabus_chapter_id' => $newChapter->id,
                        'name' => $topic->name,
                        'learning_outcomes' => $topic->learning_outcomes,
                        'difficulty' => $topic->difficulty,
                        'planned_periods' => $topic->planned_periods,
                        'remarks' => $topic->remarks,
                        'sort_order' => $topic->sort_order,
                    ]);
                }
            }

            return $newVersion;
        });
    }
}
