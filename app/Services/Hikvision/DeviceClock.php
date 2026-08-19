<?php

namespace App\Services\Hikvision;

use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * §7.5: "record event_time_device and event_time_server... store the
 * offset on each poll... alert when offset exceeds threshold." The
 * exact ISAPI response shape for System/time is unverified against
 * this firmware (§13.5) — tries the documented 'time' key first, falls
 * back to a couple of other plausible ones, and gives up loudly rather
 * than guessing if none match.
 */
class DeviceClock
{
    public function poll(Device $device, string $user, string $pass): void
    {
        try {
            $response = Http::withDigestAuth($user, $pass)
                ->connectTimeout(10)
                ->get("http://{$device->ip}/ISAPI/System/time?format=json");

            if (! $response->successful()) {
                Log::channel('attendance')->warning('DeviceClock: System/time request failed', [
                    'device_id' => $device->id,
                    'status' => $response->status(),
                ]);

                return;
            }

            $body = $response->json();
            $deviceTimeString = $body['Time']['localTime']
                ?? $body['systemTime']
                ?? $body['time']
                ?? null;

            if ($deviceTimeString === null) {
                Log::channel('attendance')->warning('DeviceClock: could not find a time field in System/time response — unverified field names (§13.5)', [
                    'device_id' => $device->id,
                    'body' => $body,
                ]);

                return;
            }

            // Explicit timestamp subtraction, not diffInSeconds(): Carbon's
            // diff*() sign convention is easy to get backwards (see
            // PairingEngine's history) — this is unambiguous. Positive
            // means the device is ahead of server time, negative behind.
            $deviceTime = Carbon::parse($deviceTimeString);
            $offsetSeconds = $deviceTime->getTimestamp() - now()->getTimestamp();

            $device->forceFill(['last_time_offset_seconds' => $offsetSeconds])->save();

            $threshold = (int) config('attendance.clock_drift_threshold_seconds');

            if (abs($offsetSeconds) > $threshold) {
                Log::channel('attendance')->warning('Device clock drift exceeds threshold', [
                    'device_id' => $device->id,
                    'offset_seconds' => $offsetSeconds,
                    'threshold_seconds' => $threshold,
                ]);
            }
        } catch (Throwable $e) {
            Log::channel('attendance')->warning('DeviceClock: failed to poll device time', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
