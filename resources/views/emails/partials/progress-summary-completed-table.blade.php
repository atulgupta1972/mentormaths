@php
    use App\Support\ProgressSummaryTable;
    $hideDateColumn = $hideDateColumn ?? false;
@endphp

<table cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse; font-size: 14px; width: 100%; margin-bottom: 16px;">
    <thead>
        <tr style="background: #f3f4f6;">
            @unless ($hideDateColumn)
                <th align="left">Date</th>
            @endunless
            <th align="left">Set</th>
            <th align="left">Type</th>
            <th align="left">Topic</th>
            @if ($hideDateColumn)
                <th align="left">Chapter</th>
            @endif
            <th align="left">Score</th>
            <th align="left">Review</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                @unless ($hideDateColumn)
                    <td>{{ ProgressSummaryTable::submittedDateLabel($row) ?? '—' }}</td>
                @endunless
                <td><strong>{{ $row['set_code'] }}</strong></td>
                <td>{{ $row['kind_label'] }}</td>
                <td>{{ ProgressSummaryTable::detailLabel($row) }}</td>
                @if ($hideDateColumn)
                    <td>{{ ProgressSummaryTable::chapterName($row) }}</td>
                @endif
                <td>
                    {{ ProgressSummaryTable::scoreLabel($row) }}
                    @if (($row['latest_attempt_number'] ?? 0) > 1)
                        <br><span style="font-size: 12px; color: #6b7280;">Attempt {{ $row['latest_attempt_number'] }}</span>
                    @endif
                </td>
                <td>{{ ProgressSummaryTable::reviewLabel($row) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
