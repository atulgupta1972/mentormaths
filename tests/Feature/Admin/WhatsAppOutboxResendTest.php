<?php

namespace Tests\Feature\Admin;

use App\Models\Student;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppOutboxResendTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_resend_a_failed_outbox_message(): void
    {
        $this->withoutVite();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.driver' => 'meta',
            'whatsapp.meta.phone_number_id' => '123',
            'whatsapp.meta.access_token' => 'token',
            'whatsapp.templates.enabled' => false,
            'whatsapp.log_to_database' => true,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.resend']],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $student = Student::query()->create([
            'name' => 'Vishvesh',
            'school_name' => 'DPS',
            'parent1_name' => 'Neeru',
            'parent1_mobile' => '9212689124',
            'notify_parent1_mobile' => true,
        ]);

        $message = WhatsAppMessage::query()->create([
            'channel' => 'assignment_completed',
            'to_mobile' => '919625522556',
            'recipient_label' => 'Mentor (Neeru)',
            'student_id' => $student->id,
            'message_body' => "Hello\n\nVishvesh finished Test T1.",
            'template_name' => null,
            'status' => WhatsAppMessage::STATUS_FAILED,
            'error' => '(#100) Invalid parameter',
            'driver' => 'meta',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.whatsapp-outbox.index'))
            ->post(route('admin.whatsapp-outbox.resend', $message), [
                'mobile' => '9212689124',
                'use_current_mentor' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('whatsapp_messages', [
            'channel' => 'assignment_completed',
            'to_mobile' => '919212689124',
            'status' => WhatsAppMessage::STATUS_SENT,
        ]);
    }
}
