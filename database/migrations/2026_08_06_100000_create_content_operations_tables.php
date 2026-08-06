<?php

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_rate_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grade_level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('syllabus_chapter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('content_type', 64)->default('textbook_chapter_mcq');
            $table->unsignedInteger('default_amount_inr');
            $table->text('admin_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['board_id', 'grade_level_id', 'syllabus_chapter_id', 'content_type'],
                'content_rate_cards_scope_unique',
            );
        });

        Schema::create('content_upload_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('textbook_chapter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 32)->default('pending_agreement');
            $table->unsignedInteger('offered_amount_inr');
            $table->unsignedInteger('agreed_amount_inr')->nullable();
            $table->timestamp('agreed_at')->nullable();
            $table->text('duplicate_override_reason')->nullable();
            $table->foreignId('duplicate_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->unique('textbook_chapter_id');
            $table->index(['assigned_to_user_id', 'status']);
            $table->index('status');
        });

        Schema::create('content_work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_upload_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('idle_paused_seconds')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['content_upload_task_id', 'user_id']);
        });

        Schema::create('content_verification_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_upload_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('in_progress');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['content_upload_task_id', 'status']);
        });

        Schema::create('content_verification_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_verification_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->boolean('check_text')->default(false);
            $table->boolean('check_options')->default(false);
            $table->boolean('check_correct')->default(false);
            $table->boolean('check_hint')->default(false);
            $table->boolean('check_explanation')->default(false);
            $table->boolean('check_difficulty')->default(false);
            $table->boolean('check_diagram')->default(false);
            $table->string('diagram_note', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['content_verification_run_id', 'question_id'], 'content_verification_checks_unique');
        });

        Schema::create('student_mentor_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mentor_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'is_active']);
            $table->index(['mentor_user_id', 'is_active']);
        });

        foreach ([
            ['code' => User::ROLE_MENTOR, 'name' => 'Mentor', 'sort_order' => 5],
            ['code' => User::ROLE_CONTENT_UPLOADER, 'name' => 'Content uploader', 'sort_order' => 6],
        ] as $group) {
            Group::query()->updateOrCreate(
                ['code' => $group['code']],
                ['name' => $group['name'], 'sort_order' => $group['sort_order'], 'is_active' => true],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_mentor_assignments');
        Schema::dropIfExists('content_verification_checks');
        Schema::dropIfExists('content_verification_runs');
        Schema::dropIfExists('content_work_sessions');
        Schema::dropIfExists('content_upload_tasks');
        Schema::dropIfExists('content_rate_cards');

        Group::query()->whereIn('code', [
            User::ROLE_MENTOR,
            User::ROLE_CONTENT_UPLOADER,
        ])->delete();
    }
};
