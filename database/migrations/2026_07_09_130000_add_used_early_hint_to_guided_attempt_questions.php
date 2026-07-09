<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guided_attempt_questions', function (Blueprint $table) {
            $table->boolean('used_early_hint')->default(false)->after('wrong_before_explanation');
        });
    }

    public function down(): void
    {
        Schema::table('guided_attempt_questions', function (Blueprint $table) {
            $table->dropColumn('used_early_hint');
        });
    }
};
