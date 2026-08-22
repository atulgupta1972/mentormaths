<?php

namespace App\Console\Commands;

use App\Support\WhatsApp\WhatsAppPhone;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Console\Command;

class WhatsAppTestSend extends Command
{
    protected $signature = 'whatsapp:test {mobile : 10-digit mobile or full number with country code} {--message= : Optional test message}';

    protected $description = 'Send a test WhatsApp message using the configured driver';

    public function handle(): int
    {
        $mobile = (string) $this->argument('mobile');
        $message = (string) ($this->option('message') ?: 'Hello from Mentor Maths — WhatsApp test message.');

        $normalized = WhatsAppPhone::normalize($mobile);

        if (! $normalized || ! WhatsAppPhone::isValid($normalized)) {
            $this->error('Invalid mobile number. Use a 10-digit Indian mobile or include country code 91.');

            return self::FAILURE;
        }

        $this->line('Driver: '.config('whatsapp.driver'));
        $this->line('Enabled: '.(config('whatsapp.enabled') ? 'yes' : 'no'));
        $this->line('To: '.$normalized);

        if (! WhatsAppSender::canAutoSend()) {
            $this->warn('Auto-send is not active. Set WHATSAPP_ENABLED=true and WHATSAPP_DRIVER=meta (or log).');

            return self::FAILURE;
        }

        $result = WhatsAppSender::sendText('progress_summary', $mobile, $message, [
            'dashboard_url' => route('dashboard'),
        ]);

        if ($result['sent']) {
            $this->info('Sent successfully.'.($result['message_id'] ? " ID: {$result['message_id']}" : ''));

            return self::SUCCESS;
        }

        $this->error('Send failed: '.($result['error'] ?? 'unknown'));

        return self::FAILURE;
    }
}
