<p>Hello,</p>

<p>
    <strong>{{ $studentName }}</strong> entered school exam marks.
</p>

<p>
    <strong>Student:</strong> {{ $studentName }}
    @if ($studentEmail)
        ({{ $studentEmail }})
    @endif
    <br>
    <strong>Class / board:</strong> {{ $gradeName ?? '—' }} · {{ $boardName ?? '—' }}<br>
    <strong>School:</strong> {{ $schoolName ?? '—' }}
</p>

<p>
    <strong>Exam:</strong> {{ $examTitle }} ({{ $examTypeLabel }})<br>
    <strong>Exam date:</strong> {{ $examDate ?? '—' }}<br>
    <strong>Marks:</strong> {{ $obtainedMarks }} / {{ $totalMarks }} ({{ $percentLabel }})<br>
    <strong>Entered at:</strong> {{ $enteredAt ?? '—' }}
</p>

@if (count($chapterNames))
    <p>
        <strong>Chapters:</strong>
        {{ implode(', ', $chapterNames) }}
    </p>
@endif

<p>
    <a href="{{ $examsUrl }}">Open student exams page</a>
</p>

<p>— MentorMaths</p>
