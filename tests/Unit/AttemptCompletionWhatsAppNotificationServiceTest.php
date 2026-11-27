<?php

namespace Tests\Unit;

use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\Student;
use App\Services\AttemptCompletionWhatsAppNotificationService;
use App\Services\StudentMentorService;
use App\Services\StudentNotificationContactService;
use App\Support\EnrollmentSource;
use App\Support\WhatsApp\WhatsAppTemplateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttemptCompletionWhatsAppNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AttemptCompletionWhatsAppNotificationService
    {
        return new AttemptCompletionWhatsAppNotificationService(
            new StudentMentorService,
            new StudentNotificationContactService,
        );
    }

    public function test_message_lists_score_and_wrong_sums(): void
    {
        $message = $this->service()->buildMessage([
            'student_name' => 'Vishvesh',
            'kind_label' => 'Test',
            'set_code' => 'T1',
            'tier_label' => 'Chapter Test',
            'scope_line' => 'Chapter: Fractions',
            'score_label' => '60% (3/5)',
            'is_guided' => false,
            'wrong_questions' => [
                ['number' => 2, 'outcome_label' => 'Wrong answer', 'topic_name' => 'Like fractions'],
                ['number' => 5, 'outcome_label' => 'Wrong answer', 'topic_name' => 'Unlike fractions'],
            ],
        ], 'https://mentormaths.in/dashboard');

        $this->assertStringContainsString("Vishvesh finished Test T1.\nChapter Test\nChapter: Fractions\nScore: 60% (3/5)", $message);
        $this->assertStringContainsString("Wrong sums:\nQ2 · Like fractions — Wrong answer\nQ5 · Unlike fractions — Wrong answer", $message);
        $this->assertStringContainsString("View details:\nhttps://mentormaths.in/dashboard", $message);
    }

    public function test_completion_parameters_are_single_lines_for_template(): void
    {
        $params = $this->service()->completionParameters([
            'student_name' => 'VISHVESH',
            'kind_label' => 'Test',
            'set_code' => 'BF7223',
            'tier_label' => 'Achiever',
            'scope_line' => 'Chapter: Operations with Integers',
            'score_label' => '80% (12/15)',
            'is_guided' => true,
            'first_try_correct' => 12,
            'corrected_after_help' => 0,
            'given_up' => 2,
            'wrong_questions' => [
                ['number' => 2, 'topic_name' => 'Rules of signs', 'outcome_label' => 'Not correct on first try'],
                ['number' => 9, 'topic_name' => 'Word problems', 'outcome_label' => 'Gave up — needs teacher help'],
                ['number' => 11, 'topic_name' => 'Word problems', 'outcome_label' => 'Gave up — needs teacher help'],
            ],
        ], 'https://mentormaths.in/dashboard');

        $this->assertCount(9, $params);
        $this->assertSame('VISHVESH finished Test BF7223.', $params[0]);
        $this->assertSame('Achiever', $params[1]);
        $this->assertSame('Chapter: Operations with Integers', $params[2]);
        $this->assertSame('80% (12/15)', $params[3]);
        $this->assertStringContainsString('First try correct: 12', $params[4]);
        $this->assertStringContainsString('Q2', $params[5]);
        $this->assertSame('https://mentormaths.in/dashboard', $params[8]);

        $template = WhatsAppTemplateBuilder::forAttemptCompletion($params);
        $this->assertSame('mentor_maths_completed', $template['name']);
        $this->assertCount(9, $template['components'][0]['parameters']);
    }

    public function test_notifications_go_to_mentor_and_notify_parents_without_duplicates(): void
    {
        $class = CoachingClass::query()->create([
            'name' => 'Test Coaching',
            'city' => 'Pune',
            'is_active' => true,
        ]);

        $teacher = CoachingClassTeacher::query()->create([
            'coaching_class_id' => $class->id,
            'name' => 'Mrs Sharma',
            'mobile' => '9123456780',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $student = Student::query()->create([
            'name' => 'Vishvesh',
            'school_name' => 'Test School',
            'parent1_name' => 'Atul',
            'parent1_mobile' => '9876543210',
            'notify_parent1_mobile' => true,
            'parent2_name' => 'Neeru',
            'parent2_mobile' => '9212689124',
            'notify_parent2_mobile' => true,
            'enrollment_source' => EnrollmentSource::COACHING,
            'coaching_class_id' => $class->id,
            'coaching_class_teacher_id' => $teacher->id,
        ]);

        $notifications = $this->service()->notificationsForCompletion($student, [
            'student_name' => 'Vishvesh',
            'kind_label' => 'Practice',
            'set_code' => 'P1',
            'tier_label' => 'Builder',
            'score_label' => '100% (4/4)',
            'wrong_questions' => [],
        ]);

        $this->assertCount(3, $notifications);
        $mobiles = array_column($notifications, 'mobile');
        $this->assertContains('9123456780', $mobiles);
        $this->assertContains('9876543210', $mobiles);
        $this->assertContains('9212689124', $mobiles);
        $this->assertArrayHasKey('completion_parameters', $notifications[0]);
    }
}
