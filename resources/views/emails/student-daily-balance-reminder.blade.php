<p>Hello,</p>

<p>
    Here is today's balance work for <strong>{{ $studentName }}</strong>
    @if ($summary['class_name'])
        ({{ $summary['class_name'] }})
    @endif
    as on <strong>{{ $summary['as_of_label'] }}</strong>.
</p>

<p>
    <strong>Still to do:</strong>
    {{ $summary['stats']['balance_count'] }} item(s) —
    Practice {{ $summary['stats']['practice_count'] }},
    Test {{ $summary['stats']['test_count'] }},
    Written {{ $summary['stats']['written_count'] }},
    Corrections {{ $summary['stats']['correction_count'] }}
    @if ($summary['stats']['overdue_count'] > 0)
        · <strong style="color: #b91c1c;">{{ $summary['stats']['overdue_count'] }} overdue</strong>
    @endif
</p>

@if (count($summary['overdue']) > 0)
    <p><strong>Overdue — please finish first</strong></p>

    @foreach ($summary['overdue_by_chapter'] as $group)
        <p style="margin: 16px 0 8px; font-weight: bold;">{{ $group['chapter_name'] }}</p>
        @include('emails.partials.progress-summary-target-table', [
            'rows' => $group['rows'],
            'dateLabel' => 'Due date',
            'showPendingDays' => true,
        ])
    @endforeach
@endif

@if (count($summary['pending']) > 0)
    <p><strong>Pending — practice, test, or written</strong></p>

    @foreach ($summary['pending_by_chapter'] as $group)
        <p style="margin: 16px 0 8px; font-weight: bold;">{{ $group['chapter_name'] }}</p>
        @include('emails.partials.progress-summary-target-table', [
            'rows' => $group['rows'],
            'dateLabel' => 'Target date',
            'showPendingDays' => true,
        ])
    @endforeach
@endif

@if (count($summary['help_requests']) > 0)
    <p><strong>Corrections — teacher help queue</strong></p>
    <ul>
        @foreach ($summary['help_requests'] as $item)
            <li>
                @if ($item['set_code'])
                    {{ $item['set_code'] }} —
                @endif
                {{ $item['question_text'] ?? 'Needs explanation in class, then retry from dashboard' }}
                @if (! empty($item['pending_days_label']) && $item['pending_days_label'] !== '—')
                    · <em>{{ $item['pending_days_label'] }}</em>
                @endif
            </li>
        @endforeach
    </ul>
@endif

<p>
    Open dashboard to complete work:<br>
    <a href="{{ $summary['dashboard_url'] }}">{{ $summary['dashboard_url'] }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
