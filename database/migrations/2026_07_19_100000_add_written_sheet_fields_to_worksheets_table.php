<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->string('delivery_mode', 20)->default('online')->after('status');
            $table->string('written_status', 30)->nullable()->after('delivery_mode');
            $table->string('written_pdf_path')->nullable()->after('written_status');
            $table->timestamp('written_verified_at')->nullable()->after('written_pdf_path');
            $table->foreignId('written_verified_by')->nullable()->after('written_verified_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('written_verified_by');
            $table->dropColumn([
                'delivery_mode',
                'written_status',
                'written_pdf_path',
                'written_verified_at',
            ]);
        });
    }
};
