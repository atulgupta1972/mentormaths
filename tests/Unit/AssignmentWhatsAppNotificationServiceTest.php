<?php

namespace Tests\Unit;

use App\Models\Student;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\Worksheet;
use App\Services\AssignmentWhatsAppNotificationService;
use App\Services\StudentNotificationContactService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentWhatsAppNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_message_includes_set_details_time_and_deadline(): void
    {
        $chapter = new SyllabusChapter(['name' => 'Integers']);
        $topic = new SyllabusTopic(['name' => 'Properties of Addition']);
        $topic->setRelation('chapter', $chapter);

        $worksheet = new Worksheet([
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
        ]);
        $worksheet->setRelation('topic', $topic);
        $worksheet->questions_count = 7;

        $student = new Student(['name' => 'Vishvesh']);

        $service = new AssignmentWhatsAppNotificationService(new StudentNotificationContactService);

        $message = $service->buildMessage($student, $worksheet, '2026-07-10');

        $this->assertStringContainsString('Vishvesh', $message);
        $this->assertStringContainsString('S711', $message);
        $this->assertStringContainsString('Properties of Addition', $message);
        $this->assertStringContainsString('7 questions', $message);
        $this->assertStringContainsString('approx', $message);
        $this->assertStringContainsString('10 Jul 2026', $message);
    }

    public function test_notifications_only_include_notify_enabled_contacts(): void
    {
        $worksheet = Worksheet::query()->create([
            'title' => 'Builder set 1',
            'set_number' => 1,
            'set_code' => 'B712',
            'tier' => PracticeSetTier::BUILDER,
            'scope' => PracticeSetScope::TOPIC,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);
        $worksheet->questions_count = 5;

        $student = new Student([
            'name' => 'Asha',
            'student_mobile' => '9876543210',
            'parent1_name' => 'Parent One',
            'parent1_mobile' => '9123456789',
            'notify_student_mobile' => false,
            'notify_parent1_mobile' => true,
        ]);

        $service = new AssignmentWhatsAppNotificationService(new StudentNotificationContactService);

        $notifications = $service->notificationsForAssignment($student, $worksheet, '2026-07-12');

        $this->assertCount(1, $notifications);
        $this->assertSame('9123456789', $notifications[0]['mobile']);
        $this->assertStringContainsString('Asha', $notifications[0]['message']);
    }
}
