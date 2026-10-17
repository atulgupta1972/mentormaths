<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\FormulaDrillSession;
use App\Models\GradeLevel;
use App\Models\MensurationMatchSetting;
use App\Models\Student;
use App\Models\StudentChapterCoverage;
use App\Models\StudentEnrollment;
use App\Models\StudentSeriesActivityDay;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Services\SeriesActivityService;
use App\Support\AlgebraCardPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesActivityRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array{user: User, student: Student, enrollment: StudentEnrollment}
     */
    private function seedClass7Student(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Rotate Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $yesterday = now()->subDay();
        $user->forceFill(['created_at' => $yesterday])->save();
        $student->forceFill(['created_at' => $yesterday])->save();

        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths', 'is_active' => true]);
        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
            'status' => SyllabusVersion::STATUS_PUBLISHED,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'chapter_number' => 1,
            'name' => 'Integers',
            'sort_order' => 1,
        ]);
        StudentChapterCoverage::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapter->id,
            'status' => StudentChapterCoverage::STATUS_STUDIED,
        ]);

        FormulaDrillSession::query()->create([
            'student_id' => $student->id,
            'drill_date' => now(config('formula_drill.timezone', 'Asia/Kolkata'))->startOfDay(),
            'status' => FormulaDrillSession::STATUS_COMPLETED,
            'questions_total' => 1,
            'questions_completed' => 1,
            'pool_size' => 1,
            'completed_at' => now(),
        ]);

        MensurationMatchSetting::query()->create([
            'grade_level_id' => $grade->id,
            'enabled' => true,
            'perimeter_area_enabled' => true,
            'volume_enabled' => false,
        ]);

        return compact('user', 'student', 'enrollment');
    }

    public function test_first_series_day_is_algebra_for_class_7(): void
    {
        if (! AlgebraCardPack::hasPlayableCards(AlgebraCardPack::FRACTION_SIMPLIFICATION)) {
            $this->markTestSkipped('Algebra card diagrams missing.');
        }

        ['user' => $user, 'student' => $student] = $this->seedClass7Student();
        $series = app(SeriesActivityService::class);

        $this->assertSame(StudentSeriesActivityDay::KIND_ALGEBRA, $series->todaysKind($student));

        $this->actingAs($user)
            ->get(route('student.series-practice.show'))
            ->assertRedirect(route('student.algebra-cards.show'));

        $this->actingAs($user)
            ->get(route('student.algebra-cards.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/AlgebraCards/Show')
                ->where('play.status', 'in_progress'));
    }

    public function test_after_algebra_day_next_day_is_mensuration(): void
    {
        if (! AlgebraCardPack::hasPlayableCards(AlgebraCardPack::FRACTION_SIMPLIFICATION)) {
            $this->markTestSkipped('Algebra card diagrams missing.');
        }

        ['user' => $user, 'student' => $student] = $this->seedClass7Student();

        StudentSeriesActivityDay::query()->create([
            'student_id' => $student->id,
            'activity_date' => now(config('formula_drill.timezone', 'Asia/Kolkata'))->subDay()->toDateString(),
            'kind' => StudentSeriesActivityDay::KIND_ALGEBRA,
            'pack_key' => AlgebraCardPack::FRACTION_SIMPLIFICATION,
            'completed_at' => now()->subDay(),
        ]);

        $series = app(SeriesActivityService::class);
        $this->assertSame(StudentSeriesActivityDay::KIND_MENSURATION, $series->todaysKind($student));

        $this->actingAs($user)
            ->get(route('student.series-practice.show'))
            ->assertRedirect(route('student.mensuration-match.show'));
    }

    public function test_gate_blocks_dashboard_until_algebra_pack_done(): void
    {
        if (! AlgebraCardPack::hasPlayableCards(AlgebraCardPack::FRACTION_SIMPLIFICATION)) {
            $this->markTestSkipped('Algebra card diagrams missing.');
        }

        ['user' => $user] = $this->seedClass7Student();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('student.algebra-cards.show'));
    }
}
