<?php

namespace Tests\Unit;

use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
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
    public function student_view_shows_coaching_teacher_and_class(): void
    {
        $service = new StudentMentorService;
        $class = new CoachingClass(['name' => 'Apex Tuition']);
        $teacher = new CoachingClassTeacher([
            'name' => 'Ravi Sir',
            'mobile' => '9876500099',
        ]);
        $teacher->setRelation('coachingClass', $class);

        $student = new Student([
            'enrollment_source' => EnrollmentSource::COACHING,
            'coaching_class_teacher_id' => 1,
            'parent1_name' => 'Someone Else',
            'parent1_mobile' => '9000000001',
            'notify_parent1_mobile' => false,
        ]);
        $student->setRelation('coachingClass', $class);
        $student->setRelation('coachingClassTeacher', $teacher);
        $student->setRelation('mentorUser', null);

        $view = $service->forStudentView($student);

        $this->assertTrue($view['mapped']);
        $this->assertSame('Ravi Sir', $view['name']);
        $this->assertSame('9876500099', $view['mobile']);
        $this->assertSame('Coaching teacher', $view['label']);
        $this->assertSame('Apex Tuition', $view['coaching_class']);
    }

    #[Test]
    public function student_view_shows_notified_parent_as_mentor(): void
    {
        $service = new StudentMentorService;
        $student = new Student([
            'enrollment_source' => EnrollmentSource::INDIVIDUAL,
            'parent1_name' => 'Meera',
            'parent1_mobile' => '9876500011',
            'notify_parent1_mobile' => true,
        ]);
        $student->setRelation('coachingClass', null);
        $student->setRelation('coachingClassTeacher', null);
        $student->setRelation('mentorUser', null);

        $view = $service->forStudentView($student);

        $this->assertTrue($view['mapped']);
        $this->assertSame('Meera', $view['name']);
        $this->assertSame('9876500011', $view['mobile']);
        $this->assertSame('Parent (communication)', $view['label']);
        $this->assertNull($view['coaching_class']);
    }

    #[Test]
    public function student_view_is_empty_when_unmapped(): void
    {
        $service = new StudentMentorService;
        $student = new Student([
            'enrollment_source' => EnrollmentSource::INDIVIDUAL,
            'parent1_name' => 'Dad',
            'parent1_mobile' => '9000000001',
            'notify_parent1_mobile' => false,
            'notify_parent2_mobile' => false,
        ]);
        $student->setRelation('coachingClass', null);
        $student->setRelation('coachingClassTeacher', null);
        $student->setRelation('mentorUser', null);

        $view = $service->forStudentView($student);

        $this->assertFalse($view['mapped']);
        $this->assertNull($view['name']);
        $this->assertNull($view['mobile']);
        $this->assertSame('Not assigned yet', $view['label']);
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
