<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_registration_requests', function (Blueprint $table) {
            $table->string('state')->nullable()->after('city');
            $table->string('country', 80)->default('India')->after('state');
            $table->string('regional_language')->nullable()->after('teaches_hindi_medium');
            $table->uuid('profile_completion_token')->nullable()->unique()->after('counter_offer_token');
            $table->timestamp('profile_completion_requested_at')->nullable()->after('profile_completion_token');
            $table->text('profile_completion_message')->nullable()->after('profile_completion_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_registration_requests', function (Blueprint $table) {
            $table->dropColumn([
                'state',
                'country',
                'regional_language',
                'profile_completion_token',
                'profile_completion_requested_at',
                'profile_completion_message',
            ]);
        });
    }
};
