<p>Self-serve trial signup (admin notify only — no approval needed).</p>

<p><strong>Summary:</strong> {{ $summary }}</p>

<ul>
    <li><strong>Type:</strong> {{ $accessCode->type }}</li>
    <li><strong>tcode:</strong> {{ $accessCode->code }}</li>
    <li><strong>User:</strong> {{ $accessCode->user?->name }} ({{ $accessCode->user?->email }})</li>
    <li><strong>Mobile:</strong> {{ $accessCode->mobile }}</li>
    <li><strong>Generated:</strong> {{ $accessCode->generated_at }}</li>
    <li><strong>Expires:</strong> {{ $accessCode->expires_at }}</li>
    @if ($accessCode->coachingClass)
        <li><strong>Coaching class:</strong> {{ $accessCode->coachingClass->name }}</li>
    @endif
    @if ($accessCode->student)
        <li><strong>Student:</strong> {{ $accessCode->student->name }}</li>
    @endif
</ul>

<p>{{ config('app.name') }}</p>
