<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_uploader_payments', function (Blueprint $table) {
            $table->uuid('batch_id')->nullable()->after('content_upload_task_id');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('content_uploader_payments', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
