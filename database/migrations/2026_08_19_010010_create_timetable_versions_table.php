<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            // draft | submitted | approved
            $table->string('state')->default('draft');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_versions');
    }
};
