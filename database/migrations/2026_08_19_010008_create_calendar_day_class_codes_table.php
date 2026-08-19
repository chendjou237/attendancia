<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scopes a classes_suspended day to specific class codes (decision:
        // "scoped to class codes", not day-wide). A classes_suspended day
        // with zero rows here suspends the whole school, matching the
        // simple national-day-rehearsal case without extra data entry.
        Schema::create('calendar_day_class_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_code_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['calendar_day_id', 'class_code_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_day_class_codes');
    }
};
