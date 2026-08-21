<?php

use App\Http\Controllers\Admin\CoachingClassController;
use App\Http\Controllers\Admin\AcademicYearController;
use App\Http\Controllers\Admin\BasicsDrillSettingsController;
use App\Http\Controllers\Admin\CatchUpSetController;
use App\Http\Controllers\Admin\ChapterHeadController;
use App\Http\Controllers\Admin\ChapterPracticeSetController;
use App\Http\Controllers\Admin\ClassAssignmentController;
use App\Http\Controllers\Admin\ClassHubController;
use App\Http\Controllers\Admin\ContentCoverageController;
use App\Http\Controllers\Admin\ContentFinanceController;
use App\Http\Controllers\Admin\ContentRateCardController;
use App\Http\Controllers\Admin\ContentUploadTaskController;
use App\Http\Controllers\Admin\ExamPlanController as AdminExamPlanController;
use App\Http\Controllers\Admin\FormulaBankController;
use App\Http\Controllers\Admin\GradeContextController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\NotificationSettingsController;
use App\Http\Controllers\Admin\PracticeSetController;
use App\Http\Controllers\Admin\PracticeSetTopicController;
use App\Http\Controllers\Admin\QuestionAuditController;
use App\Http\Controllers\Admin\QuestionController;
use App\Http\Controllers\Admin\QuestionHubController;
use App\Http\Controllers\Admin\RegistrationRequestController as AdminRegistrationRequestController;
use App\Http\Controllers\Admin\SchoolStudyPlanController;
use App\Http\Controllers\Admin\SetAssignmentController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\StudentWorkReportController;
use App\Http\Controllers\Admin\SyllabusVersionController;
use App\Http\Controllers\Admin\TeacherRegistrationRequestController as AdminTeacherRegistrationRequestController;
use App\Http\Controllers\Admin\TextbookController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WrittenSheetController;
use App\Http\Controllers\Admin\WrittenReviewController;
use App\Http\Controllers\ContentUploader\ChapterLibraryController;
use App\Http\Controllers\ContentUploader\ContentTaskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationRequestController;
use App\Http\Controllers\Student\BasicsDrillController;
use App\Http\Controllers\Student\ClassCoverageController;
use App\Http\Controllers\Student\ExamPlanController as StudentExamPlanController;
use App\Http\Controllers\Student\FormulaDrillController;
use App\Http\Controllers\Student\FormulaResourceController;
use App\Http\Controllers\Student\PracticeCorrectionController;
use App\Http\Controllers\Student\PracticeSetController as StudentPracticeSetController;
use App\Http\Controllers\Student\SelfAssignController;
use App\Http\Controllers\Student\WrittenAssignmentController as StudentWrittenAssignmentController;
use App\Http\Controllers\StudentProfileController;
use App\Http\Controllers\TeacherRegistrationRequestController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('/privacy', function () {
    return Inertia::render('Privacy');
})->name('privacy');

Route::get('/register/request', [RegistrationRequestController::class, 'create'])
    ->name('registration.create');
Route::post('/register/request', [RegistrationRequestController::class, 'store'])
    ->name('registration.store');
Route::get('/register/thank-you', [RegistrationRequestController::class, 'thankYou'])
    ->name('registration.thank-you');

Route::get('/teachers/register', [TeacherRegistrationRequestController::class, 'create'])
    ->name('teacher-registration.create');
Route::redirect('/mentors/register', '/teachers/register');
Route::post('/teachers/register', [TeacherRegistrationRequestController::class, 'store'])
    ->name('teacher-registration.store');
Route::get('/teachers/register/thank-you', [TeacherRegistrationRequestController::class, 'thankYou'])
    ->name('teacher-registration.thank-you');
Route::get('/teachers/register/offer/{token}', [TeacherRegistrationRequestController::class, 'showOffer'])
    ->name('teacher-registration.offer');
Route::post('/teachers/register/offer/{token}', [TeacherRegistrationRequestController::class, 'respondToOffer'])
    ->name('teacher-registration.offer.respond');
Route::get('/teachers/register/profile/{token}', [TeacherRegistrationRequestController::class, 'showCompleteProfile'])
    ->name('teacher-registration.profile');
