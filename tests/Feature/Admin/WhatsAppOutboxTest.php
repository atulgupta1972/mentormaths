<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_whatsapp_outbox(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        WhatsAppMessage::query()->create([
            'channel' => 'progress_summary',
            'to_mobile' => '919876543210',
            'recipient_label' => 'Parent 1',
            'student_id' => null,
            'message_body' => 'Progress report for Vishvesh',
            'template_name' => 'mentor_maths_progress',
            'meta_message_id' => 'wamid.test',
            'status' => WhatsAppMessage::STATUS_SENT,
            'error' => null,
            'driver' => 'meta',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-outbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/WhatsApp/Outbox')
                ->has('messages.data', 1)
                ->where('messages.data.0.to_mobile', '919876543210')
                ->where('messages.data.0.status', 'sent')
            );
    }
}
