<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('timetable_versions')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->foreignId('slot_id')->constrained('period_slots')->restrictOnDelete();
            $table->foreignId('class_code_id')->constrained('class_codes')->restrictOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->timestamps();

            // A teacher can only be in one place per slot within one version.
            $table->unique(['version_id', 'day_of_week', 'slot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_entries');
    }
};
