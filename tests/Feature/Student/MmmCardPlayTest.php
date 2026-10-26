<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ChapterHead;
use App\Models\GradeLevel;
use App\Models\MmmCard;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardPlayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureFirstTermMarksEntered::class,
            \App\Http\Middleware\EnsureChapterTermsMapped::class,
            \App\Http\Middleware\EnsureFormulaDrillComplete::class,
            \App\Http\Middleware\EnsureMensurationMatchComplete::class,
            \App\Http\Middleware\EnsureBasicsDrillComplete::class,
        ]);
    }

    public function test_student_can_open_balance_equation_cards(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $chapter = $this->makeChapter();
        $this->enroll($user, $chapter);
        $gradeId = $chapter->syllabusVersion->grade_level_id;

        foreach ([
            'balance-the-equation',
            'balance-equation-basic-2',
            'balance-equation-basic-3',
            'balance-equation-basic-4',
            'balance-equation-basic-5',
            'balance-equation-basic-6',
            'bodmas-simple-brackets',
        ] as $slug) {
            $card = MmmCard::query()->where('slug', $slug)->firstOrFail();
            $card->gradeLevels()->syncWithoutDetaching([$gradeId]);

            $this->actingAs($user)
                ->get(route('student.mmm-cards.show', $card))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Student/MmmCards/Play')
                    ->where('card.slug', $slug)
                    ->where('is_staff_preview', false)
                    ->where('back_url', route('learn-methods.index')));
        }
    }

    public function test_student_cannot_open_a_method_outside_their_class(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $chapter = $this->makeChapter();
        $this->enroll($user, $chapter);
        $card = MmmCard::query()->where('slug', 'balance-equation-basic-3')->firstOrFail();

        $this->actingAs($user)
            ->get(route('student.mmm-cards.show', $card))
            ->assertNotFound();
    }

    public function test_learn_methods_lists_only_the_student_class(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $chapter = $this->makeChapter();
        $this->enroll($user, $chapter);

        $mine = MmmCard::query()->where('slug', 'balance-equation-basic-3')->firstOrFail();
        $head = ChapterHead::query()->create([
            'name' => 'Equations',
            'sort_order' => 2,
        ]);
        $mine->update([
            'chapter_head_id' => $head->id,
            'sub_heading' => 'Equation solving',
            'method_blurb' => 'Balance both sides.',
        ]);
        $mine->gradeLevels()->sync([$chapter->syllabusVersion->grade_level_id]);

        $otherGrade = GradeLevel::query()->create(['name' => 'Class 8', 'sort_order' => 8, 'is_active' => true]);
        $other = MmmCard::query()->where('slug', 'balance-equation-basic-4')->firstOrFail();
        $other->gradeLevels()->sync([$otherGrade->id]);

        $this->actingAs($user)
            ->get(route('learn-methods.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/MmmCards/Catalog')
                ->where('class_name', 'Class 7')
                ->where('show_classes', false)
                ->where('groups.0.head', 'Equations')
                ->where('groups.0.subs.0.name', 'Equation solving')
                ->where('groups.0.subs.0.methods.0.id', $mine->id)
                ->missing('groups.1'));

        $this->actingAs($user)
            ->get(route('student.mmm-cards.show', $mine))
            ->assertOk();
    }

    public function test_admin_can_classify_a_method_for_several_classes(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'balance-equation-basic-2')->firstOrFail();
        $head = ChapterHead::query()->create(['name' => 'Number System', 'sort_order' => 1]);
        $six = GradeLevel::query()->create(['name' => 'Class 6', 'sort_order' => 6, 'is_active' => true]);
        $seven = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);

        $this->actingAs($admin)
            ->put(route('admin.mmm-cards.classify', $card), [
                'chapter_head_id' => $head->id,
                'sub_heading' => 'Equation solving',
                'heading' => $card->heading,
                'method_blurb' => 'Move the number across.',
                'grade_level_ids' => [$six->id, $seven->id],
            ])
            ->assertRedirect();

        $card->refresh();
        $this->assertSame($head->id, (int) $card->chapter_head_id);
        $this->assertSame('Equation solving', $card->sub_heading);
        $this->assertSame('Move the number across.', $card->method_blurb);
        $this->assertEqualsCanonicalizing(
            [$six->id, $seven->id],
            $card->gradeLevels()->pluck('grade_levels.id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    private function makeChapter(): SyllabusChapter
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths', 'is_active' => true]);
        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
            'status' => SyllabusVersion::STATUS_PUBLISHED,
        ]);

        return SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'chapter_number' => 1,
            'name' => 'Integers',
            'sort_order' => 1,
        ]);
    }

    private function enroll(User $user, SyllabusChapter $chapter): void
    {
        $syllabus = $chapter->syllabusVersion;
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $syllabus->academic_year_id,
            'grade_level_id' => $syllabus->grade_level_id,
            'board_id' => $syllabus->board_id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);
    }
}
