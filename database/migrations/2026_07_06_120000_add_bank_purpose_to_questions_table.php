<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('bank_purpose', 20)->nullable()->after('source');
            $table->index(['syllabus_topic_id', 'bank_purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['syllabus_topic_id', 'bank_purpose']);
            $table->dropColumn('bank_purpose');
        });
    }
};
