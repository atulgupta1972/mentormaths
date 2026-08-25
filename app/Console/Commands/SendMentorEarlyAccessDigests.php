<?php

namespace App\Console\Commands;

use App\Services\MentorEarlyAccessDigestService;
use App\Support\MentorEarlyAccessDigestMailer;
use Illuminate\Console\Command;

class SendMentorEarlyAccessDigests extends Command
{
    protected $signature = 'mentors:send-early-access-digests
                            {--dry-run : Show what would be sent without emailing}';

    protected $description = 'Daily early-access email to mentors: enrolled students or enrolment nudge + how the system works';

    public function handle(MentorEarlyAccessDigestService $digestService): int
    {
        if (! config('mentor_digest.enabled', true)) {
            $this->warn('Mentor early-access digests are disabled (MENTOR_EARLY_ACCESS_DIGEST_ENABLED=false).');

            return self::SUCCESS;
        }

        $mentors = $digestService->recipients();
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($mentors as $mentor) {
            $payload = $digestService->buildPayload($mentor);
            $label = sprintf(
                '%s <%s> — %d student(s)',
                $mentor->name,
                $mentor->email,
                $payload['stats']['total'],
            );

            if ($this->option('dry-run')) {
                $this->line("Would send: {$label}");
                $sent++;

                continue;
            }

            $result = MentorEarlyAccessDigestMailer::send($mentor, $payload);

            if ($result['sent']) {
                $sent++;
                $this->info("Sent: {$label}");
            } elseif ($result['error'] === 'no_email') {
                $skipped++;
            } else {
                $failed++;
                $this->warn("Failed: {$label}");
            }
        }

        $this->info("Mentor early-access digests: {$sent} sent, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }
}
