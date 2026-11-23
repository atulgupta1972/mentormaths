<?php

namespace Tests\Unit;

use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\Student;
use App\Services\StudentNotificationContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentNotificationContactServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_report_includes_coaching_teacher_once(): void
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
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'notify_parent1_mobile' => true,
            'notify_parent2_mobile' => false,
            'notify_student_mobile' => false,
            'coaching_class_id' => $class->id,
            'coaching_class_teacher_id' => $teacher->id,
        ]);

        $service = new StudentNotificationContactService;
        $recipients = $service->recipientsForProgressReport($student);

        $this->assertCount(2, $recipients);
        $this->assertSame('parent1', $recipients[0]['key']);
        $this->assertSame('teacher', $recipients[1]['key']);
        $this->assertSame('Teacher (Mrs Sharma)', $recipients[1]['label']);
        $this->assertSame('9123456780', $recipients[1]['mobile']);
    }

    public function test_progress_report_does_not_duplicate_when_teacher_is_already_parent(): void
    {
        $class = CoachingClass::query()->create([
            'name' => 'Test Coaching',
            'city' => 'Pune',
            'is_active' => true,
        ]);

        $teacher = CoachingClassTeacher::query()->create([
            'coaching_class_id' => $class->id,
            'name' => 'Mrs Sharma',
            'mobile' => '9876543210',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $student = Student::query()->create([
            'name' => 'Vishvesh',
            'school_name' => 'Test School',
            'parent1_name' => 'Mrs Sharma',
            'parent1_mobile' => '9876543210',
            'notify_parent1_mobile' => true,
            'notify_parent2_mobile' => false,
            'notify_student_mobile' => false,
            'coaching_class_id' => $class->id,
            'coaching_class_teacher_id' => $teacher->id,
        ]);

        $recipients = (new StudentNotificationContactService)->recipientsForProgressReport($student);

        $this->assertCount(1, $recipients);
        $this->assertSame('parent1', $recipients[0]['key']);
    }

    public function test_default_recipients_exclude_teacher(): void
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
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'notify_parent1_mobile' => true,
            'coaching_class_id' => $class->id,
            'coaching_class_teacher_id' => $teacher->id,
        ]);

        $recipients = (new StudentNotificationContactService)->recipientsForStudent($student);

        $this->assertCount(1, $recipients);
        $this->assertSame('parent1', $recipients[0]['key']);
    }
}
