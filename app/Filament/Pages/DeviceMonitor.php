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
use Illuminate\Contracts\Support\Htmlable;
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

    protected string $view = 'filament.pages.device-monitor';

    public ?int $selectedDeviceId = null;

    public ?int $selectedEnrolmentId = null;

    /**
     * How many rows the live feed shows. 25 was too short to answer the
     * question staff actually bring to this page — "did this teacher
     * scan, and when?" — because a single busy period between classes
     * fills it, and every scan also emits companion events the device
     * reports under other sub-types, so 25 rows can be well under ten
     * actual people.
     */
    public int $feedLimit = self::DEFAULT_FEED_LIMIT;

    /**
     * Hides the events no fingerprint resolved: the device's own
     * heartbeats and the companion rows around each tap, which crowd out
     * the scans when you are reading the feed to see who came through.
     */
    public bool $onlyIdentified = false;

    public const FEED_LIMITS = [25, 50, 100, 250, 500];

    public const DEFAULT_FEED_LIMIT = 100;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'officer']) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.nav.device_monitor');
    }

    public function getTitle(): string|Htmlable
    {
        return __('panel.nav.device_monitor');
    }

    public function mount(): void
    {
        $this->selectedDeviceId = Device::query()->where('is_active', true)->value('id');
    }

    /** @return Collection<int, array{id:int,serial:string,model:?string,corridor:string,ip:?string,last_seen_at:?\Carbon\Carbon,status:string}> */
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
                    'model' => $device->model,
                    'corridor' => $device->corridor->name,
                    'ip' => $device->ip,
                    'is_active' => $device->is_active,
                    'last_seen_at' => $device->last_seen_at,
                    'status' => $status,
                ];
            });
    }

    /**
     * Ordered by id, not by scan time: id is the insertion order and is
     * indexed, which keeps a feed that polls every three seconds off a
     * filesort over an append-only table that only ever grows. For live
     * events the two orders are the same; a backfill is the exception,
     * and there the ingestion order is arguably the more useful thing to
     * see — those rows appearing at the top is the backfill landing.
     *
     * @return Collection<int, RawEvent>
     */
    public function getRecentEventsProperty(): Collection
    {
        return RawEvent::query()
            ->with(['teacher', 'device'])
            ->when($this->onlyIdentified, fn ($q) => $q->whereNotNull('teacher_id'))
            ->latest('id')
            ->limit($this->resolvedFeedLimit())
            ->get();
    }

    /**
     * Livewire properties come from the browser, so the limit is a value
     * a viewer can set to anything. Only the offered sizes are honoured;
     * anything else falls back to the default rather than becoming an
     * unbounded query against raw_events.
     */
    public function resolvedFeedLimit(): int
    {
        return in_array($this->feedLimit, self::FEED_LIMITS, true)
            ? $this->feedLimit
            : self::DEFAULT_FEED_LIMIT;
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
            Notification::make()->title(__('panel.pages.device_monitor.notification_pick_device_teacher'))->warning()->send();

            return;
        }

        $simulator->scan($device, $enrolment->biometric_id);

        Notification::make()
            ->title(__('panel.pages.device_monitor.notification_scan_recorded', [
                'teacher' => $enrolment->teacher->full_name,
                'device' => $device->serial,
            ]))
            ->success()
            ->send();
    }
}
