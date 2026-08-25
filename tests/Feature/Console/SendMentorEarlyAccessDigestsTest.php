<?php

namespace Tests\Feature\Console;

use App\Mail\MentorEarlyAccessDigest;
use App\Models\AcademicYear;
use App\Models\AccessCode;
use App\Models\Board;
use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Student;
use App\Models\StudentChapterCoverage;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Services\UserGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendMentorEarlyAccessDigestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        foreach (['admin', 'teacher', 'mentor', 'student'] as $code) {
            Group::query()->firstOrCreate(
                ['code' => $code],
                ['name' => ucfirst($code), 'is_active' => true],
            );
        }

        config(['mentor_digest.enabled' => true, 'mentor_digest.active_tcode_only' => true]);
    }

    public function test_sends_enrolment_nudge_when_mentor_has_no_students(): void
    {
        Mail::fake();

        $mentor = $this->makeMentorWithTcode('empty.mentor@example.com');

        $this->artisan('mentors:send-early-access-digests')
            ->assertSuccessful();

        Mail::assertSent(MentorEarlyAccessDigest::class, function (MentorEarlyAccessDigest $mail) use ($mentor) {
            return $mail->hasTo($mentor->email)
                && $mail->payload['has_students'] === false
                && $mail->payload['stats']['total'] === 0;
        });
    }

    public function test_sends_student_roster_with_study_plan_flags(): void
    {
        Mail::fake();

        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $grade = GradeLevel::query()->create([
            'name' => 'Class 7',
            'sort_order' => 7,
            'is_active' => true,
        ]);
        $board = Board::query()->create([
            'code' => 'CBSE',
            'name' => 'CBSE',
            'is_active' => true,
        ]);

        $mentor = $this->makeMentorWithTcode('roster.mentor@example.com');
        $class = CoachingClass::query()->create(['name' => 'Sunrise Tuition', 'is_active' => true]);
        $teacher = CoachingClassTeacher::query()->create([
            'coaching_class_id' => $class->id,
            'name' => 'Mentor',
            'mobile' => '9876500099',
            'user_id' => $mentor->id,
            'is_active' => true,
        ]);

        $withPlan = Student::query()->create([
            'name' => 'Asha With Plan',
            'parent1_name' => 'P',
            'parent1_mobile' => '9876500101',
            'school_name' => 'S',
            'enrollment_source' => 'coaching',
            'coaching_class_id' => $class->id,
            'coaching_class_teacher_id' => $teacher->id,
            'notify_parent1_mobile' => true,
            'notify_parent2_mobile' => false,
            'notify_student_mobile' => false,
        ]);
        $withoutPlan = Student::query()->create([
            'name' => 'Bhav Without Plan',
            'parent1_name' => 'P',
            'parent1_mobile' => '9876500102',
            'school_name' => 'S',
            'enrollment_source' => 'coaching',
            'coaching_class_id' => $class->id,
            'coaching_class_teacher_id' => $teacher->id,
            'notify_parent1_mobile' => true,
            'notify_parent2_mobile' => false,
            'notify_student_mobile' => false,
        ]);

        $enrollmentWithPlan = StudentEnrollment::query()->create([
            'student_id' => $withPlan->id,
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
            'enrollment_source' => 'coaching',
            'coaching_class_id' => $class->id,
        ]);
        StudentEnrollment::query()->create([
            'student_id' => $withoutPlan->id,
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
            'enrollment_source' => 'coaching',
            'coaching_class_id' => $class->id,
        ]);

        $maths = Subject::query()->firstOrCreate(['code' => 'MATHS'], ['name' => 'Mathematics']);
        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $maths->id,
            'status' => SyllabusVersion::STATUS_PUBLISHED,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'chapter_number' => 1,
            'name' => 'Integers',
            'sort_order' => 1,
        ]);
        StudentChapterCoverage::query()->create([
            'student_enrollment_id' => $enrollmentWithPlan->id,
            'syllabus_chapter_id' => $chapter->id,
            'status' => StudentChapterCoverage::STATUS_STUDIED,
        ]);

        $this->artisan('mentors:send-early-access-digests')
            ->assertSuccessful();

        Mail::assertSent(MentorEarlyAccessDigest::class, function (MentorEarlyAccessDigest $mail) use ($mentor) {
            if (! $mail->hasTo($mentor->email) || ! $mail->payload['has_students']) {
                return false;
            }

            $names = collect($mail->payload['students'])->pluck('name')->all();
            $planned = collect($mail->payload['students'])->firstWhere('name', 'Asha With Plan');

            return in_array('Asha With Plan', $names, true)
                && in_array('Bhav Without Plan', $names, true)
                && ($planned['has_study_plan'] ?? false) === true
                && $mail->payload['stats']['total'] === 2
                && $mail->payload['stats']['with_plan'] === 1;
        });
    }

    public function test_skips_when_disabled(): void
    {
        Mail::fake();
        config(['mentor_digest.enabled' => false]);
        $this->makeMentorWithTcode('skip@example.com');

        $this->artisan('mentors:send-early-access-digests')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    private function makeMentorWithTcode(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Early Mentor',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        app(UserGroupService::class)->attachGroupByCode($user, User::ROLE_TEACHER);
        app(UserGroupService::class)->attachGroupByCode($user, User::ROLE_MENTOR);

        AccessCode::query()->create([
            'code' => 'MMTEST1',
            'type' => AccessCode::TYPE_MENTOR,
            'status' => AccessCode::STATUS_ACTIVE,
            'user_id' => $user->id,
            'email' => $email,
            'mobile' => '9876500001',
            'generated_at' => now(),
            'expires_at' => now()->addDays(15),
        ]);

        return $user->fresh();
    }
}
