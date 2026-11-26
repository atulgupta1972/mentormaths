<?php

namespace Tests\Unit;

use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\Student;
use App\Services\AttemptCompletionWhatsAppNotificationService;
use App\Services\StudentMentorService;
use App\Support\EnrollmentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AttemptCompletionWhatsAppNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_lists_score_and_wrong_sums(): void
    {
        $service = new AttemptCompletionWhatsAppNotificationService(new StudentMentorService);

        $message = $service->buildMessage([
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

        $this->assertStringContainsString('Vishvesh finished Test T1.', $message);
        $this->assertStringContainsString('Score: 60% (3/5)', $message);
        $this->assertStringContainsString('Wrong sums:', $message);
        $this->assertStringContainsString('Q2 · Like fractions — Wrong answer', $message);
        $this->assertStringContainsString('Q5 · Unlike fractions — Wrong answer', $message);
        $this->assertStringContainsString('https://mentormaths.in/dashboard', $message);
    }

    public function test_notifications_go_to_coaching_teacher_mentor(): void
    {
        Http::fake();

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
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'notify_parent1_mobile' => true,
            'enrollment_source' => EnrollmentSource::COACHING,
            'coaching_class_id' => $class->id,
            'coaching_class_teacher_id' => $teacher->id,
        ]);

        $notifications = (new AttemptCompletionWhatsAppNotificationService(new StudentMentorService))
            ->notificationsForCompletion($student, [
                'student_name' => 'Vishvesh',
                'kind_label' => 'Practice',
                'set_code' => 'P1',
                'tier_label' => 'Builder',
                'score_label' => '4/4',
                'score_percent' => 100,
                'wrong_questions' => [],
            ]);

        $this->assertCount(1, $notifications);
        $this->assertSame('9123456780', $notifications[0]['mobile']);
        $this->assertStringContainsString('Mrs Sharma', $notifications[0]['label']);
        $this->assertStringContainsString('Wrong sums: none', $notifications[0]['message']);
    }
}
