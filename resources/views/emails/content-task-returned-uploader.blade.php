<p>Hello,</p>

<p>Admin has sent your textbook MCQ chapter back for <strong>re-verification</strong>.</p>

@if(count($returnItems ?? []) > 0)
<p>Please fix <strong>only the questions listed below</strong>, then save each one and submit again when done.</p>
<ul>
@foreach($returnItems as $item)
    <li>
        <strong>{{ isset($item['number']) && $item['number'] ? 'Q'.$item['number'] : 'Question #'.$item['question_id'] }}</strong>
        @if(!empty($item['remark']))
            — {{ $item['remark'] }}
        @else
            — please re-check / fix (options, explanation, or figure)
        @endif
        @if(!empty($item['question_text']))
            <br><em>{{ \Illuminate\Support\Str::limit(strip_tags($item['question_text']), 120) }}</em>
        @endif
    </li>
@endforeach
</ul>
@else
<p>Please open the task, review each question, fix the <strong>correct option</strong> and <strong>explanation</strong> where needed, then save each question and submit again when done.</p>
@endif

@if($task->admin_notes)
<p><strong>Admin note:</strong><br>{!! nl2br(e($task->admin_notes)) !!}</p>
@endif

<p><a href="{{ $taskUrl }}">Open chapter task →</a></p>

<p>If you are not logged in, <a href="{{ $loginUrl }}">sign in here</a> first.</p>

<p>— Mentor Maths</p>
