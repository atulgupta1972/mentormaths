<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guided_attempt_questions', function (Blueprint $table) {
            $table->foreignId('first_wrong_option_id')->nullable()->after('wrong_before_explanation')->constrained('question_options')->nullOnDelete();
            $table->string('first_wrong_answer_text', 64)->nullable()->after('first_wrong_option_id');
            $table->foreignId('second_wrong_option_id')->nullable()->after('first_wrong_answer_text')->constrained('question_options')->nullOnDelete();
            $table->string('second_wrong_answer_text', 64)->nullable()->after('second_wrong_option_id');
        });
    }

    public function down(): void
    {
        Schema::table('guided_attempt_questions', function (Blueprint $table) {
            $table->dropForeign(['first_wrong_option_id']);
            $table->dropForeign(['second_wrong_option_id']);
            $table->dropColumn([
                'first_wrong_option_id',
                'first_wrong_answer_text',
                'second_wrong_option_id',
                'second_wrong_answer_text',
            ]);
        });
    }
};
