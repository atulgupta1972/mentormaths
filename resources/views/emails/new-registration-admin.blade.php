<p>Hello Admin,</p>

<p>A new registration request has been submitted on {{ config('app.name') }}.</p>

<ul>
    <li><strong>Student:</strong> {{ $registrationRequest->student_name }}</li>
    <li><strong>Parent:</strong> {{ $registrationRequest->parent1_name }} ({{ $registrationRequest->parent1_mobile }})</li>
    <li><strong>Class:</strong> {{ $registrationRequest->gradeLevel->name }}</li>
    <li><strong>Board:</strong> {{ $registrationRequest->board->name }}</li>
    <li><strong>School:</strong> {{ $registrationRequest->school_name }}</li>
    <li><strong>Login email:</strong> {{ $registrationRequest->email }}</li>
    <li><strong>Academic year:</strong> {{ $registrationRequest->academicYear->name }}</li>
</ul>

<p>
    <a href="{{ $reviewUrl }}">Review and approve this request</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
