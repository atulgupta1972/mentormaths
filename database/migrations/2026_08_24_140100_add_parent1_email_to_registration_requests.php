<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('registration_requests', 'parent1_email')) {
                $table->string('parent1_email')->nullable()->after('parent1_mobile');
            }
        });
    }

    public function down(): void
    {
        Schema::table('registration_requests', function (Blueprint $table) {
            if (Schema::hasColumn('registration_requests', 'parent1_email')) {
                $table->dropColumn('parent1_email');
            }
        });
    }
};
