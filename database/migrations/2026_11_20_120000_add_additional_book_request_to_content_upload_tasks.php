<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_upload_tasks', function (Blueprint $table) {
            $table->string('review_decision', 32)->nullable()->after('admin_notes');
            $table->text('review_decision_note')->nullable()->after('review_decision');
            $table->foreignId('parent_task_id')
                ->nullable()
                ->after('review_decision_note')
                ->constrained('content_upload_tasks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_upload_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_task_id');
            $table->dropColumn(['review_decision', 'review_decision_note']);
        });
    }
};
