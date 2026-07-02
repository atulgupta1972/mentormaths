<p>A new registration request has been submitted.</p>

<ul>
    <li>Student: {{ $registrationRequest->student_name }}</li>
    <li>Parent: {{ $registrationRequest->parent1_name }} ({{ $registrationRequest->parent1_mobile }})</li>
    <li>Class: {{ $registrationRequest->gradeLevel->name }}</li>
    <li>Board: {{ $registrationRequest->board->name }}</li>
    <li>Email: {{ $registrationRequest->email ?: 'not provided' }}</li>
</ul>

<p><a href="{{ $reviewUrl }}">Review this request</a></p>
