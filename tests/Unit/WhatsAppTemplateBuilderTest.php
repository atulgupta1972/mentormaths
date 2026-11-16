<?php

namespace Tests\Unit;

use App\Support\WhatsApp\WhatsAppTemplateBuilder;
use Tests\TestCase;

class WhatsAppTemplateBuilderTest extends TestCase
{
    public function test_builds_template_with_compact_body_and_link(): void
    {
        config([
            'whatsapp.templates.default_name' => 'mentor_maths_update',
            'whatsapp.templates.language' => 'en',
        ]);

        $message = "Hello, this is Mentor Maths.\n\nDaily work reminder for Rahul\n\nView details:\nhttps://mentormaths.in/dashboard\n\nThank you.";

        $template = WhatsAppTemplateBuilder::forChannel('daily_balance', $message, 'https://mentormaths.in/dashboard');

        $this->assertSame('mentor_maths_update', $template['name']);
        $this->assertSame('en', $template['language']);
        $body = $template['components'][0]['parameters'][0]['text'];
        $this->assertStringContainsString('Daily work reminder for Rahul', $body);
        $this->assertStringNotContainsString("\n", $body);
        $this->assertStringNotContainsString("\t", $body);
        $this->assertSame('https://mentormaths.in/dashboard', $template['components'][0]['parameters'][1]['text']);
    }

    public function test_month_report_parameter_is_a_single_line(): void
    {
        config([
            'whatsapp.templates.default_name' => 'mentor_maths_work',
            'whatsapp.templates.language' => 'en',
        ]);

        $message = "Hello, this is Mentor Maths.\n\nMonthly report for Vishvesh\nPeriod: 1 Nov 2026 – 16 Nov 2026\n\nTime on Mentor Maths: 3h 20m\nDays logged in: 8 of 16\nTests completed: 4\nScore: 32/40 (80%)\n\nView details:\nhttps://mentormaths.in/dashboard\n\nThank you.";

        $template = WhatsAppTemplateBuilder::forChannel('progress_summary', $message, 'https://mentormaths.in/dashboard');
        $body = $template['components'][0]['parameters'][0]['text'];

        $this->assertStringNotContainsString("\n", $body);
        $this->assertStringContainsString('Time on Mentor Maths: 3h 20m', $body);
        $this->assertStringContainsString('Tests completed: 4', $body);
        $this->assertStringContainsString('Score: 32/40 (80%)', $body);
        $this->assertStringNotContainsString('View details', $body);
    }

    public function test_should_use_template_only_for_meta_driver(): void
    {
        config([
            'whatsapp.templates.enabled' => true,
            'whatsapp.driver' => 'meta',
        ]);

        $this->assertTrue(WhatsAppTemplateBuilder::shouldUseTemplate());

        config(['whatsapp.driver' => 'log']);

        $this->assertFalse(WhatsAppTemplateBuilder::shouldUseTemplate());
    }
}
