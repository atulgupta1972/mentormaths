<?php

namespace Tests\Unit;

use App\Models\Student;
use App\Services\StudentMentorService;
use App\Support\EnrollmentSource;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StudentMentorServiceTest extends TestCase
{
    #[Test]
    public function individual_mentor_requires_notify_tick(): void
    {
        $service = new StudentMentorService;
        $student = new Student([
            'enrollment_source' => EnrollmentSource::INDIVIDUAL,
            'parent1_name' => 'Dad',
            'parent1_mobile' => '9000000001',
            'notify_parent1_mobile' => false,
            'parent2_name' => 'Mom',
            'parent2_mobile' => '9000000002',
            'notify_parent2_mobile' => true,
        ]);

        $this->assertTrue($service->isMapped($student));
        $resolved = $service->resolve($student);
        $this->assertSame(EnrollmentSource::MENTOR_PARENT2, $resolved['type']);
        $this->assertSame('Mom', $resolved['name']);
    }

    #[Test]
    public function individual_without_notify_is_unmapped(): void
    {
        $service = new StudentMentorService;
        $student = new Student([
            'enrollment_source' => EnrollmentSource::INDIVIDUAL,
            'parent1_name' => 'Dad',
            'parent1_mobile' => '9000000001',
            'notify_parent1_mobile' => false,
            'notify_parent2_mobile' => false,
        ]);

        $this->assertFalse($service->isMapped($student));
    }

    #[Test]
    public function enrollment_source_options_mark_school_disabled(): void
    {
        $options = EnrollmentSource::optionsForUi();
        $school = collect($options)->firstWhere('value', EnrollmentSource::SCHOOL);

        $this->assertNotNull($school);
        $this->assertFalse($school['enabled']);
    }
}
