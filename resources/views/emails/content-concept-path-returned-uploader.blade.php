<p>Hello,</p>

@php
    $fullPathReturn = collect($returnItems ?? [])->contains(fn ($item) => ($item['title'] ?? null) === 'Full concept path');
@endphp

@if($fullPathReturn)
<p>Admin has sent the <strong>full concept builder</strong> back for changes.</p>
@else
<p>Admin has sent a <strong>concept path card</strong> back for changes.</p>
@endif

@if(count($returnItems ?? []) > 0)
<p>Please fix the item(s) below — regenerate with figures if asked — then save, approve, and Run full cards again.</p>
<ul>
@foreach($returnItems as $item)
    <li>
        <strong>
            @if(!empty($item['step']))
                Step {{ $item['step'] }}
            @elseif(($item['title'] ?? null) === 'Full concept path')
                Full concept path
            @else
                Concept card
            @endif
            @if(!empty($item['title']) && ($item['title'] ?? null) !== 'Full concept path')
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
