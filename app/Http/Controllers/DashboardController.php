<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Services\SetAttemptService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private SetAttemptService $attemptService) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return Inertia::render('Dashboard', [
                'isAdmin' => true,
            ]);
        }

        $enrollment = $user->student?->currentEnrollment();
        $assignments = $enrollment
            ? $this->attemptService->dashboardForEnrollment($enrollment)
            : [];

        $byTopic = collect($assignments)->groupBy('topic_id')->map(function ($items, $topicId) {
            $first = $items->first();

            return [
                'topic_id' => $topicId,
                'topic_name' => $first['topic_name'],
                'chapter_name' => $first['chapter_name'],
                'sets' => $items->values()->all(),
            ];
        })->values()->all();

        return Inertia::render('Dashboard', [
            'isAdmin' => false,
            'assignments' => $assignments,
            'topics' => $byTopic,
            'activeYear' => AcademicYear::active()?->only(['id', 'name']),
        ]);
    }
}
