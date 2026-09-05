<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbook_chapters', function (Blueprint $table) {
            $table->json('concept_path_items')->nullable()->after('mcq_set_plan');
            $table->string('concept_path_status', 32)->nullable()->after('concept_path_items');
            $table->timestamp('concept_path_approved_at')->nullable()->after('concept_path_status');
            $table->foreignId('concept_path_approved_by')->nullable()->after('concept_path_approved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('textbook_chapters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('concept_path_approved_by');
            $table->dropColumn([
                'concept_path_items',
                'concept_path_status',
                'concept_path_approved_at',
            ]);
        });
    }
};
