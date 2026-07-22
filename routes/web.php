<?php

use App\Http\Controllers\Admin\AcademicYearController;
use App\Http\Controllers\Admin\ChapterHeadController;
use App\Http\Controllers\Admin\ChapterPracticeSetController;
use App\Http\Controllers\Admin\CatchUpSetController;
use App\Http\Controllers\Admin\ClassAssignmentController;
use App\Http\Controllers\Admin\ClassHubController;
use App\Http\Controllers\Admin\ExamPlanController as AdminExamPlanController;
use App\Http\Controllers\Admin\GradeContextController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\PracticeSetController;
use App\Http\Controllers\Admin\PracticeSetTopicController;
use App\Http\Controllers\Admin\QuestionAuditController;
use App\Http\Controllers\Admin\QuestionController;
use App\Http\Controllers\Admin\QuestionHubController;
use App\Http\Controllers\Admin\RegistrationRequestController as AdminRegistrationRequestController;
use App\Http\Controllers\Admin\SetAssignmentController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\SyllabusVersionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WrittenSheetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationRequestController;
use App\Http\Controllers\Student\ExamPlanController as StudentExamPlanController;
use App\Http\Controllers\Student\PracticeSetController as StudentPracticeSetController;
use App\Http\Controllers\Student\WrittenAssignmentController as StudentWrittenAssignmentController;
use App\Http\Controllers\StudentProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('/register/request', [RegistrationRequestController::class, 'create'])
    ->name('registration.create');
Route::post('/register/request', [RegistrationRequestController::class, 'store'])
    ->name('registration.store');
