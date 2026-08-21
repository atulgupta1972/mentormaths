<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IndiaPincodeLookup
{
    /**
     * Look up Indian PIN code via India Post public API.
     *
     * @return array{ok: bool, pin_code: string, state: ?string, city: ?string, area: ?string, message: ?string}
     */
    public function lookup(string $pinCode): array
    {
        $pin = preg_replace('/\D+/', '', $pinCode) ?? '';

        if (strlen($pin) !== 6) {
            return [
                'ok' => false,
                'pin_code' => $pin,
                'state' => null,
                'city' => null,
                'area' => null,
                'message' => 'Enter a valid 6-digit PIN code.',
            ];
        }

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get("https://api.postalpincode.in/pincode/{$pin}");

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'pin_code' => $pin,
                    'state' => null,
                    'city' => null,
                    'area' => null,
                    'message' => 'PIN lookup service unavailable. Enter city and state manually.',
                ];
            }

            $payload = $response->json();
            $row = is_array($payload) ? ($payload[0] ?? null) : null;
            $offices = is_array($row) ? ($row['PostOffice'] ?? null) : null;

            if (! is_array($offices) || $offices === [] || ($row['Status'] ?? '') !== 'Success') {
                return [
                    'ok' => false,
                    'pin_code' => $pin,
                    'state' => null,
                    'city' => null,
                    'area' => null,
                    'message' => 'No post office found for this PIN. Check the code or enter city/state manually.',
                ];
            }

            $office = $offices[0];

            return [
                'ok' => true,
                'pin_code' => $pin,
                'state' => $office['State'] ?? null,
                'city' => $office['District'] ?? ($office['Block'] ?? null),
                'area' => $office['Name'] ?? null,
                'message' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('PIN code lookup failed', ['pin' => $pin, 'error' => $e->getMessage()]);

            return [
                'ok' => false,
                'pin_code' => $pin,
                'state' => null,
                'city' => null,
                'area' => null,
                'message' => 'PIN lookup failed. Enter city and state manually.',
            ];
        }
    }
}
