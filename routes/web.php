<?php

use App\Http\Controllers\Admin\AcademicYearController;
use App\Http\Controllers\Admin\ChapterHeadController;
use App\Http\Controllers\Admin\ChapterPracticeSetController;
use App\Http\Controllers\Admin\ClassHubController;
use App\Http\Controllers\Admin\GradeContextController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\PracticeSetController;
use App\Http\Controllers\Admin\PracticeSetTopicController;
use App\Http\Controllers\Admin\QuestionController;
use App\Http\Controllers\Admin\QuestionHubController;
use App\Http\Controllers\Admin\RegistrationRequestController as AdminRegistrationRequestController;
use App\Http\Controllers\Admin\SetAssignmentController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\SyllabusVersionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationRequestController;
use App\Http\Controllers\Student\PracticeSetController as StudentPracticeSetController;
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
    Route::patch('/students/{student}/contacts', [StudentController::class, 'updateContacts'])
        ->name('students.contacts.update');
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
    Route::put('/syllabus/{syllabusVersion}/rows', [SyllabusVersionController::class, 'updateRows'])
        ->name('syllabus.rows.update');
    Route::post('/syllabus/{syllabusVersion}/carry-forward', [SyllabusVersionController::class, 'carryForward'])
        ->name('syllabus.carry-forward');

    Route::get('/chapter-heads', [ChapterHeadController::class, 'index'])->name('chapter-heads.index');
    Route::post('/chapter-heads', [ChapterHeadController::class, 'store'])->name('chapter-heads.store');
    Route::post('/chapter-heads/quick', [ChapterHeadController::class, 'storeQuick'])->name('chapter-heads.quick-store');
    Route::get('/chapter-heads/{chapterHead}', [ChapterHeadController::class, 'show'])->name('chapter-heads.show');
    Route::put('/chapter-heads/{chapterHead}', [ChapterHeadController::class, 'update'])->name('chapter-heads.update');
    Route::delete('/chapter-heads/{chapterHead}', [ChapterHeadController::class, 'destroy'])->name('chapter-heads.destroy');

    Route::get('/questions/create', [QuestionController::class, 'create'])->name('questions.create');
    Route::post('/questions/preview-import', [QuestionController::class, 'previewImport'])->name('questions.preview-import');
    Route::post('/questions/extract-pdf', [QuestionController::class, 'extractPdf'])->name('questions.extract-pdf');
    Route::post('/questions/extract-pdf-worksheet', [QuestionController::class, 'extractPdfWorksheet'])->name('questions.extract-pdf-worksheet');
    Route::post('/questions/bulk-store', [QuestionController::class, 'storeBulk'])->name('questions.bulk-store');
    Route::get('/questions/{question}/edit', [QuestionController::class, 'edit'])->name('questions.edit');
    Route::put('/questions/{question}', [QuestionController::class, 'update'])->name('questions.update');
    Route::delete('/questions/{question}', [QuestionController::class, 'destroy'])->name('questions.destroy');

    Route::post('/practice-sets/from-topic/{topic}', [PracticeSetController::class, 'storeFromTopic'])
        ->name('practice-sets.from-topic');
    Route::get('/practice-sets/chapters/{chapter}', [ChapterPracticeSetController::class, 'show'])->name('practice-sets.chapters.show');
    Route::get('/practice-sets/chapters/{chapter}/create', [ChapterPracticeSetController::class, 'create'])->name('practice-sets.chapters.create');
    Route::post('/practice-sets/chapters/{chapter}', [ChapterPracticeSetController::class, 'store'])->name('practice-sets.chapters.store');
    Route::post('/practice-sets/chapters/{chapter}/auto-mix', [ChapterPracticeSetController::class, 'storeAutoMix'])->name('practice-sets.chapters.auto-mix');
    Route::get('/practice-sets', [PracticeSetController::class, 'index'])->name('practice-sets.index');
    Route::get('/practice-sets/create', [PracticeSetController::class, 'create'])->name('practice-sets.create');
    Route::post('/practice-sets', [PracticeSetController::class, 'store'])->name('practice-sets.store');
    Route::get('/practice-sets/topics/{topic}', [PracticeSetTopicController::class, 'show'])->name('practice-sets.topics.show');
    Route::get('/practice-sets/{worksheet}', [PracticeSetController::class, 'show'])->name('practice-sets.show');
    Route::delete('/practice-sets/{worksheet}', [PracticeSetController::class, 'destroy'])->name('practice-sets.destroy');

    Route::post('/practice-sets/{worksheet}/assign', [SetAssignmentController::class, 'store'])->name('practice-sets.assign');
    Route::post('/practice-sets/{worksheet}/assign-bulk', [SetAssignmentController::class, 'storeBulk'])->name('practice-sets.assign-bulk');
    Route::get('/set-assignments/{assignment}', [SetAssignmentController::class, 'show'])->name('set-assignments.show');
    Route::post('/set-assignments/{assignment}/reassign', [SetAssignmentController::class, 'reassign'])->name('set-assignments.reassign');
});

Route::middleware(['auth', 'verified'])->prefix('student')->name('student.')->group(function () {
    Route::get('/assignments/{assignment}', [StudentPracticeSetController::class, 'showAssignment'])->name('assignments.show');
    Route::post('/assignments/{assignment}/start', [StudentPracticeSetController::class, 'startAttempt'])->name('assignments.start');
    Route::get('/attempts/{attempt}', [StudentPracticeSetController::class, 'showAttempt'])->name('attempts.show');
    Route::post('/attempts/{attempt}/submit', [StudentPracticeSetController::class, 'submitAttempt'])->name('attempts.submit');
    Route::get('/attempts/{attempt}/result', [StudentPracticeSetController::class, 'result'])->name('attempts.result');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/student-contacts', [StudentProfileController::class, 'updateContacts'])
        ->name('profile.student-contacts.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