Route::post('/teachers/register/profile/{token}', [TeacherRegistrationRequestController::class, 'updateCompleteProfile'])
    ->name('teacher-registration.profile.update');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified', 'formula.drill', 'basics.drill'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/classes', [ClassHubController::class, 'index'])->name('classes.index');
    Route::get('/classes/{gradeLevel}', [ClassHubController::class, 'show'])->name('classes.show');
    Route::patch('/classes/{gradeLevel}/attempt-protection', [ClassHubController::class, 'updateAttemptProtection'])
        ->name('classes.attempt-protection.update');
    Route::get('/classes/{gradeLevel}/assign', [ClassAssignmentController::class, 'show'])->name('classes.assign');
    Route::post('/classes/{gradeLevel}/assign', [ClassAssignmentController::class, 'store'])->name('classes.assign.store');

    Route::get('/syllabus/{syllabusVersion}', [SyllabusVersionController::class, 'show'])
        ->whereNumber('syllabusVersion')
        ->name('syllabus.show');

    Route::get('/questions', [QuestionHubController::class, 'classes'])->name('questions.index');
    Route::get('/questions/classes/{gradeLevel}', [QuestionHubController::class, 'chapters'])->name('questions.classes.show');
    Route::get('/questions/chapters/{chapter}', [QuestionHubController::class, 'topics'])->name('questions.chapters.show');
    Route::get('/questions/sets/{worksheet}', [QuestionHubController::class, 'setQuestions'])->name('questions.sets.show');
    Route::get('/questions/topics/{topic}', [QuestionController::class, 'topicIndex'])->name('questions.topics.show');
    // Browse-only for students: Q&A redacted in controller. Admin import stays under admin middleware.
    Route::get('/formula-bank/sets/{worksheet}', [FormulaBankController::class, 'setShow'])->name('formula-bank.sets.show');
});

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/registration-requests', [AdminRegistrationRequestController::class, 'index'])
        ->name('registration-requests.index');
    Route::get('/registration-requests/{registrationRequest}', [AdminRegistrationRequestController::class, 'show'])
        ->name('registration-requests.show');
    Route::post('/registration-requests/{registrationRequest}/approve', [AdminRegistrationRequestController::class, 'approve'])
        ->name('registration-requests.approve');
    Route::post('/registration-requests/{registrationRequest}/reject', [AdminRegistrationRequestController::class, 'reject'])
        ->name('registration-requests.reject');
    Route::patch('/registration-requests/{registrationRequest}/contacts', [AdminRegistrationRequestController::class, 'updateContacts'])
        ->name('registration-requests.contacts.update');

    Route::get('/teacher-registrations', [AdminTeacherRegistrationRequestController::class, 'index'])
        ->name('teacher-registrations.index');
    Route::get('/teacher-registrations/{teacherRegistration}', [AdminTeacherRegistrationRequestController::class, 'show'])
        ->name('teacher-registrations.show');
    Route::get('/teacher-registrations/{teacherRegistration}/resume', [AdminTeacherRegistrationRequestController::class, 'downloadResume'])
        ->name('teacher-registrations.resume');
    Route::post('/teacher-registrations/{teacherRegistration}/counter-offer', [AdminTeacherRegistrationRequestController::class, 'sendCounterOffer'])
        ->name('teacher-registrations.counter-offer');
    Route::post('/teacher-registrations/{teacherRegistration}/request-profile', [AdminTeacherRegistrationRequestController::class, 'requestProfileCompletion'])
        ->name('teacher-registrations.request-profile');
    Route::post('/teacher-registrations/{teacherRegistration}/approve', [AdminTeacherRegistrationRequestController::class, 'approve'])
        ->name('teacher-registrations.approve');
    Route::post('/teacher-registrations/{teacherRegistration}/resend-welcome', [AdminTeacherRegistrationRequestController::class, 'resendWelcomeEmail'])
        ->name('teacher-registrations.resend-welcome');
    Route::post('/teacher-registrations/{teacherRegistration}/grant-content-uploader', [AdminTeacherRegistrationRequestController::class, 'grantContentUploader'])
        ->name('teacher-registrations.grant-content-uploader');
    Route::post('/teacher-registrations/{teacherRegistration}/reject', [AdminTeacherRegistrationRequestController::class, 'reject'])
        ->name('teacher-registrations.reject');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');

    Route::get('/groups', [GroupController::class, 'index'])->name('groups.index');
    Route::post('/groups', [GroupController::class, 'store'])->name('groups.store');
    Route::put('/groups/{group}', [GroupController::class, 'update'])->name('groups.update');
    Route::delete('/groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');

    Route::post('/grade-context', [GradeContextController::class, 'update'])
        ->name('grade-context.update');

    Route::get('/academic-years', [AcademicYearController::class, 'index'])
        ->name('academic-years.index');
    Route::post('/academic-years', [AcademicYearController::class, 'store'])
        ->name('academic-years.store');
    Route::post('/academic-years/{academicYear}/activate', [AcademicYearController::class, 'activate'])
        ->name('academic-years.activate');

    Route::get('/coaching-classes', [CoachingClassController::class, 'index'])
        ->name('coaching-classes.index');
    Route::get('/coaching-classes/pincode/{pinCode}', [CoachingClassController::class, 'lookupPincode'])
        ->where('pinCode', '[0-9]{6}')
        ->name('coaching-classes.pincode');
    Route::post('/coaching-classes', [CoachingClassController::class, 'store'])
        ->name('coaching-classes.store');
    Route::patch('/coaching-classes/{coachingClass}', [CoachingClassController::class, 'update'])
        ->name('coaching-classes.update');
    Route::post('/coaching-classes/{coachingClass}/toggle-active', [CoachingClassController::class, 'toggleActive'])
        ->name('coaching-classes.toggle-active');
    Route::post('/coaching-classes/{coachingClass}/map-students', [CoachingClassController::class, 'mapStudents'])
        ->name('coaching-classes.map-students');
    Route::post('/coaching-classes/{coachingClass}/teachers', [CoachingClassController::class, 'storeTeacher'])
        ->name('coaching-classes.teachers.store');
    Route::patch('/coaching-class-teachers/{teacher}', [CoachingClassController::class, 'updateTeacher'])
        ->name('coaching-class-teachers.update');
    Route::delete('/coaching-class-teachers/{teacher}', [CoachingClassController::class, 'destroyTeacher'])
        ->name('coaching-class-teachers.destroy');

    Route::get('/students', [StudentController::class, 'index'])
        ->name('students.index');
    Route::get('/students/{student}', [StudentController::class, 'show'])
        ->name('students.show');
    Route::get('/dashboard/students/{student}', [DashboardController::class, 'student'])
        ->name('dashboard.student');
    Route::post('/students/{student}/toggle-active', [StudentController::class, 'toggleActive'])
        ->name('students.toggle-active');
    Route::delete('/students/{student}', [StudentController::class, 'destroy'])
        ->name('students.destroy');
    Route::patch('/students/{student}/contacts', [StudentController::class, 'updateContacts'])
        ->name('students.contacts.update');
    Route::post('/students/{student}/send-pending-work', [StudentController::class, 'sendPendingWork'])
        ->name('students.send-pending-work');
    Route::get('/students/{student}/progress-summary-preview', [StudentController::class, 'progressSummaryPreview'])
        ->name('students.progress-summary-preview');
    Route::post('/students/{student}/send-progress-summary', [StudentController::class, 'sendProgressSummary'])
        ->name('students.send-progress-summary');
    Route::get('/students/{student}/progress-summary-pdf', [StudentController::class, 'progressSummaryPdf'])
        ->name('students.progress-summary-pdf');
    Route::patch('/students/{student}/emails', [StudentController::class, 'updateEmails'])
        ->name('students.emails.update');
    Route::patch('/students/{student}/mentor', [StudentController::class, 'mapMentor'])
        ->name('students.mentor.map');
    Route::post('/students/{student}/promote', [StudentController::class, 'promote'])
        ->name('students.promote');
    Route::post('/students/bulk-promote', [StudentController::class, 'bulkPromote'])
        ->name('students.bulk-promote');

    Route::get('/notifications', [NotificationSettingsController::class, 'index'])
        ->name('notifications.index');
    Route::post('/notifications/send-pending-work', [NotificationSettingsController::class, 'sendPendingWorkAll'])
        ->name('notifications.send-pending-work');

    Route::get('/student-work-report', [StudentWorkReportController::class, 'index'])
        ->name('student-work-report.index');
    Route::post('/student-work-report/send-reminders', [StudentWorkReportController::class, 'sendReminders'])
        ->name('student-work-report.send-reminders');

    Route::get('/school-study-plan', [SchoolStudyPlanController::class, 'index'])
        ->name('school-study-plan.index');
    Route::post('/school-study-plan/send-reminders', [SchoolStudyPlanController::class, 'sendReminders'])
        ->name('school-study-plan.send-reminders');
    Route::put('/school-study-plan/{student}/{syllabusChapter}', [SchoolStudyPlanController::class, 'update'])
        ->name('school-study-plan.update');

    Route::get('/syllabus', [SyllabusVersionController::class, 'index'])
        ->name('syllabus.index');
    Route::post('/syllabus', [SyllabusVersionController::class, 'store'])
        ->name('syllabus.store');
    Route::post('/syllabus/import', [SyllabusVersionController::class, 'import'])
        ->name('syllabus.import');
    Route::post('/syllabus/{syllabusVersion}/import', [SyllabusVersionController::class, 'importIntoVersion'])
        ->name('syllabus.import-into');
    Route::post('/syllabus/{syllabusVersion}/import-preview', [SyllabusVersionController::class, 'previewImportIntoVersion'])
        ->name('syllabus.import-preview');
    Route::put('/syllabus/{syllabusVersion}/rows', [SyllabusVersionController::class, 'updateRows'])
        ->name('syllabus.rows.update');
    Route::post('/syllabus/{syllabusVersion}/chapters/{syllabusChapter}/move-content', [SyllabusVersionController::class, 'moveChapterContent'])
        ->name('syllabus.chapters.move-content');
    Route::post('/syllabus/{syllabusVersion}/clear', [SyllabusVersionController::class, 'clearRows'])
        ->name('syllabus.clear');
    Route::post('/syllabus/{syllabusVersion}/topics', [SyllabusVersionController::class, 'storeTopic'])
        ->name('syllabus.topics.store');
    Route::post('/syllabus/{syllabusVersion}/carry-forward', [SyllabusVersionController::class, 'carryForward'])
        ->name('syllabus.carry-forward');

    Route::get('/chapter-heads', [ChapterHeadController::class, 'index'])->name('chapter-heads.index');
    Route::post('/chapter-heads', [ChapterHeadController::class, 'store'])->name('chapter-heads.store');
    Route::post('/chapter-heads/quick', [ChapterHeadController::class, 'storeQuick'])->name('chapter-heads.quick-store');
    Route::get('/chapter-heads/{chapterHead}', [ChapterHeadController::class, 'show'])->name('chapter-heads.show');
    Route::put('/chapter-heads/{chapterHead}', [ChapterHeadController::class, 'update'])->name('chapter-heads.update');
    Route::delete('/chapter-heads/{chapterHead}', [ChapterHeadController::class, 'destroy'])->name('chapter-heads.destroy');

    Route::get('/questions/create', [QuestionController::class, 'create'])->name('questions.create');
    Route::get('/questions/create-fill-in-blank', [QuestionController::class, 'createFillInBlank'])->name('questions.create-fill-in-blank');
    Route::post('/questions/preview-fill-blank-import', [QuestionController::class, 'previewFillBlankImport'])->name('questions.preview-fill-blank-import');
    Route::post('/questions/bulk-store-fill-blank', [QuestionController::class, 'storeBulkFillBlank'])->name('questions.bulk-store-fill-blank');
    Route::post('/questions/preview-import', [QuestionController::class, 'previewImport'])->name('questions.preview-import');
    Route::post('/questions/import-zip-pack', [QuestionController::class, 'importZipPack'])->name('questions.import-zip-pack');
    Route::post('/questions/extract-pdf', [QuestionController::class, 'extractPdf'])->name('questions.extract-pdf');
    Route::post('/questions/extract-pdf-worksheet', [QuestionController::class, 'extractPdfWorksheet'])->name('questions.extract-pdf-worksheet');
    Route::post('/questions/bulk-store', [QuestionController::class, 'storeBulk'])->name('questions.bulk-store');
    Route::post('/questions/bulk-store-chapter', [QuestionController::class, 'storeBulkChapter'])->name('questions.bulk-store-chapter');
    Route::post('/questions/chapter-fill-blank-prompt', [QuestionController::class, 'chapterFillBlankPrompt'])->name('questions.chapter-fill-blank-prompt');
    Route::post('/questions/bulk-store-chapter-fill-blank', [QuestionController::class, 'storeBulkChapterFillBlank'])->name('questions.bulk-store-chapter-fill-blank');
    Route::post('/questions/chapter-prompt', [QuestionController::class, 'chapterPrompt'])->name('questions.chapter-prompt');
    Route::post('/questions/topics/{topic}/generate-method-hints', [QuestionController::class, 'generateMethodHints'])
        ->name('questions.topics.generate-method-hints');
    Route::delete('/questions/topics/{topic}/bank', [QuestionController::class, 'clearTopicBank'])
        ->name('questions.topics.clear-bank');
    Route::get('/questions/{question}/edit', [QuestionController::class, 'edit'])->name('questions.edit');
    Route::put('/questions/{question}', [QuestionController::class, 'update'])->name('questions.update');
    Route::patch('/questions/{question}/fill-blank', [QuestionController::class, 'updateFillBlank'])->name('questions.fill-blank.update');
    Route::delete('/questions/{question}', [QuestionController::class, 'destroy'])->name('questions.destroy');
    Route::get('/questions/set-code', [QuestionHubController::class, 'setCodeReview'])->name('questions.set-code');
    Route::get('/questions/coverage', [ContentCoverageController::class, 'index'])->name('questions.coverage');

    Route::get('/question-audit', [QuestionAuditController::class, 'index'])->name('question-audit.index');
    Route::get('/question-audit/classes/{gradeLevel}', [QuestionAuditController::class, 'chapters'])->name('question-audit.classes.show');
    Route::get('/question-audit/chapters/{chapter}', [QuestionAuditController::class, 'chapterSets'])->name('question-audit.chapters.show');
    Route::get('/question-audit/worksheets/{worksheet}', [QuestionAuditController::class, 'show'])->name('question-audit.worksheets.show');
    Route::post('/question-audit/worksheets/{worksheet}/run', [QuestionAuditController::class, 'run'])->name('question-audit.worksheets.run');

    Route::post('/practice-sets/from-topic/{topic}', [PracticeSetController::class, 'storeFromTopic'])
        ->name('practice-sets.from-topic');
    Route::get('/practice-sets/chapters/{chapter}', [ChapterPracticeSetController::class, 'show'])->name('practice-sets.chapters.show');
    Route::get('/practice-sets/chapters/{chapter}/create', [ChapterPracticeSetController::class, 'create'])->name('practice-sets.chapters.create');
    Route::post('/practice-sets/chapters/{chapter}', [ChapterPracticeSetController::class, 'store'])->name('practice-sets.chapters.store');
    Route::post('/practice-sets/chapters/{chapter}/auto-mix', [ChapterPracticeSetController::class, 'storeAutoMix'])->name('practice-sets.chapters.auto-mix');
    Route::post('/practice-sets/chapters/{chapter}/from-bank', [ChapterPracticeSetController::class, 'storeFromChapterBank'])->name('practice-sets.chapters.from-bank');
    Route::post('/practice-sets/chapters/{chapter}/from-practice-bank', [ChapterPracticeSetController::class, 'storeFromChapterPracticeBank'])->name('practice-sets.chapters.from-practice-bank');
    Route::delete('/questions/chapters/{chapter}/practice-bank', [QuestionController::class, 'clearChapterPracticeBank'])->name('questions.chapters.clear-practice-bank');
    Route::get('/practice-sets', [PracticeSetController::class, 'index'])->name('practice-sets.index');
    Route::get('/practice-sets/create', [PracticeSetController::class, 'create'])->name('practice-sets.create');
    Route::post('/practice-sets', [PracticeSetController::class, 'store'])->name('practice-sets.store');
    Route::get('/practice-sets/topics/{topic}', [PracticeSetTopicController::class, 'show'])->name('practice-sets.topics.show');
    Route::get('/practice-sets/{worksheet}', [PracticeSetController::class, 'show'])->name('practice-sets.show');
    Route::delete('/practice-sets/{worksheet}', [PracticeSetController::class, 'destroy'])->name('practice-sets.destroy');

    Route::get('/catch-up', [CatchUpSetController::class, 'index'])->name('catch-up.index');
    Route::post('/catch-up/prompt', [CatchUpSetController::class, 'prompt'])->name('catch-up.prompt');
    Route::post('/catch-up/import', [CatchUpSetController::class, 'import'])->name('catch-up.import');

    Route::get('/written-sheets', [WrittenSheetController::class, 'index'])->name('written-sheets.index');
    Route::get('/written-review', [WrittenReviewController::class, 'index'])->name('written-review.index');
    Route::get('/written-sheets/create', [WrittenSheetController::class, 'create'])->name('written-sheets.create');
    Route::post('/written-sheets/chapter-prompt', [WrittenSheetController::class, 'chapterPrompt'])->name('written-sheets.chapter-prompt');
    Route::post('/written-sheets/stage-pdf', [WrittenSheetController::class, 'stagePdf'])->name('written-sheets.stage-pdf');
    Route::post('/written-sheets/parse-answer-pdf', [WrittenSheetController::class, 'parseAnswerPdf'])->name('written-sheets.parse-answer-pdf');
    Route::post('/written-sheets', [WrittenSheetController::class, 'store'])->name('written-sheets.store');
    Route::post('/written-sheets/import-zip-pack', [WrittenSheetController::class, 'importZipPack'])->name('written-sheets.import-zip-pack');
    Route::get('/written-sheets/{worksheet}', [WrittenSheetController::class, 'show'])->name('written-sheets.show');
    Route::post('/written-sheets/{worksheet}/regenerate', [WrittenSheetController::class, 'regenerate'])->name('written-sheets.regenerate');
    Route::post('/written-sheets/{worksheet}/replace-pdf', [WrittenSheetController::class, 'replacePdf'])->name('written-sheets.replace-pdf');
    Route::post('/written-sheets/{worksheet}/remove-pdf', [WrittenSheetController::class, 'removePdf'])->name('written-sheets.remove-pdf');
    Route::post('/written-sheets/{worksheet}/reimport-zip-pack', [WrittenSheetController::class, 'reimportZipPack'])->name('written-sheets.reimport-zip-pack');
    Route::post('/written-sheets/{worksheet}/reimport-json', [WrittenSheetController::class, 'reimportJson'])->name('written-sheets.reimport-json');
    Route::post('/written-sheets/{worksheet}/update-answers', [WrittenSheetController::class, 'updateAnswers'])->name('written-sheets.update-answers');
    Route::post('/written-sheets/{worksheet}/update-questions', [WrittenSheetController::class, 'updateQuestions'])->name('written-sheets.update-questions');
    Route::post('/written-sheets/{worksheet}/verify', [WrittenSheetController::class, 'verify'])->name('written-sheets.verify');
    Route::post('/written-sheets/{worksheet}/reject', [WrittenSheetController::class, 'reject'])->name('written-sheets.reject');
    Route::get('/written-sheets/{worksheet}/download', [WrittenSheetController::class, 'download'])->name('written-sheets.download');
    Route::post('/written-assignments/{assignment}/manual-grade', [WrittenSheetController::class, 'manualGrade'])->name('written-assignments.manual-grade');
    Route::post('/written-assignments/{assignment}/upload-revision', [WrittenSheetController::class, 'uploadRevision'])->name('written-assignments.upload-revision');
    Route::post('/written-assignments/{assignment}/upload-work', [WrittenSheetController::class, 'uploadWork'])->name('written-assignments.upload-work');
    Route::post('/written-assignments/{assignment}/retry-ai', [WrittenSheetController::class, 'retryAiGrading'])->name('written-assignments.retry-ai');
    Route::post('/written-assignments/{assignment}/retry-ai-question', [WrittenSheetController::class, 'retryAiQuestion'])->name('written-assignments.retry-ai-question');

    Route::get('/textbooks', [TextbookController::class, 'index'])->name('textbooks.index');
    Route::get('/textbooks/create', [TextbookController::class, 'create'])->name('textbooks.create');
    Route::post('/textbooks', [TextbookController::class, 'store'])->name('textbooks.store');
    Route::get('/textbooks/chapters/{textbookChapter}', [TextbookController::class, 'show'])->name('textbooks.show');
    Route::post('/textbooks/chapters/{textbookChapter}/draft', [TextbookController::class, 'updateDraft'])->name('textbooks.draft');
    Route::post('/textbooks/chapters/{textbookChapter}/publish', [TextbookController::class, 'publish'])->name('textbooks.publish');
    Route::post('/textbooks/chapters/{textbookChapter}/import-mcq', [TextbookController::class, 'importMcq'])->name('textbooks.import-mcq');
    Route::post('/textbooks/chapters/{textbookChapter}/import-mcq-zip', [TextbookController::class, 'importMcqZip'])->name('textbooks.import-mcq-zip');
    Route::post('/textbooks/chapters/{textbookChapter}/replace-diagram', [TextbookController::class, 'replaceItemDiagram'])->name('textbooks.replace-diagram');
    Route::post('/textbooks/chapters/{textbookChapter}/remove-diagram', [TextbookController::class, 'removeItemDiagram'])->name('textbooks.remove-diagram');
    Route::post('/textbooks/chapters/{textbookChapter}/import-fill-blank', [TextbookController::class, 'importFillBlank'])->name('textbooks.import-fill-blank');
    Route::post('/textbooks/chapters/{textbookChapter}/publish-fill-blank-written', [TextbookController::class, 'publishFillBlankAndWritten'])->name('textbooks.publish-fill-blank-written');
    Route::get('/textbooks/chapters/{textbookChapter}/mcq-reference', [TextbookController::class, 'downloadMcqReference'])->name('textbooks.mcq-reference');
    Route::post('/textbooks/chapters/{textbookChapter}/reset-import', [TextbookController::class, 'resetImport'])->name('textbooks.reset-import');
    Route::get('/textbooks/chapters/{textbookChapter}/download', [TextbookController::class, 'download'])->name('textbooks.download');
    Route::post('/textbooks/chapters/{textbookChapter}/upload-pdf', [TextbookController::class, 'uploadPdf'])->name('textbooks.upload-pdf');
    Route::post('/textbooks/chapters/{textbookChapter}/change-book', [TextbookController::class, 'changeBook'])->name('textbooks.change-book');
    Route::post('/textbooks/chapters/{textbookChapter}/change-syllabus', [TextbookController::class, 'changeSyllabusChapter'])->name('textbooks.change-syllabus');

    Route::get('/formula-bank', [FormulaBankController::class, 'index'])->name('formula-bank.index');
    Route::get('/basics-drill', [BasicsDrillSettingsController::class, 'index'])->name('basics-drill.index');
    Route::put('/basics-drill/globals', [BasicsDrillSettingsController::class, 'updateGlobals'])->name('basics-drill.globals.update');
    Route::put('/basics-drill/classes/{gradeLevel}', [BasicsDrillSettingsController::class, 'update'])->name('basics-drill.update');
    Route::get('/formula-bank/classes/{grade}', [FormulaBankController::class, 'classShow'])->name('formula-bank.classes.show');
    Route::get('/formula-bank/topics/{topic}', [FormulaBankController::class, 'topicShow'])->name('formula-bank.topics.show');
    Route::post('/formula-bank/topics/{topic}/prompt', [FormulaBankController::class, 'topicPrompt'])->name('formula-bank.topics.prompt');
    Route::post('/formula-bank/topics/{topic}/sets', [FormulaBankController::class, 'storeSet'])->name('formula-bank.topics.sets.store');
    Route::post('/formula-bank/topics/{topic}/import', [FormulaBankController::class, 'importToTopic'])->name('formula-bank.topics.import');
    Route::post('/formula-bank/topics/{topic}/package', [FormulaBankController::class, 'packageTopic'])->name('formula-bank.topics.package');
    Route::get('/formula-bank/chapters/{chapter}', [FormulaBankController::class, 'chapterShow'])->name('formula-bank.chapters.show');
    Route::post('/formula-bank/chapters/{chapter}/prompt', [FormulaBankController::class, 'chapterPrompt'])->name('formula-bank.chapters.prompt');
    Route::post('/formula-bank/chapters/{chapter}/import', [FormulaBankController::class, 'importToChapter'])->name('formula-bank.chapters.import');
    Route::delete('/formula-bank/cards/{question}', [FormulaBankController::class, 'destroyCard'])->name('formula-bank.cards.destroy');
    Route::post('/formula-bank/sets/{worksheet}/import', [FormulaBankController::class, 'importToSet'])->name('formula-bank.sets.import');

    Route::get('/content-rate-cards', [ContentRateCardController::class, 'index'])->name('content-rate-cards.index');
    Route::post('/content-rate-cards', [ContentRateCardController::class, 'store'])->name('content-rate-cards.store');
    Route::put('/content-rate-cards/{contentRateCard}', [ContentRateCardController::class, 'update'])->name('content-rate-cards.update');

    Route::get('/content-tasks', [ContentUploadTaskController::class, 'index'])->name('content-tasks.index');
    Route::get('/content-tasks/create', [ContentUploadTaskController::class, 'create'])->name('content-tasks.create');
    Route::post('/content-tasks/assign-fill-blank-conversion', [ContentUploadTaskController::class, 'assignFillBlankConversion'])->name('content-tasks.assign-fill-blank-conversion');
    Route::post('/content-tasks', [ContentUploadTaskController::class, 'store'])->name('content-tasks.store');
    Route::post('/content-tasks/bulk-reassign', [ContentUploadTaskController::class, 'bulkReassign'])->name('content-tasks.bulk-reassign');
    Route::get('/content-tasks/{contentTask}', [ContentUploadTaskController::class, 'show'])->name('content-tasks.show');
    Route::post('/content-tasks/{contentTask}/reassign', [ContentUploadTaskController::class, 'reassign'])->name('content-tasks.reassign');
    Route::post('/content-tasks/{contentTask}/verification-question', [ContentUploadTaskController::class, 'saveVerificationQuestion'])->name('content-tasks.verification-question');
    Route::post('/content-tasks/{contentTask}/verification-batch', [ContentUploadTaskController::class, 'markVerificationBatch'])->name('content-tasks.verification-batch');
    Route::post('/content-tasks/{contentTask}/verification-diagram', [ContentUploadTaskController::class, 'uploadVerificationDiagram'])->name('content-tasks.verification-diagram');
    Route::post('/content-tasks/{contentTask}/verification-diagram/remove', [ContentUploadTaskController::class, 'removeVerificationDiagram'])->name('content-tasks.verification-diagram.remove');
    Route::post('/content-tasks/{contentTask}/return-for-reverification', [ContentUploadTaskController::class, 'returnForReverification'])->name('content-tasks.return-for-reverification');
    Route::post('/help-requests/{item}/return-to-uploader', [ContentUploadTaskController::class, 'returnHelpRequestQuestion'])->name('help-requests.return-to-uploader');
    Route::post('/content-tasks/{contentTask}/publish', [ContentUploadTaskController::class, 'publish'])->name('content-tasks.publish');
    Route::post('/content-tasks/{contentTask}/conversion-clear-rows', [ContentUploadTaskController::class, 'clearConversionRows'])->name('content-tasks.conversion-clear-rows');
    Route::post('/content-tasks/{contentTask}/conversion-clear-all', [ContentUploadTaskController::class, 'clearAllConversionRows'])->name('content-tasks.conversion-clear-all');
    Route::post('/content-tasks/{contentTask}/delete-requests/{deleteRequest}/approve', [ContentUploadTaskController::class, 'approveQuestionDelete'])->name('content-tasks.delete-requests.approve');
    Route::post('/content-tasks/{contentTask}/delete-requests/{deleteRequest}/reject', [ContentUploadTaskController::class, 'rejectQuestionDelete'])->name('content-tasks.delete-requests.reject');

    Route::get('/finance', [ContentFinanceController::class, 'index'])->name('finance.index');
    Route::post('/finance/payments', [ContentFinanceController::class, 'storePayment'])->name('finance.payments.store');

    Route::post('/practice-sets/{worksheet}/assign', [SetAssignmentController::class, 'store'])->name('practice-sets.assign');
    Route::post('/practice-sets/{worksheet}/assign-bulk', [SetAssignmentController::class, 'storeBulk'])->name('practice-sets.assign-bulk');
    Route::post('/practice-sets/{worksheet}/assign-students', [SetAssignmentController::class, 'storeStudents'])->name('practice-sets.assign-students');
    Route::get('/set-assignments/{assignment}', [SetAssignmentController::class, 'show'])->name('set-assignments.show');
    Route::delete('/set-assignments/{assignment}', [SetAssignmentController::class, 'destroy'])->name('set-assignments.destroy');
    Route::post('/set-assignments/{assignment}/reassign', [SetAssignmentController::class, 'reassign'])->name('set-assignments.reassign');

    Route::post('/exam-plans', [AdminExamPlanController::class, 'store'])->name('exam-plans.store');
    Route::put('/exam-plans/{examPlan}', [AdminExamPlanController::class, 'update'])->name('exam-plans.update');
    Route::delete('/exam-plans/{examPlan}', [AdminExamPlanController::class, 'destroy'])->name('exam-plans.destroy');
});

