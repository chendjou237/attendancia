<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Versioned (finding 1.6 / plan ⚠️): every other pay-affecting input
        // is versioned so a later edit never rewrites already-computed pay.
        // Slot times set every grace-window boundary, so they must be too.
        Schema::create('period_slots', function (Blueprint $table) {
            $table->id();
            // Carbon convention: 0 = Sunday .. 6 = Saturday.
            $table->unsignedTinyInteger('day_of_week');
            $table->unsignedTinyInteger('seq');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_break')->default(false);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->unique(['day_of_week', 'seq', 'valid_from'], 'period_slots_day_seq_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_slots');
    }
};
