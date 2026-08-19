<?php

namespace App\Services\Hikvision;

use App\Models\Device;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The one HTTP call in the backfill pagination loop, pulled out on its
 * own so BackfillHikvisionEvents' pagination/dedup logic — the part
 * that's actually this project's code, not Hikvision's wire protocol —
 * can be tested independently of it. Guzzle's real digest-auth
 * middleware runs its challenge/response handshake even against
 * Http::fake(), and a POST request's body doesn't survive that
 * handshake in the fake handler, so there is no way to exercise this
 * exact HTTP call in tests without a live device — that's true of the
 * whole request/response shape here (§13.5, unverified), not just the
 * auth layer.
 *
 * @return array{InfoList: array<int, array<string, mixed>>, responseStatusStrg: ?string}
 */
class AcsEventFetcher
{
    public function fetch(Device $device, string $user, string $pass, array $condition): array
    {
        // connectTimeout() only bounds establishing the TCP connection —
        // an unreachable host fails before that with ConnectionException
        // rather than a Response.
        try {
            $response = Http::withDigestAuth($user, $pass)
                ->connectTimeout(10)
                ->timeout(30)
                ->post("http://{$device->ip}/ISAPI/AccessControl/AcsEvent?format=json", [
                    'AcsEventCond' => $condition,
                ]);
        } catch (ConnectionException $e) {
            throw new AcsEventFetchException("Could not connect: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            throw new AcsEventFetchException("AcsEvent request failed: HTTP {$response->status()}");
        }

        $body = $response->json('AcsEventResponse') ?? $response->json() ?? [];

        return [
            'InfoList' => $body['InfoList'] ?? [],
            'responseStatusStrg' => $body['responseStatusStrg'] ?? null,
        ];
    }
}
