<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_registration_requests', function (Blueprint $table) {
            $table->boolean('interested_in_book_content_upload')->default(false)->after('sample_work_url');
            $table->unsignedInteger('proposed_rate_per_set_inr')->nullable()->after('interested_in_book_content_upload');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_registration_requests', function (Blueprint $table) {
            $table->dropColumn(['interested_in_book_content_upload', 'proposed_rate_per_set_inr']);
        });
    }
};