Route::middleware(['auth', 'verified'])->prefix('student')->name('student.')->group(function () {
    Route::get('/resources/formulas', [FormulaResourceController::class, 'index'])
        ->name('resources.formulas.index');
    Route::get('/resources/formulas/chapters/{syllabusChapter}', [FormulaResourceController::class, 'chapter'])
        ->name('resources.formulas.chapter');
});

Route::middleware(['auth', 'verified', 'formula.drill'])->prefix('student')->name('student.')->group(function () {
    Route::get('/formula-drill', [FormulaDrillController::class, 'show'])->name('formula-drill.show');
    Route::post('/formula-drill/items/{item}/answer', [FormulaDrillController::class, 'submitAnswer'])->name('formula-drill.answer');
    Route::post('/formula-drill/items/{item}/request-help', [FormulaDrillController::class, 'requestTeacherHelp'])->name('formula-drill.request-help');
    Route::get('/basics-drill', [BasicsDrillController::class, 'show'])->name('basics-drill.show');
    Route::post('/basics-drill/sessions/{session}/start', [BasicsDrillController::class, 'start'])->name('basics-drill.start');
    Route::post('/basics-drill/items/{item}/answer', [BasicsDrillController::class, 'submitAnswer'])->name('basics-drill.answer');
    Route::post('/basics-drill/items/{item}/mcq-answer', [BasicsDrillController::class, 'submitMcqAnswer'])->name('basics-drill.mcq-answer');
    Route::post('/basics-drill/items/{item}/acknowledge', [BasicsDrillController::class, 'acknowledge'])->name('basics-drill.acknowledge');
});

