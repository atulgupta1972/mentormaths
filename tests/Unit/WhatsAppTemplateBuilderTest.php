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
        $this->assertStringContainsString('Daily work reminder for Rahul', $template['components'][0]['parameters'][0]['text']);
        $this->assertSame('https://mentormaths.in/dashboard', $template['components'][0]['parameters'][1]['text']);
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
