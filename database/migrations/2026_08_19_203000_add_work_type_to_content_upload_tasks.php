<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('content_upload_tasks', function (Blueprint $table) {
                $table->dropForeign(['textbook_chapter_id']);
            });
        }

        Schema::table('content_upload_tasks', function (Blueprint $table) {
            $table->dropUnique(['textbook_chapter_id']);
        });

        Schema::table('content_upload_tasks', function (Blueprint $table) {
            $table->string('work_type', 40)->default('mcq_upload')->after('textbook_chapter_id');
            $table->unique(['textbook_chapter_id', 'work_type']);
        });

        if ($driver === 'mysql') {
            Schema::table('content_upload_tasks', function (Blueprint $table) {
                $table->foreign('textbook_chapter_id')
                    ->references('id')
                    ->on('textbook_chapters')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('content_upload_tasks', function (Blueprint $table) {
                $table->dropForeign(['textbook_chapter_id']);
            });
        }

        Schema::table('content_upload_tasks', function (Blueprint $table) {
            $table->dropUnique(['textbook_chapter_id', 'work_type']);
            $table->dropColumn('work_type');
        });

        Schema::table('content_upload_tasks', function (Blueprint $table) {
            $table->unique('textbook_chapter_id');
        });

        if ($driver === 'mysql') {
            Schema::table('content_upload_tasks', function (Blueprint $table) {
                $table->foreign('textbook_chapter_id')
                    ->references('id')
                    ->on('textbook_chapters')
                    ->cascadeOnDelete();
            });
        }
    }
};
