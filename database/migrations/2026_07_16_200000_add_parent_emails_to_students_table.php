<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('parent1_email')->nullable()->after('parent1_mobile');
            $table->string('parent2_email')->nullable()->after('parent2_mobile');
            $table->boolean('notify_contact_email')->default(true)->after('email');
            $table->boolean('notify_login_email')->default(true)->after('notify_contact_email');
            $table->boolean('notify_parent1_email')->default(true)->after('notify_parent1_mobile');
            $table->boolean('notify_parent2_email')->default(false)->after('notify_parent2_mobile');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'parent1_email',
                'parent2_email',
                'notify_contact_email',
                'notify_login_email',
                'notify_parent1_email',
                'notify_parent2_email',
            ]);
        });
    }
};