Route::middleware(['auth', 'verified', 'formula.drill', 'basics.drill'])->prefix('student')->name('student.')->group(function () {
    Route::post('/exam-plans', [StudentExamPlanController::class, 'store'])->name('exam-plans.store');
    Route::put('/exam-plans/{examPlan}', [StudentExamPlanController::class, 'update'])->name('exam-plans.update');
    Route::delete('/exam-plans/{examPlan}', [StudentExamPlanController::class, 'destroy'])->name('exam-plans.destroy');

    Route::put('/class-coverage/{syllabusChapter}', [ClassCoverageController::class, 'update'])
        ->name('class-coverage.update');
    Route::get('/school-study-plan', [ClassCoverageController::class, 'show'])
        ->name('school-study-plan.show');

    Route::post('/worksheets/{worksheet}/self-assign', [SelfAssignController::class, 'store'])->name('worksheets.self-assign');
    Route::post('/worksheets/{worksheet}/correction-practice', [PracticeCorrectionController::class, 'store'])->name('worksheets.correction-practice');
    Route::get('/assignments/{assignment}', [StudentPracticeSetController::class, 'showAssignment'])->name('assignments.show');
    Route::post('/assignments/{assignment}/start', [StudentPracticeSetController::class, 'startAttempt'])->name('assignments.start');
    Route::get('/written-assignments', [StudentWrittenAssignmentController::class, 'index'])->name('written-assignments.index');
    Route::get('/written-assignments/{assignment}', [StudentWrittenAssignmentController::class, 'show'])->name('written-assignments.show');
    Route::post('/written-assignments/{assignment}/upload', [StudentWrittenAssignmentController::class, 'storeUpload'])->name('written-assignments.upload');
    Route::get('/written-assignments/{assignment}/download', [StudentWrittenAssignmentController::class, 'download'])->name('written-assignments.download');
    Route::get('/attempts/{attempt}', [StudentPracticeSetController::class, 'showAttempt'])->name('attempts.show');
    Route::post('/attempts/{attempt}/guided/answer', [StudentPracticeSetController::class, 'guidedAnswer'])->name('attempts.guided.answer');
    Route::post('/attempts/{attempt}/guided/request-hint', [StudentPracticeSetController::class, 'guidedRequestHint'])->name('attempts.guided.request-hint');
    Route::post('/attempts/{attempt}/guided/give-up', [StudentPracticeSetController::class, 'guidedGiveUp'])->name('attempts.guided.give-up');
    Route::post('/attempts/{attempt}/timing/pause', [StudentPracticeSetController::class, 'pauseAttemptTiming'])->name('attempts.timing.pause');
    Route::post('/attempts/{attempt}/timing/resume', [StudentPracticeSetController::class, 'resumeAttemptTiming'])->name('attempts.timing.resume');
    Route::post('/attempts/{attempt}/timing/heartbeat', [StudentPracticeSetController::class, 'heartbeatAttemptTiming'])->name('attempts.timing.heartbeat');
    Route::post('/attempts/{attempt}/integrity/tab-leave', [StudentPracticeSetController::class, 'recordTabLeave'])->name('attempts.integrity.tab-leave');
    Route::post('/attempts/{attempt}/submit', [StudentPracticeSetController::class, 'submitAttempt'])->name('attempts.submit');
    Route::get('/attempts/{attempt}/result', [StudentPracticeSetController::class, 'result'])->name('attempts.result');
    Route::post('/attempts/{attempt}/practice-retry', [StudentPracticeSetController::class, 'practiceRetry'])->name('attempts.practice-retry');
    Route::get('/resolutions/history', [StudentPracticeSetController::class, 'resolutionHistory'])->name('resolutions.history');
    Route::get('/resolutions/clear-all', [StudentPracticeSetController::class, 'startClearAllQueue'])->name('resolutions.clear-all');
    Route::get('/resolutions/{item}', [StudentPracticeSetController::class, 'showResolution'])->name('resolutions.show');
    Route::post('/resolutions/{item}/answer', [StudentPracticeSetController::class, 'submitResolution'])->name('resolutions.answer');
});

