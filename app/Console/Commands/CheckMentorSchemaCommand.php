<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckMentorSchemaCommand extends Command
{
    protected $signature = 'mentor:schema-check';

    protected $description = 'Check database columns required by the question bank chapter hub';

    public function handle(): int
    {
        $checks = [
            'worksheets.set_number' => Schema::hasColumn('worksheets', 'set_number'),
            'worksheets.tier' => Schema::hasColumn('worksheets', 'tier'),
            'worksheets.set_code' => Schema::hasColumn('worksheets', 'set_code'),
            'worksheets.scope' => Schema::hasColumn('worksheets', 'scope'),
            'worksheets.syllabus_chapter_id' => Schema::hasColumn('worksheets', 'syllabus_chapter_id'),
            'worksheets.purpose' => Schema::hasColumn('worksheets', 'purpose'),
            'worksheets.delivery_mode' => Schema::hasColumn('worksheets', 'delivery_mode'),
            'worksheets.written_status' => Schema::hasColumn('worksheets', 'written_status'),
            'questions.bank_purpose' => Schema::hasColumn('questions', 'bank_purpose'),
        ];

        $missing = [];

        foreach ($checks as $label => $present) {
            $status = $present ? 'OK' : 'MISSING';
            $this->line("{$label}: {$status}");

            if (! $present) {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            $this->newLine();
            $this->error('Missing columns detected. Run: php artisan migrate --force');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Schema looks good for the question bank chapter hub.');

        return self::SUCCESS;
    }
}
