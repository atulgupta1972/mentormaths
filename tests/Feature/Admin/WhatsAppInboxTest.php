<?php

namespace Tests\Feature\Admin;

use App\Models\Student;
use App\Models\User;
use App\Models\WhatsAppInboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.driver' => 'meta',
            'whatsapp.meta.phone_number_id' => '1260204063847643',
            'whatsapp.meta.access_token' => 'test-token',
            'whatsapp.meta.app_secret' => 'test-secret',
            'whatsapp.webhook.verify_token' => 'verify-me',
            'whatsapp.templates.enabled' => true,
            'whatsapp.log_to_database' => false,
        ]);
    }

    public function test_webhook_verification_returns_the_challenge(): void
    {
        $response = $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345');

        $response->assertOk();
        $this->assertSame('12345', $response->getContent());
    }

    public function test_webhook_rejects_a_bad_signature(): void
    {
        $this->postJson('/webhooks/whatsapp', ['object' => 'whatsapp_business_account'], [
            'X-Hub-Signature-256' => 'sha256=nope',
        ])->assertForbidden();
    }

    public function test_webhook_stores_a_parent_reply_and_links_the_student(): void
    {
        $student = Student::query()->create([
            'name' => 'Rahul',
            'parent1_name' => 'Anita',
            'parent1_mobile' => '8826962333',
            'school_name' => 'Test School',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '1260204063847643'],
                        'contacts' => [[
                            'wa_id' => '918826962333',
                            'profile' => ['name' => 'Anita'],
                        ]],
                        'messages' => [[
                            'from' => '918826962333',
                            'id' => 'wamid.incoming1',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => 'Received the work'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        ], $body)->assertOk();

        $this->assertDatabaseHas('whatsapp_inbox_messages', [
            'direction' => 'in',
            'mobile' => '918826962333',
            'student_id' => $student->id,
            'body' => 'Received the work',
            'meta_message_id' => 'wamid.incoming1',
        ]);
    }

    public function test_admin_reply_uses_a_session_message_inside_24_hours(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.reply1']],
            ], 200),
        ]);

        WhatsAppInboxMessage::query()->create([
            'direction' => 'in',
            'mobile' => '918826962333',
            'body' => 'Received the work',
            'received_at' => now()->subHour(),
            'status' => 'received',
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('admin.whatsapp-inbox.reply', ['mobile' => '918826962333']), [
                'body' => 'Please complete it today.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return ($request['type'] ?? null) === 'text'
                && $request['text']['body'] === 'Please complete it today.';
        });

        $this->assertDatabaseHas('whatsapp_inbox_messages', [
            'direction' => 'out',
            'mobile' => '918826962333',
            'body' => 'Please complete it today.',
            'status' => 'sent',
        ]);
    }

    public function test_admin_reply_is_blocked_after_24_hours(): void
    {
        Http::fake();

        WhatsAppInboxMessage::query()->create([
            'direction' => 'in',
            'mobile' => '918826962333',
            'body' => 'Received the work',
            'received_at' => now()->subHours(25),
            'status' => 'received',
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->from(route('admin.whatsapp-inbox.index'))
            ->post(route('admin.whatsapp-inbox.reply', ['mobile' => '918826962333']), [
                'body' => 'Still there?',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('body');

        Http::assertNothingSent();
    }
}
