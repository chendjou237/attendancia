<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backs period_results.notice_id (finding 1.8: §6 referenced a
        // notice_ref with no table behind it). A notice travels with an
        // ABSENT_JUSTIFIED record; it does not change the count.
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
