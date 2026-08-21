<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coaching_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('coaching_class_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coaching_class_id')->constrained('coaching_classes')->cascadeOnDelete();
            $table->string('name');
            $table->string('mobile', 20);
            $table->string('email')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['coaching_class_id', 'is_active']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('enrollment_source', 20)->default('individual')->after('school_name');
            $table->foreignId('coaching_class_id')->nullable()->after('enrollment_source')
                ->constrained('coaching_classes')->nullOnDelete();
            $table->foreignId('coaching_class_teacher_id')->nullable()->after('coaching_class_id')
                ->constrained('coaching_class_teachers')->nullOnDelete();
            $table->string('mentor_type', 30)->nullable()->after('coaching_class_teacher_id');
            $table->foreignId('mentor_user_id')->nullable()->after('mentor_type')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('registration_requests', function (Blueprint $table) {
            $table->string('enrollment_source', 20)->default('individual')->after('school_name');
            $table->foreignId('coaching_class_id')->nullable()->after('enrollment_source')
                ->constrained('coaching_classes')->nullOnDelete();
            $table->foreignId('coaching_class_teacher_id')->nullable()->after('coaching_class_id')
                ->constrained('coaching_class_teachers')->nullOnDelete();
        });

        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->string('enrollment_source', 20)->nullable()->after('school_name');
            $table->foreignId('coaching_class_id')->nullable()->after('enrollment_source')
                ->constrained('coaching_classes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coaching_class_id');
            $table->dropColumn('enrollment_source');
        });

        Schema::table('registration_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coaching_class_teacher_id');
            $table->dropConstrainedForeignId('coaching_class_id');
            $table->dropColumn('enrollment_source');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mentor_user_id');
            $table->dropColumn('mentor_type');
            $table->dropConstrainedForeignId('coaching_class_teacher_id');
            $table->dropConstrainedForeignId('coaching_class_id');
            $table->dropColumn('enrollment_source');
        });

        Schema::dropIfExists('coaching_class_teachers');
        Schema::dropIfExists('coaching_classes');
    }
};
