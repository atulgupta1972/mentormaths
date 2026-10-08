<p>Hello,</p>

<p>Admin has sent a <strong>concept path card</strong> back for changes.</p>

@if(count($returnItems ?? []) > 0)
<p>Please fix the card(s) below — regenerate with figures if asked — then save, approve, and Run full cards again.</p>
<ul>
@foreach($returnItems as $item)
    <li>
        <strong>
            @if(!empty($item['step']))
                Step {{ $item['step'] }}
            @else
                Concept card
            @endif
            @if(!empty($item['title']))
                — {{ $item['title'] }}
            @endif
        </strong>
        @if(!empty($item['remark']))
            <br>{{ $item['remark'] }}
        @endif
    </li>
@endforeach
</ul>
@else
<p>Please open the concept path, fix the cards that need work, then save, approve, and Run full cards again.</p>
@endif

@if($task->admin_notes)
<p><strong>Admin note:</strong><br>{!! nl2br(e($task->admin_notes)) !!}</p>
@endif

<p><a href="{{ $taskUrl }}">Open concept path →</a></p>

<p>If you are not logged in, <a href="{{ $loginUrl }}">sign in here</a> first.</p>

<p>— Mentor Maths</p>
