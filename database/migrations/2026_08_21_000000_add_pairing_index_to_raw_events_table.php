<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PairingEngine::pair() is the engine's hottest read: for every session
 * it looks up one teacher's events inside that session's pairing window,
 * filtering on teacher_id plus a range over event_time_device.
 *
 * The table's existing indexes cover biometric_id and event_time_server
 * — neither of which that query touches. teacher_id alone is indexed as
 * a side effect of its foreign key, which narrows to one teacher but
 * then scans all of their events, forever, as raw_events grows
 * append-only. This composite lets the range be satisfied from the index
 * instead.
 *
 * event_time_device is nullable (the fallback to event_time_server is in
 * RawEvent::effectiveTime()); rows with a null are simply absent from
 * that portion of the index, which is correct here — the query's
 * whereBetween on event_time_device cannot match them either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_events', function (Blueprint $table) {
            $table->index(['teacher_id', 'event_time_device'], 'raw_events_pairing_lookup');
        });
    }

    public function down(): void
    {
        // MySQL auto-creates raw_events_teacher_id_foreign to back the
        // teacher_id foreign key, then drops it as redundant once this
        // composite — which has teacher_id as its leftmost column — can
        // serve the constraint instead. That leaves this index as the
        // only thing supporting the FK, so dropping it outright fails
        // with errno 1553 ("needed in a foreign key constraint").
        // Restore the FK's own index first, in its own statement so the
        // ADD commits before the DROP.
        //
        // SQLite never auto-indexes foreign keys, so there is nothing to
        // restore there and adding one would leave rollback asymmetric.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('raw_events', function (Blueprint $table) {
                $table->index('teacher_id', 'raw_events_teacher_id_foreign');
            });
        }

        Schema::table('raw_events', function (Blueprint $table) {
            $table->dropIndex('raw_events_pairing_lookup');
        });
    }
};
