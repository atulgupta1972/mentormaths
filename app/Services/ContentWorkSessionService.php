<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\ContentWorkSession;
use App\Models\User;

class ContentWorkSessionService
{
    public const IDLE_TIMEOUT_SECONDS = 300;

    public function startOrResume(ContentUploadTask $task, User $user): ContentWorkSession
    {
        $open = ContentWorkSession::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        if ($open) {
            $open->update(['last_activity_at' => now()]);

            return $open->fresh();
        }

        return ContentWorkSession::create([
            'content_upload_task_id' => $task->id,
            'user_id' => $user->id,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    public function recordActivity(ContentWorkSession $session): ContentWorkSession
    {
        if (! $session->isOpen()) {
            return $session;
        }

        $now = now();
        $last = $session->last_activity_at ?? $session->started_at;
        $elapsed = max(0, $last->diffInSeconds($now));

        if ($elapsed <= self::IDLE_TIMEOUT_SECONDS) {
            $session->increment('active_seconds', $elapsed);
        } else {
            $session->increment('active_seconds', self::IDLE_TIMEOUT_SECONDS);
            $session->increment('idle_paused_seconds', $elapsed - self::IDLE_TIMEOUT_SECONDS);
        }

        $session->update(['last_activity_at' => $now]);

        return $session->fresh();
    }

    public function endOpenSessions(ContentUploadTask $task, User $user): void
    {
        ContentWorkSession::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->each(function (ContentWorkSession $session) {
                $this->recordActivity($session);
                $session->update(['ended_at' => now()]);
            });
    }

    public function totalActiveSeconds(ContentUploadTask $task): int
    {
        return (int) ContentWorkSession::query()
            ->where('content_upload_task_id', $task->id)
            ->sum('active_seconds');
    }
}
