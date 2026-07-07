<?php

namespace Tests\Unit;

use App\Mail\DoubtsCleared;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionResolutionItem;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\QuestionResolutionService;
use App\Support\DoubtsClearedMailer;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class QuestionResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_answer_clears_item_without_sending_email(): void
    {
        Mail::fake();

        config(['mail.registration_notify' => 'admin@example.com']);

        [$item] = $this->seedResolutionItem(withOptions: true);
        $option = $item->question->options->firstWhere('is_correct', true);

        $result = app(QuestionResolutionService::class)->submitAnswer($item->fresh(), $option->id);

        $this->assertTrue($result['resolved']);
        $this->assertSame(QuestionResolutionItem::CLEARANCE_ANSWERED, $item->fresh()->clearance_method);

        Mail::assertNothingSent();
    }

    public function test_batch_clearance_email_sends_once_with_all_items(): void
    {
        Mail::fake();

        config(['mail.registration_notify' => 'admin@example.com']);

        [$first, $student] = $this->seedResolutionItem(withOptions: true);
        $second = $this->seedAnotherResolutionItem($first->student_enrollment_id);
        $second->question->options()->createMany([
            ['option_text' => '5', 'is_correct' => false, 'sort_order' => 1],
            ['option_text' => '6', 'is_correct' => true, 'sort_order' => 2],
        ]);

        $service = app(QuestionResolutionService::class);
        $firstOption = $first->question->options->firstWhere('is_correct', true);
        $secondOption = $second->fresh(['question.options'])->question->options->firstWhere('is_correct', true);

        $service->submitAnswer($first->fresh(['question.options']), $firstOption->id);
        $service->submitAnswer($second->fresh(['question.options']), $secondOption->id);

        $emailResult = $service->sendClearanceEmailForItems($student, [$first->id, $second->id]);

        $this->assertTrue($emailResult['sent']);

        Mail::assertSent(DoubtsCleared::class, function (DoubtsCleared $mail) use ($student) {
            return $mail->student->is($student) && count($mail->items) === 2;
        });

        Mail::assertSentCount(1);
    }

    public function test_clear_all_queue_starts_with_first_pending_item(): void
    {
        [$first] = $this->seedResolutionItem();
        $second = $this->seedAnotherResolutionItem($first->student_enrollment_id);

        $service = app(QuestionResolutionService::class);

        $this->assertTrue($service->firstPendingForEnrollment($first->student_enrollment_id)->is($second));
        $this->assertTrue($service->nextPendingAfter($first->student_enrollment_id, $second->id)->is($first));
        $this->assertNull($service->nextPendingAfter($first->student_enrollment_id, $first->id));
    }

    public function test_queue_meta_reports_position_and_total(): void
    {
        [$first] = $this->seedResolutionItem();
        $second = $this->seedAnotherResolutionItem($first->student_enrollment_id);

        $service = app(QuestionResolutionService::class);

        $this->assertSame(['position' => 1, 'total' => 2], $service->queueMetaForItem($second));
        $this->assertSame(['position' => 2, 'total' => 2], $service->queueMetaForItem($first));
    }

    public function test_history_lists_cleared_items_with_dates(): void
    {
        [$item] = $this->seedResolutionItem(withOptions: true);
        $option = $item->question->options->firstWhere('is_correct', true);

        app(QuestionResolutionService::class)->submitAnswer($item->fresh(), $option->id);

        $history = app(QuestionResolutionService::class)->historyForEnrollment($item->student_enrollment_id);

        $this->assertCount(1, $history);
        $this->assertSame($item->id, $history[0]['id']);
        $this->assertNotNull($history[0]['gave_up_at']);
        $this->assertNotNull($history[0]['resolved_at']);
        $this->assertSame('Answered correctly', $history[0]['clearance_label']);
    }

    public function test_doubts_cleared_mailable_uses_requested_subject(): void
    {
        [$item, $student] = $this->seedResolutionItem();

        $mailable = new DoubtsCleared($student, [[
            'set_code' => 'S711',
            'question_text' => 'What is 2 + 2?',
            'topic_label' => 'Addition (Numbers)',
            'asked_label' => '7 Jul 2026, 4:00 PM',
            'cleared_label' => '7 Jul 2026, 4:20 PM',
        ]]);

        $this->assertSame('Topics — doubts cleared', $mailable->envelope()->subject);
    }

    public function test_mailer_skips_when_student_has_no_email(): void
    {
        Mail::fake();

        [$item, $student] = $this->seedResolutionItem();
        $student->update(['email' => null, 'user_id' => null]);

        $result = DoubtsClearedMailer::send($student->fresh(), [[
            'set_code' => 'S711',
            'question_text' => 'Sample',
            'topic_label' => null,
            'asked_label' => 'Now',
            'cleared_label' => 'Now',
        ]]);

        $this->assertFalse($result['sent']);
        $this->assertSame('no_email', $result['error']);
        Mail::assertNothingSent();
    }

    /**
     * @return array{0: QuestionResolutionItem, 1: Student}
     */
    private function seedResolutionItem(bool $withOptions = false): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create([
            'code' => 'CBSE',
            'name' => 'CBSE',
            'is_active' => true,
        ]);

        $grade = GradeLevel::query()->create([
            'name' => 'Class 7',
            'sort_order' => 7,
            'is_active' => true,
        ]);

        $subject = Subject::query()->create([
            'code' => 'MATHS',
            'name' => 'Mathematics',
        ]);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Integers',
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);

        $user = User::factory()->create(['email' => 'student@example.com']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Student',
            'email' => 'student@example.com',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Starter set',
            'set_number' => 711,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'What is 2 + 2?',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        if ($withOptions) {
            $question->options()->createMany([
                ['option_text' => '3', 'is_correct' => false, 'sort_order' => 1],
                ['option_text' => '4', 'is_correct' => true, 'sort_order' => 2],
            ]);
        }

        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_IN_PROGRESS,
        ]);

        $item = QuestionResolutionItem::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'question_id' => $question->id,
            'set_assignment_id' => $assignment->id,
            'status' => QuestionResolutionItem::STATUS_PENDING,
            'gave_up_at' => now()->subHour(),
        ]);

        return [$item->fresh(['question.options', 'question.topic.chapter', 'assignment.practiceSet', 'enrollment.student']), $student];
    }

    private function seedAnotherResolutionItem(int $enrollmentId): QuestionResolutionItem
    {
        $assignment = SetAssignment::query()->where('student_enrollment_id', $enrollmentId)->firstOrFail();
        $assignment->loadMissing('practiceSet');
        $topicId = $assignment->practiceSet->syllabus_topic_id;

        $question = Question::query()->create([
            'syllabus_topic_id' => $topicId,
            'question_text' => 'What is 3 + 3?',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        return QuestionResolutionItem::query()->create([
            'student_enrollment_id' => $enrollmentId,
            'question_id' => $question->id,
            'set_assignment_id' => $assignment->id,
            'status' => QuestionResolutionItem::STATUS_PENDING,
            'gave_up_at' => now()->subMinutes(30),
        ]);
    }
}
