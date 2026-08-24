<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('type', 20); // student|mentor
            $table->string('status', 20)->default('active'); // active|expired|revoked
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coaching_class_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coaching_class_teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->dateTime('generated_at');
            $table->dateTime('expires_at');
            $table->dateTime('extended_at')->nullable();
            $table->unsignedInteger('extension_days_total')->default(0);
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_codes');
    }
};
