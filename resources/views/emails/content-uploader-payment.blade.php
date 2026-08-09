@php
    $task = $payment->task;
    $uploader = $task?->assignee;
    $chapter = $task?->textbookChapter;
    $book = $chapter?->textbook;
    $grade = $book?->gradeLevel;
@endphp

<p>Hi {{ $uploader->name ?? 'there' }},</p>

<p>
    Payment has been recorded for your Mentor Maths <strong>content upload</strong> work.
</p>

<div style="margin: 16px 0; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
    <p style="margin: 0 0 6px;">
        <strong>{{ $grade->name ?? 'Class' }}</strong>
        · {{ $book->name ?? 'Textbook' }}
    </p>
    <p style="margin: 0 0 6px;">
        <strong>Chapter:</strong>
        Ch {{ $chapter->chapter_number ?? '?' }} — {{ $chapter->title ?? 'Untitled' }}
    </p>
    <p style="margin: 0 0 6px;">
        <strong>Amount paid:</strong> ₹{{ number_format((int) $payment->amount_inr) }}
    </p>
    <p style="margin: 0 0 6px;">
        <strong>Paid on:</strong> {{ $payment->paid_on?->format('d M Y') }}
    </p>
    <p style="margin: 0 0 6px;">
        <strong>Method:</strong> {{ $payment->methodLabel() }}
    </p>
    <p style="margin: 0 0 6px;">
        <strong>UPI / reference:</strong> {{ $payment->upi_or_reference }}
    </p>
    @if ($payment->notes)
        <p style="margin: 0;">
            <strong>Notes:</strong> {{ $payment->notes }}
        </p>
    @endif
</div>

<p>
    If anything looks incorrect, reply to this email or message admin.
</p>

<p>
    <a href="{{ $tasksUrl }}">Open your content tasks →</a>
    · <a href="{{ $loginUrl }}">Sign in</a>
</p>

<p>— Mentor Maths</p>
