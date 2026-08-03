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

@if (! empty($summary['formula_drill']['weak_formulas']))
    <p><strong>Formula memory — needs more practice</strong></p>
    <p style="font-size: 14px;">
        Pool: {{ $summary['formula_drill']['pool_size'] ?? 0 }} formulas ·
        Mastered: {{ $summary['formula_drill']['mastered_count'] ?? 0 }} ·
        Failures: {{ $summary['formula_drill']['total_failures'] ?? 0 }}
    </p>
    <ul>
        @foreach ($summary['formula_drill']['weak_formulas'] as $row)
            <li style="margin-bottom: 8px;">
                {{ $row['question_text'] }}
                @if (($row['total_failures'] ?? 0) > 0)
                    · {{ $row['total_failures'] }} wrong attempt(s)
                @endif
            </li>
        @endforeach
    </ul>
@endif

@if (! empty($summary['basics_drill']['weak_facts']))
    <p><strong>Tables & powers — needs more practice</strong></p>
    <p style="font-size: 14px;">
        Facts practised: {{ $summary['basics_drill']['facts_practised'] ?? 0 }} ·
        Mastered: {{ $summary['basics_drill']['mastered_count'] ?? 0 }} ·
        Failures: {{ $summary['basics_drill']['total_failures'] ?? 0 }}
    </p>
    <ul>
        @foreach ($summary['basics_drill']['weak_facts'] as $row)
            <li style="margin-bottom: 8px;">
                {{ $row['label'] }}
                @if (($row['times_failed'] ?? 0) > 0)
                    · {{ $row['times_failed'] }} wrong attempt(s)
                @endif
            </li>
        @endforeach
    </ul>
@endif

<p>
    View dashboard:<br>
    <a href="{{ $summary['dashboard_url'] }}">{{ $summary['dashboard_url'] }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
