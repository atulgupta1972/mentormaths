<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->boolean('notify_student_mobile')->default(false)->after('email');
            $table->boolean('notify_parent1_mobile')->default(true)->after('notify_student_mobile');
            $table->boolean('notify_parent2_mobile')->default(false)->after('notify_parent1_mobile');
        });

        Schema::table('registration_requests', function (Blueprint $table) {
            $table->boolean('notify_student_mobile')->default(false)->after('email');
            $table->boolean('notify_parent1_mobile')->default(true)->after('notify_student_mobile');
            $table->boolean('notify_parent2_mobile')->default(false)->after('notify_parent1_mobile');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'notify_student_mobile',
                'notify_parent1_mobile',
                'notify_parent2_mobile',
            ]);
        });

        Schema::table('registration_requests', function (Blueprint $table) {
            $table->dropColumn([
                'notify_student_mobile',
                'notify_parent1_mobile',
                'notify_parent2_mobile',
            ]);
        });
    }
};
