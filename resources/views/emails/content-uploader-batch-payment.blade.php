@php
    $first = $payments->first();
    $totalInr = (int) $payments->sum('amount_inr');
@endphp

<p>Hi {{ $uploader->name ?? 'there' }},</p>

<p>
    Payment has been recorded for your Mentor Maths <strong>content upload</strong> work
    ({{ $payments->count() }} chapter{{ $payments->count() === 1 ? '' : 's' }} in one transfer).
</p>

<div style="margin: 16px 0; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
    <p style="margin: 0 0 10px;">
        <strong>Total amount paid:</strong> ₹{{ number_format($totalInr) }}
    </p>
    <p style="margin: 0 0 10px;">
        <strong>Paid on:</strong> {{ $first->paid_on?->format('d M Y') }}
    </p>
    <p style="margin: 0 0 10px;">
        <strong>Method:</strong> {{ $first->methodLabel() }}
    </p>
    <p style="margin: 0 0 10px;">
        <strong>UPI / reference:</strong> {{ $first->upi_or_reference }}
    </p>
    @if ($first->notes)
        <p style="margin: 0 0 10px;">
            <strong>Notes:</strong> {{ $first->notes }}
        </p>
    @endif

    <p style="margin: 12px 0 6px; font-weight: bold;">Chapters covered</p>
    <ul style="margin: 0; padding-left: 18px;">
        @foreach ($payments as $payment)
            @php
                $chapter = $payment->task?->textbookChapter;
                $book = $chapter?->textbook;
                $grade = $book?->gradeLevel;
            @endphp
            <li style="margin-bottom: 6px;">
                <strong>{{ $grade->name ?? 'Class' }}</strong>
                · Ch {{ $chapter->chapter_number ?? '?' }} — {{ $chapter->title ?? 'Untitled' }}
                ({{ $book->name ?? 'Textbook' }})
                — ₹{{ number_format((int) $payment->amount_inr) }}
            </li>
        @endforeach
    </ul>
</div>

<p>
    If anything looks incorrect, reply to this email or message admin.
</p>

<p>
    <a href="{{ $tasksUrl }}">Open your content tasks →</a>
    · <a href="{{ $loginUrl }}">Sign in</a>
</p>

<p>— Mentor Maths</p>
