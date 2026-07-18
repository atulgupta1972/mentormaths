<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Progress summary — {{ $summary['student_name'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        h1 { font-size: 16px; margin: 0 0 8px; }
        h2 { font-size: 13px; margin: 18px 0 8px; color: #312e81; }
        p { margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 10px; text-transform: uppercase; }
        .muted { color: #6b7280; font-size: 10px; }
        .stats { margin: 10px 0; }
        .chart-block { margin-top: 16px; page-break-inside: avoid; }
        .chart-box { border: 1px solid #e5e7eb; padding: 8px; margin-top: 8px; }
        .chart-img { width: 100%; max-width: 500px; height: auto; }
        .bar-track { background: #e5e7eb; height: 14px; width: 100%; }
        .bar-fill { background: #4f46e5; height: 14px; }
        .line-grid { width: 100%; border-collapse: collapse; height: 130px; border-bottom: 1px solid #d1d5db; }
        .line-grid td { border: none; vertical-align: bottom; text-align: center; padding: 2px; }
        .line-dot { width: 10px; background: #059669; margin: 0 auto; }
        .line-label { font-size: 8px; color: #374151; }
    </style>
</head>
<body>
    <h1>Progress summary — {{ $summary['student_name'] }}</h1>
    @if ($summary['class_name'])
        <p><strong>Class:</strong> {{ $summary['class_name'] }}</p>
    @endif
    <p><strong>As on:</strong> {{ $summary['as_of_label'] }}</p>
    @if ($summary['period_label'] ?? null)
        <p><strong>Period:</strong> {{ $summary['period_label'] }}</p>
    @endif

    <p class="stats">
        <strong>Completed:</strong> {{ $summary['stats']['completed_count'] }} ·
        <strong>Pending:</strong> {{ $summary['stats']['pending_count'] }} ·
        <strong>Overdue:</strong> {{ $summary['stats']['overdue_count'] }}
        @if (($summary['stats']['overall_score_label'] ?? null) && ($summary['stats']['completed_count'] ?? 0) > 0)
            · <strong>Overall:</strong> {{ $summary['stats']['overall_score_label'] }}
        @endif
    </p>

    @if (count($summary['completed']) > 0)
        <h2>Completed work</h2>
        @foreach ($summary['completed_by_chapter'] as $group)
            <p><strong>{{ $group['chapter_name'] }}</strong></p>
            @include('emails.partials.progress-summary-completed-table', ['rows' => $group['rows']])
        @endforeach
    @endif

    @if (count($summary['overdue']) > 0)
        <h2>Overdue</h2>
        @foreach ($summary['overdue_by_chapter'] as $group)
            <p><strong>{{ $group['chapter_name'] }}</strong></p>
            @include('emails.partials.progress-summary-target-table', [
                'rows' => $group['rows'],
                'dateLabel' => 'Due date',
            ])
        @endforeach
    @endif

    @if (count($summary['pending']) > 0)
        <h2>Pending</h2>
        @foreach ($summary['pending_by_chapter'] as $group)
            <p><strong>{{ $group['chapter_name'] }}</strong></p>
            @include('emails.partials.progress-summary-target-table', [
                'rows' => $group['rows'],
                'dateLabel' => 'Target date',
            ])
        @endforeach
    @endif

    @if (count($summary['help_requests']) > 0)
        <h2>Asked for teacher help</h2>
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

    @if (count($summary['chapter_performance'] ?? []) > 0)
        <h2>Chapter-wise performance</h2>
        <p class="muted">Overall score % by chapter (all completed sets in this report).</p>
        <table>
            <thead>
                <tr>
                    <th>Chapter</th>
                    <th>Sets</th>
                    <th>Overall</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary['chapter_performance'] as $row)
                    <tr>
                        <td>{{ $row['chapter_name'] }}</td>
                        <td>{{ $row['sets_count'] }}</td>
                        <td>{{ $row['label'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (! empty($chartPaths['chapter_bar_chart'] ?? null))
        <div class="chart-block">
            <h2>Chapter performance chart</h2>
            <p class="muted">Overall % by chapter</p>
            <div class="chart-box">
                <img src="{{ $chartPaths['chapter_bar_chart'] }}" alt="Chapter performance chart" class="chart-img" />
            </div>
        </div>
    @elseif (count($summary['chapter_performance'] ?? []) > 0)
        <div class="chart-block">
            <h2>Chapter performance chart</h2>
            <p class="muted">Overall % by chapter</p>
            <div class="chart-box">
                <table style="width:100%; border:none;">
                    @foreach ($summary['chapter_performance'] as $row)
                        <tr>
                            <td style="width:34%; border:none; padding:4px 8px 4px 0;">{{ $row['chapter_name'] }}</td>
                            <td style="border:none; padding:4px 0;">
                                <div class="bar-track">
                                    <div class="bar-fill" style="width: {{ max(0, min(100, (int) ($row['percent'] ?? 0))) }}%;"></div>
                                </div>
                            </td>
                            <td style="width:12%; border:none; text-align:right; padding-left:8px;">{{ $row['percent'] ?? '—' }}%</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endif

    @if (count($summary['date_performance'] ?? []) > 0)
        <div class="chart-block">
            <h2>Date-wise performance</h2>
            <p class="muted">
                Overall % on each submission date
                @if ($summary['period_label'] ?? null)
                    (period: {{ $summary['period_label'] }})
                @endif
            </p>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Sets</th>
                        <th>Overall</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['date_performance'] as $row)
                        <tr>
                            <td>{{ $row['date_label'] }}</td>
                            <td>{{ $row['sets_count'] }}</td>
                            <td>{{ $row['label'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! empty($chartPaths['date_line_chart'] ?? null))
        <div class="chart-block">
            <h2>Date-wise performance chart</h2>
            <p class="muted">Trend of overall % by submission date</p>
            <div class="chart-box">
                <img src="{{ $chartPaths['date_line_chart'] }}" alt="Date-wise performance chart" class="chart-img" />
            </div>
        </div>
    @elseif (count($summary['date_performance'] ?? []) > 0)
        <div class="chart-block">
            <h2>Date-wise performance chart</h2>
            <p class="muted">Trend of overall % by submission date</p>
            <div class="chart-box">
                <table class="line-grid">
                    <tr>
                        @foreach ($summary['date_performance'] as $row)
                            @php($height = max(6, min(100, (int) ($row['percent'] ?? 0))))
                            <td>
                                <div class="line-label">{{ $row['percent'] ?? '—' }}%</div>
                                <div class="line-dot" style="height: {{ $height }}px;"></div>
                            </td>
                        @endforeach
                    </tr>
                    <tr>
                        @foreach ($summary['date_performance'] as $row)
                            <td class="line-label">{{ $row['date_label'] }}</td>
                        @endforeach
                    </tr>
                </table>
            </div>
        </div>
    @endif

    <p class="muted">Generated by {{ config('app.name') }} · {{ $summary['dashboard_url'] }}</p>
</body>
</html>
