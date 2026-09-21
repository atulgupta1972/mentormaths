<?php

namespace Tests\Unit;

use App\Support\MentorMathsSourceRef;
use PHPUnit\Framework\TestCase;

class MentorMathsSourceRefTest extends TestCase
{
    public function test_options_for_class_include_publisher_refs(): void
    {
        $options = MentorMathsSourceRef::options(7);
        $values = array_column($options, 'value');

        $this->assertContains('RDS-C7', $values);
        $this->assertContains('RSA-C7', $values);
        $this->assertContains('GL-C7', $values);
        $this->assertContains('EXEM-C7', $values);
        $this->assertNotContains('RDS-C8', $values);
    }

    public function test_guess_from_book_name(): void
    {
        $this->assertSame('RDS-C9', MentorMathsSourceRef::guessFromBook('RD SHARMA', 'rds', 9));
        $this->assertSame('RSA-C10', MentorMathsSourceRef::guessFromBook('RS AGARWAL', 'rs', 10));
        $this->assertSame('GL-C7', MentorMathsSourceRef::guessFromBook('Greya Lakshmi', 'gl', 7));
    }
}
