<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppInboxService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, WhatsAppInboxService $inbox): Response
    {
        if ($request->isMethod('GET')) {
            return $this->verify($request);
        }

        $secret = (string) config('whatsapp.meta.app_secret');
        if ($secret !== '' && ! $this->signatureMatches($request, $secret)) {
            Log::warning('WhatsApp webhook signature did not match.');

            return response('Invalid signature', 403);
        }

        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return response('OK', 200);
        }

        $inbox->ingest($payload);

        return response('OK', 200);
    }

    private function verify(Request $request): Response
    {
        $mode = (string) ($request->query('hub_mode') ?? $request->query('hub.mode') ?? '');
        $token = (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token') ?? '');
        $challenge = (string) ($request->query('hub_challenge') ?? $request->query('hub.challenge') ?? '');
        $expected = (string) config('whatsapp.webhook.verify_token');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    private function signatureMatches(Request $request, string $secret): bool
    {
        $header = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return $header !== '' && hash_equals($expected, $header);
    }
}
