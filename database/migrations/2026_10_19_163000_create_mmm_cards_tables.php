<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mmm_cards', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title');
            $table->string('heading', 120);
            $table->string('kind', 40)->default('table_fill');
            $table->string('status', 20)->default('draft');
            $table->string('accent', 20)->default('teal');
            $table->text('method_blurb')->nullable();
            $table->json('table_schema');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mmm_card_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mmm_card_id')->constrained('mmm_cards')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('difficulty', 20)->default('demo');
            $table->boolean('is_demo')->default(false);
            $table->text('question_text');
            $table->json('answers');
            $table->timestamps();

            $table->index(['mmm_card_id', 'sort_order']);
        });

        Schema::create('mmm_card_syllabus_chapter', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mmm_card_id')->constrained('mmm_cards')->cascadeOnDelete();
            $table->foreignId('syllabus_chapter_id')->constrained('syllabus_chapters')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['mmm_card_id', 'syllabus_chapter_id']);
        });

        Schema::create('student_mmm_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mmm_card_id')->constrained('mmm_cards')->cascadeOnDelete();
            $table->unsignedSmallInteger('exercises_completed')->default(0);
            $table->string('status', 20)->default('not_started');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'mmm_card_id']);
        });

        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'marks-scoring-table',
            'title' => 'Marks Scoring table',
            'heading' => 'Marks Scoring table',
            'kind' => 'table_fill',
            'status' => 'published',
            'accent' => 'teal',
            'method_blurb' => 'For marks-type word problems, first make a table: Correct / Wrong rows, then Marks × No. of questions = Total. Add the totals to get the final score.',
            'table_schema' => json_encode([
                'columns' => [
                    ['key' => 'label', 'label' => 'Answer', 'fixed' => true],
                    ['key' => 'marks', 'label' => 'Marks'],
                    ['key' => 'count', 'label' => 'No. of Ques'],
                    ['key' => 'total', 'label' => 'Total'],
                ],
                'rows' => [
                    ['key' => 'correct', 'label' => 'Correct'],
                    ['key' => 'wrong', 'label' => 'Wrong'],
                ],
                'show_grand_total' => true,
                'grand_total_label' => 'Total marks',
            ]),
            'sort_order' => 1,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('mmm_card_exercises')->insert([
            'mmm_card_id' => $cardId,
            'sort_order' => 1,
            'difficulty' => 'demo',
            'is_demo' => true,
            'question_text' => 'A student gets +2 marks for each correct answer and −1 mark for each wrong answer. After attempting 10 correct and 5 wrong sums, how many marks does the student get?',
            'answers' => json_encode([
                'correct' => ['marks' => '2', 'count' => '10', 'total' => '20'],
                'wrong' => ['marks' => '-1', 'count' => '5', 'total' => '-5'],
                'grand_total' => '15',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('student_mmm_progress');
        Schema::dropIfExists('mmm_card_syllabus_chapter');
        Schema::dropIfExists('mmm_card_exercises');
        Schema::dropIfExists('mmm_cards');
    }
};
