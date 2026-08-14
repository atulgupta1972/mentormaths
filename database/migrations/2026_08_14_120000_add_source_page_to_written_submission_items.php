<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('written_submission_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('source_page')->nullable()->after('needs_review');
            $table->string('source_image_path')->nullable()->after('source_page');
        });
    }

    public function down(): void
    {
        Schema::table('written_submission_items', function (Blueprint $table) {
            $table->dropColumn(['source_page', 'source_image_path']);
        });
    }
};
