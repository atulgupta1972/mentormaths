<?php

namespace Tests\Unit;

use App\Support\StudentWeeklyReportEmails;
use Tests\TestCase;

class StudentWeeklyReportEmailsTest extends TestCase
{
    public function test_parses_up_to_two_comma_separated_emails(): void
    {
        $emails = StudentWeeklyReportEmails::parse(' parent1@test.com , parent2@test.com , extra@test.com ');

        $this->assertSame(['parent1@test.com', 'parent2@test.com'], $emails);
    }

    public function test_display_joins_parent_emails(): void
    {
        $this->assertSame(
            'a@test.com, b@test.com',
            StudentWeeklyReportEmails::display('a@test.com', 'b@test.com'),
        );
    }
}
