<?php

namespace Tests\Unit;

use App\Support\WhatsApp\WhatsAppPhone;
use Tests\TestCase;

class WhatsAppPhoneTest extends TestCase
{
    public function test_normalizes_ten_digit_indian_mobile(): void
    {
        $this->assertSame('919876543210', WhatsAppPhone::normalize('9876543210'));
        $this->assertSame('919876543210', WhatsAppPhone::normalize('98765 43210'));
        $this->assertSame('919876543210', WhatsAppPhone::normalize('+91 9876543210'));
    }

    public function test_normalizes_leading_zero(): void
    {
        $this->assertSame('919876543210', WhatsAppPhone::normalize('09876543210'));
    }

    public function test_validates_indian_mobile(): void
    {
        $this->assertTrue(WhatsAppPhone::isValid('9876543210'));
        $this->assertFalse(WhatsAppPhone::isValid('5876543210'));
        $this->assertFalse(WhatsAppPhone::isValid(''));
    }
}
