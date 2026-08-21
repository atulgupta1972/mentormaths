<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coaching_classes', function (Blueprint $table) {
            $table->string('pin_code', 10)->nullable()->after('phone');
            $table->string('state', 100)->nullable()->after('pin_code');
        });
    }

    public function down(): void
    {
        Schema::table('coaching_classes', function (Blueprint $table) {
            $table->dropColumn(['pin_code', 'state']);
        });
    }
};
