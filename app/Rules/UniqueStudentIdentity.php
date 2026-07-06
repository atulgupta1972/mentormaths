<?php

namespace App\Rules;

use App\Support\StudentIdentity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueStudentIdentity implements ValidationRule
{
    public function __construct(
        private ?int $ignoreRegistrationRequestId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = request()->input('student_name');
        $mobile = request()->input('student_mobile');

        if (! StudentIdentity::isCheckable($name, $mobile)) {
            return;
        }

        if (StudentIdentity::findExistingStudent($name, $mobile)) {
            $fail('A student with this name and mobile number is already registered. Please log in or contact the admin.');

            return;
        }

        if (StudentIdentity::findPendingRequest($name, $mobile, $this->ignoreRegistrationRequestId)) {
            $fail('A registration request with this name and mobile number is already pending review.');
        }
    }
}
