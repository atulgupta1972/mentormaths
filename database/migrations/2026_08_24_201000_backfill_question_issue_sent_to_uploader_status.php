<?php

use App\Models\QuestionIssueReport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('question_issue_reports')) {
            return;
        }

        // Earlier “Send to uploader” only wrote a note — move those into the new status.
        DB::table('question_issue_reports')
            ->where('status', QuestionIssueReport::STATUS_PENDING_ADMIN)
            ->where('admin_note', 'like', 'Sent to uploader%')
            ->update([
                'status' => QuestionIssueReport::STATUS_SENT_TO_UPLOADER,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('question_issue_reports')) {
            return;
        }

        DB::table('question_issue_reports')
            ->where('status', QuestionIssueReport::STATUS_SENT_TO_UPLOADER)
            ->update([
                'status' => QuestionIssueReport::STATUS_PENDING_ADMIN,
                'updated_at' => now(),
            ]);
    }
};
