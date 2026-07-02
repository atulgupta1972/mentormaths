<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('set_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worksheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('assigned');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['student_enrollment_id', 'worksheet_id']);
            $table->index(['worksheet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('set_assignments');
    }
};