Route::middleware(['auth', 'verified', 'content.uploader'])->prefix('content')->name('content.')->group(function () {
    Route::get('/chapters', [ChapterLibraryController::class, 'index'])->name('chapters.index');
    Route::get('/chapters/{textbookChapter}', [ChapterLibraryController::class, 'show'])->name('chapters.show');
    Route::post('/chapters/{textbookChapter}/append-mcq', [ChapterLibraryController::class, 'appendMcq'])->name('chapters.append-mcq');
    Route::post('/chapters/{textbookChapter}/append-mcq-zip', [ChapterLibraryController::class, 'appendMcqZip'])->name('chapters.append-mcq-zip');
    Route::post('/chapters/{textbookChapter}/delete-question', [ChapterLibraryController::class, 'destroyQuestion'])->name('chapters.delete-question');
    Route::post('/chapters/{textbookChapter}/request-delete', [ChapterLibraryController::class, 'requestDelete'])->name('chapters.request-delete');

    Route::get('/tasks', [ContentTaskController::class, 'index'])->name('tasks.index');
    Route::get('/tasks/{contentTask}', [ContentTaskController::class, 'show'])->name('tasks.show');
    Route::get('/tasks/{contentTask}/convert', [ContentTaskController::class, 'convert'])->name('tasks.convert');
    Route::post('/tasks/{contentTask}/agree', [ContentTaskController::class, 'agree'])->name('tasks.agree');
    Route::post('/tasks/{contentTask}/mark-uploaded', [ContentTaskController::class, 'markUploaded'])->name('tasks.mark-uploaded');
    Route::post('/tasks/{contentTask}/start-review', [ContentTaskController::class, 'startReview'])->name('tasks.start-review');
    Route::post('/corrections/{correction}/start', [ContentTaskController::class, 'startCorrection'])->name('corrections.start');
    Route::post('/tasks/{contentTask}/verification-check', [ContentTaskController::class, 'saveVerificationCheck'])->name('tasks.verification-check');
    Route::post('/tasks/{contentTask}/verification-question', [ContentTaskController::class, 'saveVerificationQuestion'])->name('tasks.verification-question');
    Route::post('/tasks/{contentTask}/verification-diagram', [ContentTaskController::class, 'uploadVerificationDiagram'])->name('tasks.verification-diagram');
    Route::post('/tasks/{contentTask}/verification-diagram/remove', [ContentTaskController::class, 'removeVerificationDiagram'])->name('tasks.verification-diagram.remove');
    Route::post('/tasks/{contentTask}/complete-verification', [ContentTaskController::class, 'completeVerification'])->name('tasks.complete-verification');
    Route::post('/tasks/{contentTask}/submit-for-publish', [ContentTaskController::class, 'submitForPublish'])->name('tasks.submit-for-publish');
    Route::post('/tasks/{contentTask}/convert-save', [ContentTaskController::class, 'saveConversionRow'])->name('tasks.convert-save');
    Route::post('/tasks/{contentTask}/convert-check', [ContentTaskController::class, 'checkConversionRow'])->name('tasks.convert-check');
    Route::post('/tasks/{contentTask}/convert-skip', [ContentTaskController::class, 'skipConversionRow'])->name('tasks.convert-skip');
    Route::post('/tasks/{contentTask}/ping-session', [ContentTaskController::class, 'pingSession'])->name('tasks.ping-session');

    Route::middleware('content.chapter')->group(function () {
        Route::get('/textbooks/chapters/{textbookChapter}', [TextbookController::class, 'show'])->name('textbooks.show');
        Route::post('/textbooks/chapters/{textbookChapter}/import-mcq', [TextbookController::class, 'importMcq'])->name('textbooks.import-mcq');
        Route::post('/textbooks/chapters/{textbookChapter}/import-mcq-zip', [TextbookController::class, 'importMcqZip'])->name('textbooks.import-mcq-zip');
        Route::post('/textbooks/chapters/{textbookChapter}/draft', [TextbookController::class, 'updateDraft'])->name('textbooks.draft');
        Route::post('/textbooks/chapters/{textbookChapter}/publish', [TextbookController::class, 'publish'])->name('textbooks.publish');
        Route::post('/textbooks/chapters/{textbookChapter}/import-fill-blank', [TextbookController::class, 'importFillBlank'])->name('textbooks.import-fill-blank');
        Route::post('/textbooks/chapters/{textbookChapter}/publish-fill-blank-written', [TextbookController::class, 'publishFillBlankAndWritten'])->name('textbooks.publish-fill-blank-written');
        Route::get('/textbooks/chapters/{textbookChapter}/mcq-reference', [TextbookController::class, 'downloadMcqReference'])->name('textbooks.mcq-reference');
        Route::post('/textbooks/chapters/{textbookChapter}/reset-import', [TextbookController::class, 'resetImport'])->name('textbooks.reset-import');
        Route::post('/textbooks/chapters/{textbookChapter}/replace-diagram', [TextbookController::class, 'replaceItemDiagram'])->name('textbooks.replace-diagram');
        Route::post('/textbooks/chapters/{textbookChapter}/remove-diagram', [TextbookController::class, 'removeItemDiagram'])->name('textbooks.remove-diagram');
        Route::get('/textbooks/chapters/{textbookChapter}/download', [TextbookController::class, 'download'])->name('textbooks.download');
        Route::post('/textbooks/chapters/{textbookChapter}/upload-pdf', [TextbookController::class, 'uploadPdf'])->name('textbooks.upload-pdf');
        Route::post('/textbooks/chapters/{textbookChapter}/change-book', [TextbookController::class, 'changeBook'])->name('textbooks.change-book');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/student-contacts', [StudentProfileController::class, 'updateContacts'])
        ->name('profile.student-contacts.update');
    Route::patch('/profile/weekly-report-emails', [StudentProfileController::class, 'updateWeeklyReportEmails'])
        ->name('profile.weekly-report-emails.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
