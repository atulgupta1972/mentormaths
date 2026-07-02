<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->unsignedSmallInteger('set_number')->default(1)->after('title');
            $table->string('tier', 20)->default('starter')->after('set_number');
        });

        $rows = DB::table('worksheets')->orderBy('id')->get();
        $perTopic = [];

        foreach ($rows as $row) {
            $topicId = $row->syllabus_topic_id ?? 0;
            $perTopic[$topicId] = ($perTopic[$topicId] ?? 0) + 1;

            DB::table('worksheets')->where('id', $row->id)->update([
                'set_number' => $perTopic[$topicId],
                'tier' => 'starter',
            ]);
        }

        Schema::table('worksheets', function (Blueprint $table) {
            $table->unique(['syllabus_topic_id', 'set_number']);
        });
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->dropUnique(['syllabus_topic_id', 'set_number']);
            $table->dropColumn(['set_number', 'tier']);
        });
    }
};
