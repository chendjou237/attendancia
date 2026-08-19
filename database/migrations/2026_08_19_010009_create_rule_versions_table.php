<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('grace_late_minutes');
            $table->unsignedSmallInteger('grace_early_minutes');
            $table->unsignedSmallInteger('pair_window_before_minutes');
            $table->unsignedSmallInteger('pair_window_after_minutes');
            // Debounce: successive scans from the same teacher inside this
            // window collapse to one at pairing time (finding 1.4).
            $table->unsignedSmallInteger('min_scan_gap_seconds');
            // A paired interval shorter than this is UNPAIRED, never ABSENT.
            $table->unsignedSmallInteger('min_session_minutes');
            // HR basis: 1 period = 1 hour, but versioned rather than a
            // constant so a change of basis is recomputable, not a rewrite.
            $table->decimal('hours_per_period', 4, 2)->default(1.00);
            $table->date('valid_from')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_versions');
    }
};
