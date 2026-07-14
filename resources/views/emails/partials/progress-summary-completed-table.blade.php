@php
    use App\Support\ProgressSummaryTable;
@endphp

<table cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse; font-size: 14px; width: 100%; margin-bottom: 16px;">
    <thead>
        <tr style="background: #f3f4f6;">
            <th align="left">Date</th>
            <th align="left">Set</th>
            <th align="left">Type</th>
            <th align="left">Topic</th>
            <th align="left">Score</th>
            <th align="left">Review</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ ProgressSummaryTable::submittedDateLabel($row) ?? '—' }}</td>
                <td><strong>{{ $row['set_code'] }}</strong></td>
                <td>{{ $row['kind_label'] }}</td>
                <td>{{ ProgressSummaryTable::detailLabel($row) }}</td>
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
