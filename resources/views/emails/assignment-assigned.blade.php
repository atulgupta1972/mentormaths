<p>Hello,</p>

<p>
    This is <strong>{{ config('app.name') }}</strong>.
    New {{ count($items) > 1 ? 'work' : strtolower($items[0]['kind_label']) }} has been assigned for
    <strong>{{ $studentName }}</strong>.
</p>

<p><strong>Complete by:</strong> {{ $dueLabel }}</p>

<ul>
    @foreach ($items as $item)
        <li>
            <strong>{{ $item['set_code'] }}</strong> — {{ $item['tier_label'] }} {{ $item['kind_label'] }}
            @if ($item['scope_line'])
                <br>{{ $item['scope_line'] }}
            @endif
            <br>{{ $item['question_count'] }} question{{ $item['question_count'] === 1 ? '' : 's' }}
            · {{ $item['time_estimate'] }}
        </li>
    @endforeach
</ul>

@if ($notes)
    <p><strong>Note from teacher:</strong> {{ $notes }}</p>
@endif

<p>
    Log in and start from the dashboard:<br>
    <a href="{{ $dashboardUrl }}">{{ $dashboardUrl }}</a>
</p>

<p>
    Login page: <a href="{{ $loginUrl }}">{{ $loginUrl }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
