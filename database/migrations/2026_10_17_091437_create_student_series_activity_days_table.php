<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_series_activity_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('activity_date');
            $table->string('kind', 32); // algebra | mensuration
            $table->string('pack_key')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'activity_date']);
            $table->index(['student_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_series_activity_days');
    }
};
