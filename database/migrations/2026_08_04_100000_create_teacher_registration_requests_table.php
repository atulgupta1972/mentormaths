<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_registration_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('mobile', 15);
            $table->string('password');
            $table->string('city')->nullable();
            $table->string('qualification')->nullable();
            $table->string('current_role')->nullable();
            $table->unsignedSmallInteger('years_of_experience')->default(0);
            $table->text('bio')->nullable();

            $table->json('board_ids')->nullable();
            $table->json('teaching_grade_level_ids')->nullable();
            $table->json('content_grade_level_ids')->nullable();

            $table->boolean('interested_in_content_creation')->default(false);
            $table->boolean('creates_mcq')->default(false);
            $table->boolean('creates_fill_blank')->default(false);
            $table->boolean('creates_written_sheets')->default(false);
            $table->boolean('creates_chapter_tests')->default(false);
            $table->boolean('creates_formula_drills')->default(false);
            $table->string('sample_work_url')->nullable();

            $table->boolean('interested_in_doubt_solving')->default(false);
            $table->unsignedTinyInteger('doubt_sessions_per_week')->nullable();
            $table->decimal('doubt_hours_per_week', 4, 1)->nullable();
            $table->unsignedInteger('proposed_hourly_rate_inr')->nullable();
            $table->json('preferred_days')->nullable();
            $table->string('preferred_time_slot')->nullable();
            $table->date('expected_start_date')->nullable();

            $table->unsignedInteger('counter_hourly_rate_inr')->nullable();
            $table->text('counter_offer_message')->nullable();
            $table->uuid('counter_offer_token')->nullable()->unique();
            $table->timestamp('counter_offer_sent_at')->nullable();
            $table->timestamp('offer_responded_at')->nullable();
            $table->string('offer_response', 20)->nullable();

            $table->boolean('teaches_english_medium')->default(true);
            $table->boolean('teaches_hindi_medium')->default(false);
            $table->string('referral_source')->nullable();
            $table->boolean('agreed_to_terms')->default(false);
            $table->timestamp('agreed_at')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 30)->default('pending');
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_registration_requests');
    }
};
