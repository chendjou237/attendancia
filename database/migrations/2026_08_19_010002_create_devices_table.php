<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            // §3: one device per corridor.
            $table->foreignId('corridor_id')->unique()->constrained()->restrictOnDelete();
            $table->string('serial')->unique();
            $table->string('ip')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('firmware')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->integer('last_time_offset_seconds')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
