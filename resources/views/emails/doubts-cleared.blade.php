<p>Hello,</p>

<p>
    <strong>{{ $studentName }}</strong> has marked the following topic doubt{{ count($items) === 1 ? '' : 's' }} as cleared
    after teacher help on <strong>{{ config('app.name') }}</strong>.
</p>

<table cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse;">
    <thead>
        <tr>
            <th align="left" style="padding:8px 4px; border-bottom:1px solid #ddd;">Set</th>
            <th align="left" style="padding:8px 4px; border-bottom:1px solid #ddd;">Question</th>
            <th align="left" style="padding:8px 4px; border-bottom:1px solid #ddd;">Asked for help</th>
            <th align="left" style="padding:8px 4px; border-bottom:1px solid #ddd;">Cleared</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $item)
            <tr>
                <td style="padding:8px 4px; border-bottom:1px solid #eee; vertical-align:top;">
                    {{ $item['set_code'] ?? '—' }}
                </td>
                <td style="padding:8px 4px; border-bottom:1px solid #eee; vertical-align:top;">
                    {{ $item['question_text'] }}
                    @if (! empty($item['topic_label']))
                        <br><span style="color:#666; font-size:12px;">{{ $item['topic_label'] }}</span>
                    @endif
                </td>
                <td style="padding:8px 4px; border-bottom:1px solid #eee; vertical-align:top;">
                    {{ $item['asked_label'] }}
                </td>
                <td style="padding:8px 4px; border-bottom:1px solid #eee; vertical-align:top;">
                    {{ $item['cleared_label'] }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

<p>
    Dashboard: <a href="{{ $dashboardUrl }}">{{ $dashboardUrl }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
