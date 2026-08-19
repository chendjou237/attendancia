<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only (§14): no updates, no deletes, ever. Only created_at,
        // deliberately no updated_at, to make that intent structural.
        Schema::create('raw_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_serial');
            $table->unsignedBigInteger('device_event_serial');
            // Raw device identity (employeeNoString). Kept even when it
            // resolves to no teacher — unmatched scans are the most
            // valuable diagnostic data in the system.
            $table->string('biometric_id')->nullable();
            $table->timestamp('event_time_device')->nullable();
            $table->timestamp('event_time_server');
            $table->unsignedTinyInteger('major_event_type')->nullable();
            $table->unsignedTinyInteger('sub_event_type')->nullable();
            $table->json('payload_json')->nullable();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['device_serial', 'device_event_serial']);
            $table->index('biometric_id');
            $table->index('event_time_server');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_events');
    }
};
