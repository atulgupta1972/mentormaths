<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MentorMathsConversionPackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ImportMentorMathsConversionPackCommand extends Command
{
    protected $signature = 'mentormaths:import-conversion-pack
                            {path : Path to pack JSON (absolute, or relative to storage/app)}
                            {--user= : Admin user id (defaults to first admin)}
                            {--no-publish : Only merge fill-blanks; do not publish worksheets}';

    protected $description = 'Import a MentorMaths conversion pack and optionally publish fill-blank worksheets';

    public function handle(MentorMathsConversionPackService $packs): int
    {
        $path = (string) $this->argument('path');
        $absolute = $this->resolvePath($path);

        if (! is_file($absolute)) {
            $this->error("File not found: {$absolute}");

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($absolute), true);
        if (! is_array($decoded)) {
            $this->error('Pack JSON is invalid.');

            return self::FAILURE;
        }

        $user = $this->resolvePublisher();
        $publish = ! $this->option('no-publish');

        try {
            $result = $packs->importPack($decoded, $user, $publish);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['imported'] as $row) {
            $status = ($row['published'] ?? false) ? 'published' : 'saved';
            $this->info(sprintf(
                '[%s] %s · %s (id %s) — %s ready%s',
                $status,
                $row['book_code'] ?? '?',
                $row['title'] ?? '?',
                $row['target_chapter_id'] ?? '?',
                $row['fill_blank_ready_count'] ?? 0,
                ! empty($row['warning']) ? ' — '.$row['warning'] : '',
            ));
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        if ($result['errors'] !== [] && $result['imported'] === []) {
            return self::FAILURE;
        }

        return $result['errors'] === [] ? self::SUCCESS : self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $fromStorage = Storage::disk('local')->path($path);
        if (is_file($fromStorage)) {
            return $fromStorage;
        }

        return $path;
    }

    private function resolvePublisher(): User
    {
        if ($this->option('user')) {
            $user = User::query()->find((int) $this->option('user'));
            if (! $user) {
                throw new InvalidArgumentException('User not found.');
            }

            return $user;
        }

        $admin = User::query()->where('role', User::ROLE_ADMIN)->orderBy('id')->first();
        if (! $admin) {
            throw new InvalidArgumentException('No admin user found. Pass --user=ID.');
        }

        return $admin;
    }
}
