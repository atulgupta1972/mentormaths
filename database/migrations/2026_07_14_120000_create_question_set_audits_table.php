<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_set_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worksheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedSmallInteger('issue_count')->default(0);
            $table->json('findings');
            $table->timestamps();

            $table->index(['worksheet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_set_audits');
    }
};
