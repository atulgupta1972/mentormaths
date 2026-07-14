<p>Hello,</p>

<p>
    Here is the progress summary for <strong>{{ $studentName }}</strong>
    @if ($summary['class_name'])
        ({{ $summary['class_name'] }})
    @endif
    as on <strong>{{ $summary['as_of_label'] }}</strong>.
</p>

@if ($summary['period_label'])
    <p><strong>Period covered:</strong> {{ $summary['period_label'] }}</p>
@endif

@if (($summary['stats']['overall_score_label'] ?? null) && ($summary['stats']['completed_count'] ?? 0) > 0)
    <p><strong>Overall score:</strong> {{ $summary['stats']['overall_score_label'] }}</p>
@endif

<p>
    <strong>Completed:</strong> {{ $summary['stats']['completed_count'] }} ·
    <strong>Pending:</strong> {{ $summary['stats']['pending_count'] }} ·
    <strong>Overdue:</strong> {{ $summary['stats']['overdue_count'] }} ·
    <strong>Need teacher help:</strong> {{ $summary['stats']['help_count'] }}
</p>

@if (count($summary['completed']) > 0)
    <p><strong>Completed work</strong></p>

    @foreach ($summary['completed_by_chapter'] as $group)
        <p style="margin: 16px 0 8px; font-weight: bold;">{{ $group['chapter_name'] }}</p>
        @include('emails.partials.progress-summary-completed-table', ['rows' => $group['rows']])
    @endforeach
@endif

@if (count($summary['overdue']) > 0)
    <p><strong>Overdue</strong></p>

    @foreach ($summary['overdue_by_chapter'] as $group)
        <p style="margin: 16px 0 8px; font-weight: bold;">{{ $group['chapter_name'] }}</p>
        @include('emails.partials.progress-summary-target-table', [
            'rows' => $group['rows'],
            'dateLabel' => 'Due date',
        ])
    @endforeach
@endif

@if (count($summary['pending']) > 0)
    <p><strong>Pending</strong></p>

    @foreach ($summary['pending_by_chapter'] as $group)
        <p style="margin: 16px 0 8px; font-weight: bold;">{{ $group['chapter_name'] }}</p>
        @include('emails.partials.progress-summary-target-table', [
            'rows' => $group['rows'],
            'dateLabel' => 'Target date',
        ])
    @endforeach
@endif

@if (count($summary['help_requests']) > 0)
    <p><strong>Asked for teacher help:</strong></p>
    <ul>
        @foreach ($summary['help_requests'] as $item)
            <li>
                @if ($item['set_code'])
                    {{ $item['set_code'] }} —
                @endif
                {{ $item['question_text'] ?? 'Needs explanation in class' }}
            </li>
        @endforeach
    </ul>
@endif

@if (count($summary['recently_completed'] ?? []) > 0 && ($summary['period_label'] ?? null))
    <p><strong>Completed in this period</strong></p>

    @foreach ($summary['recently_completed_by_chapter'] as $group)
        <p style="margin: 16px 0 8px; font-weight: bold;">{{ $group['chapter_name'] }}</p>
        @include('emails.partials.progress-summary-completed-table', ['rows' => $group['rows']])
    @endforeach
@endif

<p>
    View dashboard:<br>
    <a href="{{ $summary['dashboard_url'] }}">{{ $summary['dashboard_url'] }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
