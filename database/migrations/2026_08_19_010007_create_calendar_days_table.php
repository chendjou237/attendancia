<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            // teaching | public_holiday | school_closure | half_day | classes_suspended
            $table->string('day_type');
            $table->text('note')->nullable();
            // §13.2: which slots survive on a half-day.
            $table->foreignId('half_day_cutoff_slot_id')->nullable()
                ->constrained('period_slots')->nullOnDelete();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_days');
    }
};
