<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_blank_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('answer_format', 20);
            $table->string('correct_answer', 64);
            $table->unsignedTinyInteger('decimal_places')->nullable();
            $table->timestamps();

            $table->unique('question_id');
        });

        Schema::table('guided_attempt_questions', function (Blueprint $table) {
            $table->string('final_answer_text', 64)->nullable()->after('final_option_id');
        });

        Schema::table('set_attempt_answers', function (Blueprint $table) {
            $table->string('answer_text', 64)->nullable()->after('question_option_id');
        });
    }

    public function down(): void
    {
        Schema::table('set_attempt_answers', function (Blueprint $table) {
            $table->dropColumn('answer_text');
        });

        Schema::table('guided_attempt_questions', function (Blueprint $table) {
            $table->dropColumn('final_answer_text');
        });

        Schema::dropIfExists('question_blank_answers');
    }
};
