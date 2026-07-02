<p>Hello {{ $registrationRequest->parent1_name }},</p>

<p>We have received your registration request for <strong>{{ $registrationRequest->student_name }}</strong>.</p>

<ul>
    <li>Academic year: {{ $registrationRequest->academicYear->name }}</li>
    <li>Class: {{ $registrationRequest->gradeLevel->name }}</li>
    <li>Board: {{ $registrationRequest->board->name }}</li>
    <li>School: {{ $registrationRequest->school_name }}</li>
</ul>

<p>Your request is <strong>pending review</strong>. We will contact you once access has been approved.</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
