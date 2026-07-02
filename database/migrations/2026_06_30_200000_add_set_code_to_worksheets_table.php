<?php

use App\Services\PracticeSetCodeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->string('set_code', 20)->nullable()->after('set_number');
            $table->unique('set_code');
        });

        app(PracticeSetCodeService::class)->backfillAll();
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->dropUnique(['set_code']);
            $table->dropColumn('set_code');
        });
    }
};
