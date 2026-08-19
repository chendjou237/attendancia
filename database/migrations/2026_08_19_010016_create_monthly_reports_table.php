<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_reports', function (Blueprint $table) {
            $table->id();
            // First-of-month convention.
            $table->date('month')->unique();
            // draft | officer_reviewed | principal_approved | sent_to_hr
            $table->string('state')->default('draft');
            $table->json('snapshot_json')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_to_hr_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_reports');
    }
};
