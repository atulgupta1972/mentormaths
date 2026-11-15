<?php

namespace App\Services;

use App\Models\Student;
use App\Models\WhatsAppInboxMessage;
use App\Support\WhatsApp\WhatsAppPhone;
use App\Support\WhatsApp\WhatsAppSender;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WhatsAppInboxService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(array $payload): int
    {
        $stored = 0;
        $expectedPhoneNumberId = (string) config('whatsapp.meta.phone_number_id');

        foreach ($payload['entry'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($entry['changes'] ?? [] as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $value = $change['value'] ?? null;
                if (! is_array($value)) {
                    continue;
                }

                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                if ($expectedPhoneNumberId !== '' && $phoneNumberId !== '' && $phoneNumberId !== $expectedPhoneNumberId) {
                    continue;
                }

                $names = $this->contactNames(is_array($value['contacts'] ?? null) ? $value['contacts'] : []);

                foreach ($value['messages'] ?? [] as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    if ($this->storeIncoming($message, $names)) {
                        $stored++;
                    }
                }
            }
        }

        return $stored;
    }

    /**
     * @return array{sent: bool, error: ?string}
     */
    public function reply(string $mobile, string $body): array
    {
        $mobile = WhatsAppPhone::normalize($mobile) ?? '';

        if (! WhatsAppPhone::isValid($mobile)) {
            return ['sent' => false, 'error' => 'That mobile number is not valid.'];
        }

        if (! $this->sessionOpen($mobile)) {
            return ['sent' => false, 'error' => 'This parent last wrote more than 24 hours ago. Ask them to send a new WhatsApp message, then reply here.'];
        }

        $match = $this->matchStudent($mobile);
        $result = WhatsAppSender::sendText('inbox_reply', $mobile, $body, [
            'session_reply' => true,
            'recipient_label' => $match['label'],
            'student_id' => $match['student_id'],
        ]);

        WhatsAppInboxMessage::query()->create([
            'direction' => WhatsAppInboxMessage::DIRECTION_OUT,
            'mobile' => $mobile,
            'contact_name' => $match['contact_name'],
            'recipient_label' => $match['label'],
            'student_id' => $match['student_id'],
            'body' => $body,
            'meta_message_id' => $result['message_id'],
            'received_at' => now(),
            'status' => $result['sent'] ? WhatsAppInboxMessage::STATUS_SENT : WhatsAppInboxMessage::STATUS_FAILED,
            'error' => $result['error'],
        ]);

        if (! $result['sent']) {
            return ['sent' => false, 'error' => 'WhatsApp could not send the reply. Try again in a moment.'];
        }

        return ['sent' => true, 'error' => null];
    }

    public function sessionOpen(string $mobile): bool
    {
        $latest = WhatsAppInboxMessage::query()
            ->where('mobile', $mobile)
            ->where('direction', WhatsAppInboxMessage::DIRECTION_IN)
            ->orderByDesc('id')
            ->first();

        $at = $latest?->received_at ?? $latest?->created_at;

        return $at instanceof Carbon && $at->greaterThan(now()->subHours(24));
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     * @return array<string, string>
     */
    private function contactNames(array $contacts): array
    {
        $names = [];

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $waId = WhatsAppPhone::normalize((string) ($contact['wa_id'] ?? ''));
            $name = trim((string) ($contact['profile']['name'] ?? ''));

            if ($waId && $name !== '') {
                $names[$waId] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, string>  $names
     */
    private function storeIncoming(array $message, array $names): bool
    {
        $metaId = trim((string) ($message['id'] ?? ''));
        if ($metaId !== '' && WhatsAppInboxMessage::query()->where('meta_message_id', $metaId)->exists()) {
            return false;
        }

        $mobile = WhatsAppPhone::normalize((string) ($message['from'] ?? ''));
        if (! $mobile || ! WhatsAppPhone::isValid($mobile)) {
            Log::info('WhatsApp inbox skipped a message with an unusable sender.', [
                'from' => $message['from'] ?? null,
            ]);

            return false;
        }

        $body = $this->bodyFromMessage($message);
        if ($body === '') {
            return false;
        }

        $match = $this->matchStudent($mobile);
        $timestamp = isset($message['timestamp']) ? (int) $message['timestamp'] : null;

        WhatsAppInboxMessage::query()->create([
            'direction' => WhatsAppInboxMessage::DIRECTION_IN,
            'mobile' => $mobile,
            'contact_name' => $names[$mobile] ?? $match['contact_name'],
            'recipient_label' => $match['label'],
            'student_id' => $match['student_id'],
            'body' => $body,
            'meta_message_id' => $metaId !== '' ? $metaId : null,
            'received_at' => $timestamp ? Carbon::createFromTimestamp($timestamp) : now(),
            'status' => WhatsAppInboxMessage::STATUS_RECEIVED,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function bodyFromMessage(array $message): string
    {
        $type = (string) ($message['type'] ?? 'unknown');

        $body = match ($type) {
            'text' => (string) ($message['text']['body'] ?? ''),
            'button' => (string) ($message['button']['text'] ?? '[Button]'),
            'interactive' => (string) (
                $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? '[Reply]'
            ),
            'image' => trim('[Image] '.($message['image']['caption'] ?? '')),
            'video' => trim('[Video] '.($message['video']['caption'] ?? '')),
            'document' => trim('[Document] '.($message['document']['filename'] ?? $message['document']['caption'] ?? '')),
            'audio' => '[Audio]',
            'sticker' => '[Sticker]',
            'location' => '[Location]',
            'contacts' => '[Contact]',
            default => '['.ucfirst($type).']',
        };

        return trim($body);
    }

    /**
     * @return array{student_id: ?int, label: ?string, contact_name: ?string}
     */
    private function matchStudent(string $mobile): array
    {
        $last10 = substr($mobile, -10);
        $student = Student::query()
            ->where('student_mobile', 'like', '%'.$last10)
            ->orWhere('parent1_mobile', 'like', '%'.$last10)
            ->orWhere('parent2_mobile', 'like', '%'.$last10)
            ->first();

        if (! $student) {
            return ['student_id' => null, 'label' => null, 'contact_name' => null];
        }

        $pairs = [
            ['mobile' => $student->student_mobile, 'label' => 'Student', 'name' => $student->name],
            ['mobile' => $student->parent1_mobile, 'label' => 'Parent 1', 'name' => $student->parent1_name],
            ['mobile' => $student->parent2_mobile, 'label' => 'Parent 2', 'name' => $student->parent2_name],
        ];

        foreach ($pairs as $pair) {
            $normalized = WhatsAppPhone::normalize($pair['mobile']);
            if ($normalized !== $mobile) {
                continue;
            }

            $name = filled($pair['name']) ? (string) $pair['name'] : null;

            return [
                'student_id' => $student->id,
                'label' => $name ? $pair['label'].' ('.$name.')' : $pair['label'],
                'contact_name' => $name,
            ];
        }

        return [
            'student_id' => $student->id,
            'label' => $student->name,
            'contact_name' => $student->name,
        ];
    }
}
