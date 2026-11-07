<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextbookStudentTryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_run_a_set_as_a_student_without_saving_a_score(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $chapter = $this->chapter($admin);

        $this->actingAs($admin)
            ->get(route('admin.textbooks.try-student', [
                'textbookChapter' => $chapter,
                'from' => 1,
                'to' => 1,
                'set' => 'C9-GM2-CH02-M1S1',
                'restart' => 1,
            ]))
            ->assertRedirect(route('admin.textbooks.try-student', [
                'textbookChapter' => $chapter,
                'from' => 1,
                'to' => 1,
                'set' => 'C9-GM2-CH02-M1S1',
            ]));

        $this->actingAs($admin)
            ->get(route('admin.textbooks.try-student', [
                'textbookChapter' => $chapter,
                'from' => 1,
                'to' => 1,
                'set' => 'C9-GM2-CH02-M1S1',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Textbooks/TryStudent')
                ->where('play.question.type', 'fill_blank')
                ->where('play.question.text', 'A convex quadrilateral has every internal angle less than ____ degrees.')
                ->where('play.feedback', null)
                ->where('play.total', 1)
                ->missing('play.question.correct_answer')
            );

        $this->actingAs($admin)
            ->post(route('admin.textbooks.try-student.answer', [
                'textbookChapter' => $chapter,
                'from' => 1,
                'to' => 1,
                'set' => 'C9-GM2-CH02-M1S1',
            ]), ['answer_text' => '90'])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.textbooks.try-student', [
                'textbookChapter' => $chapter,
                'from' => 1,
                'to' => 1,
                'set' => 'C9-GM2-CH02-M1S1',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('play.feedback.correct', false)
                ->where('play.feedback.expected', '180')
                ->where('play.score', 0)
            );

        $this->actingAs($admin)
            ->get(route('admin.textbooks.try-student', [
                'textbookChapter' => $chapter,
                'from' => 2,
                'to' => 2,
                'set' => 'C9-GM2-CH02-M1S1',
                'restart' => 1,
            ]));

        $this->actingAs($admin)
            ->post(route('admin.textbooks.try-student.answer', [
                'textbookChapter' => $chapter,
                'from' => 2,
                'to' => 2,
                'set' => 'C9-GM2-CH02-M1S1',
            ]), ['option_index' => 1])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.textbooks.try-student', [
                'textbookChapter' => $chapter,
                'from' => 2,
                'to' => 2,
                'set' => 'C9-GM2-CH02-M1S1',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('play.feedback.correct', true)
                ->where('play.score', 1)
            );

        $this->assertDatabaseCount('set_attempts', 0);
        $this->assertDatabaseCount('set_assignments', 0);
    }

    private function chapter(User $admin): TextbookChapter
    {
        $grade = GradeLevel::query()->create(['name' => 'Class 9', 'sort_order' => 9, 'is_active' => true]);
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics', 'is_active' => true]);
        $version = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'subject_id' => $subject->id,
            'name' => 'CBSE Class 9',
            'is_active' => true,
        ]);
        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'chapter_number' => 2,
            'name' => 'Quadrilaterals',
            'sort_order' => 2,
        ]);
        $textbook = Textbook::query()->create([
            'name' => 'Ganita Manjari 2',
            'code' => 'GM2',
            'grade_level_id' => $grade->id,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        return TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 2,
            'title' => 'Quadrilaterals',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
            'extraction_items' => [
                [
                    'id' => 'mcq-1',
                    'label' => 'Convex',
                    'question_type' => 'fill_blank',
                    'question_text' => 'A convex quadrilateral has every internal angle less than ____ degrees.',
                    'fill_blank_question_text' => 'A convex quadrilateral has every internal angle less than ____ degrees.',
                    'correct_answer' => '180',
                    'fill_blank_correct_answer' => '180',
                    'method_hint' => 'A dent is more than a straight angle.',
                    'explanation' => 'Every internal angle is less than 180 degrees.',
                    'mcq_options' => [],
                ],
                [
                    'id' => 'mcq-2',
                    'label' => 'Parallelogram',
                    'question_type' => 'mcq',
                    'question_text' => 'Equal opposite sides make the quadrilateral a ____.',
                    'correct_answer' => 'parallelogram',
                    'method_hint' => 'Use SSS.',
                    'explanation' => 'The converse says it is a parallelogram.',
                    'mcq_options' => [
                        ['text' => 'kite', 'is_correct' => false],
                        ['text' => 'parallelogram', 'is_correct' => true],
                    ],
                ],
            ],
        ]);
    }
}
