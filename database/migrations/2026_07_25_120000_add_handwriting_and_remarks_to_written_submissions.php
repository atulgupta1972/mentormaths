<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('written_submissions', function (Blueprint $table) {
            $table->string('handwriting_rating', 32)->nullable()->after('ai_summary');
            $table->text('teacher_remarks')->nullable()->after('handwriting_rating');
        });
    }

    public function down(): void
    {
        Schema::table('written_submissions', function (Blueprint $table) {
            $table->dropColumn(['handwriting_rating', 'teacher_remarks']);
        });
    }
};
