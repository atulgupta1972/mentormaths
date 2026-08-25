<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_verification_checks', function (Blueprint $table) {
            $table->boolean('skipped')->default(false)->after('diagram_note');
            $table->string('skip_reason', 500)->nullable()->after('skipped');
            $table->timestamp('skipped_at')->nullable()->after('skip_reason');
        });
    }

    public function down(): void
    {
        Schema::table('content_verification_checks', function (Blueprint $table) {
            $table->dropColumn(['skipped', 'skip_reason', 'skipped_at']);
        });
    }
};
