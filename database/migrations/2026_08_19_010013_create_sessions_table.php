<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->foreignId('class_code_id')->constrained('class_codes')->restrictOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('corridor_id')->constrained('corridors')->restrictOnDelete();
            $table->foreignId('first_slot_id')->constrained('period_slots')->restrictOnDelete();
            $table->foreignId('last_slot_id')->constrained('period_slots')->restrictOnDelete();
            // Deliberately NOT unique: one boundary scan may close session A
            // and open session B for a back-to-back class change (decision:
            // "one raw_event may serve as both scan-out of A and scan-in of B").
            $table->foreignId('scan_in_event_id')->nullable()->constrained('raw_events')->nullOnDelete();
            $table->foreignId('scan_out_event_id')->nullable()->constrained('raw_events')->nullOnDelete();
            $table->string('state'); // paired | unpaired
            $table->string('anomaly_code')->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
