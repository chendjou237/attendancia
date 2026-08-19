<?php

namespace App\Filament\Pages;

use App\Models\Device;
use App\Models\RawEvent;
use App\Models\TeacherBiometricId;
use App\Services\Demo\DeviceScanSimulator;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * §1/§7.5's "devices.last_seen_at needs a monitor so a device that is
 * genuinely down is visible rather than silent" — that column existed
 * from Phase C but had no screen. This is that screen, plus a live
 * feed of what's actually landing in raw_events, so an Officer can
 * watch the corridor's terminal work in real time instead of trusting
 * it blindly between Exception Queue sessions.
 *
 * Health is read from last_seen_at, which EventProcessor::handle()
 * already stamps on *every* inbound event — including the device's own
 * idle heartbeats, not just scans (§7.3). That's a deliberate choice
 * over this page actively pinging the device's HTTP API on every poll:
 * a synchronous ISAPI call against an unreachable device would stall
 * the whole page load for a connect timeout, which is exactly the
 * moment staff most needs the page to still work.
 *
 * "Simulate scan" exists because the pilot device isn't available yet
 * (project history) — it fires one real event through the same
 * EventNormalizer -> EventProcessor pipeline a live stream payload
 * would, via DeviceScanSimulator, so this page can be demonstrated
 * and sanity-checked without hardware.
 */
class DeviceMonitor extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Device Monitor';

    protected string $view = 'filament.pages.device-monitor';

    public ?int $selectedDeviceId = null;

    public ?int $selectedEnrolmentId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'officer']) ?? false;
    }

    public function mount(): void
    {
        $this->selectedDeviceId = Device::query()->where('is_active', true)->value('id');
    }

    /** @return Collection<int, array{id:int,serial:string,corridor:string,ip:?string,last_seen_at:?\Carbon\Carbon,status:string}> */
    public function getDevicesProperty(): Collection
    {
        $idleTimeout = (int) config('attendance.idle_timeout');

        return Device::query()
            ->with('corridor')
            ->orderBy('serial')
            ->get()
            ->map(function (Device $device) use ($idleTimeout) {
                $seconds = $device->last_seen_at?->diffInSeconds(now());

                $status = match (true) {
                    $seconds === null => 'offline',
                    $seconds <= $idleTimeout => 'online',
                    $seconds <= 3600 => 'warning',
                    default => 'offline',
                };

                return [
                    'id' => $device->id,
                    'serial' => $device->serial,
                    'corridor' => $device->corridor->name,
                    'ip' => $device->ip,
                    'is_active' => $device->is_active,
                    'last_seen_at' => $device->last_seen_at,
                    'status' => $status,
                ];
            });
    }

    /** @return Collection<int, RawEvent> */
    public function getRecentEventsProperty(): Collection
    {
        return RawEvent::query()
            ->with(['teacher', 'device'])
            ->latest('id')
            ->limit(25)
            ->get();
    }

    /** @return Collection<int, TeacherBiometricId> */
    public function getEnrolledTeachersProperty(): Collection
    {
        return TeacherBiometricId::query()
            ->whereNull('valid_to')
            ->with('teacher')
            ->get()
            ->filter(fn (TeacherBiometricId $mapping) => $mapping->teacher !== null)
            ->sortBy(fn (TeacherBiometricId $mapping) => $mapping->teacher->full_name)
            ->values();
    }

    public function simulateScan(DeviceScanSimulator $simulator): void
    {
        $device = Device::find($this->selectedDeviceId);
        $enrolment = TeacherBiometricId::with('teacher')->find($this->selectedEnrolmentId);

        if ($device === null || $enrolment === null || $enrolment->teacher === null) {
            Notification::make()->title('Pick a device and a teacher first')->warning()->send();

            return;
        }

        $simulator->scan($device, $enrolment->biometric_id);

        Notification::make()
            ->title("Scan recorded — {$enrolment->teacher->full_name} on {$device->serial}")
            ->success()
            ->send();
    }
}
