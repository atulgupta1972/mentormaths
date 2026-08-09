<?php

namespace Tests\Unit;

use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppSenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.driver' => 'meta',
            'whatsapp.meta.phone_number_id' => '123456',
            'whatsapp.meta.access_token' => 'test-token',
            'whatsapp.meta.api_version' => 'v21.0',
            'whatsapp.channels.progress_summary' => true,
        ]);
    }

    public function test_meta_driver_sends_text_message(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.test123']],
            ], 200),
        ]);

        $result = WhatsAppSender::sendText('progress_summary', '9876543210', 'Hello test');

        $this->assertTrue($result['sent']);
        $this->assertSame('919876543210', $result['to']);
        $this->assertSame('wamid.test123', $result['message_id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v21.0/123456/messages'
                && $request['to'] === '919876543210'
                && $request['text']['body'] === 'Hello test';
        });
    }

    public function test_manual_driver_does_not_send(): void
    {
        config(['whatsapp.driver' => 'manual']);

        $result = WhatsAppSender::sendText('progress_summary', '9876543210', 'Hello');

        $this->assertFalse($result['sent']);
        $this->assertSame('manual', $result['error']);
    }

    public function test_log_driver_sends_without_http(): void
    {
        config(['whatsapp.driver' => 'log']);

        $result = WhatsAppSender::sendText('progress_summary', '9876543210', 'Hello log');

        $this->assertTrue($result['sent']);
        Http::assertNothingSent();
    }
}
