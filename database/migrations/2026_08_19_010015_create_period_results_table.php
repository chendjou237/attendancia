<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->nullable()->constrained('sessions')->nullOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->foreignId('slot_id')->constrained('period_slots')->restrictOnDelete();
            $table->foreignId('class_code_id')->constrained('class_codes')->restrictOnDelete();
            // present | present_admin | absent | absent_justified | unpaired | location_mismatch
            $table->string('status');
            // scan | administrative | override
            $table->string('source');
            $table->foreignId('rule_version_id')->constrained('rule_versions')->restrictOnDelete();
            $table->timestamp('computed_at');
            // Recomputing under the SAME rule_version_id upserts this row
            // (idempotent). Recomputing under a DIFFERENT rule_version_id
            // inserts a new row and flips this one to false, so old results
            // are never destroyed (§14) while callers can still find "the"
            // current figure for a period.
            $table->boolean('is_current')->default(true);
            $table->string('override_status')->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('override_at')->nullable();
            $table->foreignId('notice_id')->nullable()->constrained('notices')->nullOnDelete();
            $table->timestamps();

            $table->unique(['teacher_id', 'date', 'slot_id', 'rule_version_id'], 'period_results_natural_key');
            $table->index(['teacher_id', 'date', 'slot_id', 'is_current'], 'period_results_current_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_results');
    }
};