Route::get('/register/thank-you', [RegistrationRequestController::class, 'thankYou'])
    ->name('registration.thank-you');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/classes', [ClassHubController::class, 'index'])->name('classes.index');
    Route::get('/classes/{gradeLevel}', [ClassHubController::class, 'show'])->name('classes.show');
    Route::patch('/classes/{gradeLevel}/attempt-protection', [ClassHubController::class, 'updateAttemptProtection'])
        ->name('classes.attempt-protection.update');
    Route::get('/classes/{gradeLevel}/assign', [ClassAssignmentController::class, 'show'])->name('classes.assign');
    Route::post('/classes/{gradeLevel}/assign', [ClassAssignmentController::class, 'store'])->name('classes.assign.store');

    Route::get('/syllabus/{syllabusVersion}', [SyllabusVersionController::class, 'show'])->name('syllabus.show');

    Route::get('/questions', [QuestionHubController::class, 'classes'])->name('questions.index');
    Route::get('/questions/classes/{gradeLevel}', [QuestionHubController::class, 'chapters'])->name('questions.classes.show');
    Route::get('/questions/chapters/{chapter}', [QuestionHubController::class, 'topics'])->name('questions.chapters.show');
    Route::get('/questions/sets/{worksheet}', [QuestionHubController::class, 'setQuestions'])->name('questions.sets.show');
    Route::get('/questions/topics/{topic}', [QuestionController::class, 'topicIndex'])->name('questions.topics.show');
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

    Route::get('/students', [StudentController::class, 'index'])
        ->name('students.index');
    Route::get('/students/{student}', [StudentController::class, 'show'])
        ->name('students.show');
    Route::post('/students/{student}/toggle-active', [StudentController::class, 'toggleActive'])
        ->name('students.toggle-active');
    Route::delete('/students/{student}', [StudentController::class, 'destroy'])
        ->name('students.destroy');
    Route::patch('/students/{student}/contacts', [StudentController::class, 'updateContacts'])
        ->name('students.contacts.update');
    Route::get('/students/{student}/progress-summary-preview', [StudentController::class, 'progressSummaryPreview'])
        ->name('students.progress-summary-preview');
    Route::post('/students/{student}/send-progress-summary', [StudentController::class, 'sendProgressSummary'])
        ->name('students.send-progress-summary');
    Route::get('/students/{student}/progress-summary-pdf', [StudentController::class, 'progressSummaryPdf'])
        ->name('students.progress-summary-pdf');
    Route::patch('/students/{student}/emails', [StudentController::class, 'updateEmails'])
        ->name('students.emails.update');
    Route::post('/students/{student}/promote', [StudentController::class, 'promote'])
        ->name('students.promote');
    Route::post('/students/bulk-promote', [StudentController::class, 'bulkPromote'])
        ->name('students.bulk-promote');

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
    Route::post('/written-sheets/{worksheet}/verify', [WrittenSheetController::class, 'verify'])->name('written-sheets.verify');
    Route::post('/written-sheets/{worksheet}/reject', [WrittenSheetController::class, 'reject'])->name('written-sheets.reject');
    Route::get('/written-sheets/{worksheet}/download', [WrittenSheetController::class, 'download'])->name('written-sheets.download');

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
    Route::post('/exam-plans', [StudentExamPlanController::class, 'store'])->name('exam-plans.store');
    Route::put('/exam-plans/{examPlan}', [StudentExamPlanController::class, 'update'])->name('exam-plans.update');
    Route::delete('/exam-plans/{examPlan}', [StudentExamPlanController::class, 'destroy'])->name('exam-plans.destroy');

    Route::get('/assignments/{assignment}', [StudentPracticeSetController::class, 'showAssignment'])->name('assignments.show');
    Route::post('/assignments/{assignment}/start', [StudentPracticeSetController::class, 'startAttempt'])->name('assignments.start');
    Route::get('/written-assignments/{assignment}', [StudentWrittenAssignmentController::class, 'show'])->name('written-assignments.show');
    Route::post('/written-assignments/{assignment}/upload', [StudentWrittenAssignmentController::class, 'storeUpload'])->name('written-assignments.upload');
    Route::get('/written-assignments/{assignment}/download', [StudentWrittenAssignmentController::class, 'download'])->name('written-assignments.download');
    Route::get('/attempts/{attempt}', [StudentPracticeSetController::class, 'showAttempt'])->name('attempts.show');
    Route::post('/attempts/{attempt}/guided/answer', [StudentPracticeSetController::class, 'guidedAnswer'])->name('attempts.guided.answer');
    Route::post('/attempts/{attempt}/guided/request-hint', [StudentPracticeSetController::class, 'guidedRequestHint'])->name('attempts.guided.request-hint');
    Route::post('/attempts/{attempt}/guided/give-up', [StudentPracticeSetController::class, 'guidedGiveUp'])->name('attempts.guided.give-up');
    Route::post('/attempts/{attempt}/timing/pause', [StudentPracticeSetController::class, 'pauseAttemptTiming'])->name('attempts.timing.pause');
    Route::post('/attempts/{attempt}/integrity/tab-leave', [StudentPracticeSetController::class, 'recordTabLeave'])->name('attempts.integrity.tab-leave');
    Route::post('/attempts/{attempt}/submit', [StudentPracticeSetController::class, 'submitAttempt'])->name('attempts.submit');
    Route::get('/attempts/{attempt}/result', [StudentPracticeSetController::class, 'result'])->name('attempts.result');
    Route::post('/attempts/{attempt}/practice-retry', [StudentPracticeSetController::class, 'practiceRetry'])->name('attempts.practice-retry');
    Route::get('/resolutions/history', [StudentPracticeSetController::class, 'resolutionHistory'])->name('resolutions.history');
    Route::get('/resolutions/clear-all', [StudentPracticeSetController::class, 'startClearAllQueue'])->name('resolutions.clear-all');
    Route::get('/resolutions/{item}', [StudentPracticeSetController::class, 'showResolution'])->name('resolutions.show');
    Route::post('/resolutions/{item}/answer', [StudentPracticeSetController::class, 'submitResolution'])->name('resolutions.answer');
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
