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

    public function test_progress_report_template_has_one_parameter_per_line(): void
    {
        config([
            'whatsapp.templates.progress_report_name' => 'mentor_maths_progress',
            'whatsapp.templates.language' => 'en',
        ]);

        $template = WhatsAppTemplateBuilder::forProgressReport([
            "Vishvesh",
            "1 Nov 2026 – 16 Nov 2026, Class 7",
            "8 days",
            "4",
            "Fractions: 2 worksheets, score 90%",
            "Integers: 1 worksheet, score 60%",
            '—',
            '75% (30/40)',
            "Integers I1 60%",
            '—',
        ]);

        $parameters = $template['components'][0]['parameters'];

        $this->assertSame('mentor_maths_progress', $template['name']);
        $this->assertCount(10, $parameters);
        $this->assertSame('Fractions: 2 worksheets, score 90%', $parameters[4]['text']);
        foreach ($parameters as $parameter) {
            $this->assertStringNotContainsString("\n", $parameter['text']);
        }
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

    public function test_attempt_completion_template_uses_nine_body_parameters(): void
    {
        config([
            'whatsapp.templates.names.assignment_completed' => 'mentor_maths_completed',
            'whatsapp.templates.language' => 'en',
        ]);

        $template = WhatsAppTemplateBuilder::forAttemptCompletion([
            'VISHVESH finished Test BF7223.',
            'Achiever',
            'Chapter: Operations with Integers',
            '80% (12/15)',
            'First try correct: 12 · After help: 0 · Gave up: 2',
            'Q2 · Rules of signs — Not correct on first try',
            'Q9 · Word problems — Gave up',
            'Q11 · Word problems — Gave up',
            'https://mentormaths.in/dashboard',
        ]);

        $this->assertSame('mentor_maths_completed', $template['name']);
        $this->assertCount(9, $template['components'][0]['parameters']);
        $this->assertSame('Achiever', $template['components'][0]['parameters'][1]['text']);
    }
}
