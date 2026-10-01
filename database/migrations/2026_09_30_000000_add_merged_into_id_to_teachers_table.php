<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A teacher created twice by mistake. The duplicate can't be deleted once
 * attendance has been computed for it (sessions and period_results hold it
 * with RESTRICT), and its scans can't be moved — raw_events is append-only.
 * So the duplicate is kept, deactivated, and pointed at the real record:
 * pairing for the real teacher reads the duplicate's scans too (see
 * Teacher::scanSourceIds()). App\Services\Attendance\TeacherDeactivator
 * is the only writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->after('active_to')
                ->constrained('teachers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            // dropConstrainedForeignId drops the key before the column, the
            // order MySQL needs (errno 1553 otherwise; see
            // 2026_08_21_000000_add_pairing_index_to_raw_events_table).
            $table->dropConstrainedForeignId('merged_into_id');
        });
    }
};
