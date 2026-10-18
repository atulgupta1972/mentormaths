<?php

namespace App\Support;

use App\Mail\ExamMarksEnteredAdmin;
use App\Models\ExamPlan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ExamMarksMailer
{
    public static function notifyAdmin(ExamPlan $plan): void
    {
        if (! $plan->hasMarks()) {
            return;
        }

        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();
        if (! $adminEmail) {
            Log::warning('Exam marks entered but no admin notify email configured.', [
                'exam_plan_id' => $plan->id,
            ]);

            return;
        }

        $loaded = $plan->fresh([
            'enrollment.student.user',
            'enrollment.gradeLevel',
            'enrollment.board',
            'chapters',
        ]) ?? $plan;

        try {
            Mail::to($adminEmail)->send(new ExamMarksEnteredAdmin($loaded));
        } catch (\Throwable $e) {
            Log::error('Failed to send exam marks admin email.', [
                'exam_plan_id' => $plan->id,
                'admin_email' => $adminEmail,
                'error' => $e->getMessage(),
            ]);

            if (app()->environment('testing')) {
                throw $e;
            }
        }
    }
}
