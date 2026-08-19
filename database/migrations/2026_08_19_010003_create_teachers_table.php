<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->string('staff_no')->unique();
            $table->string('full_name');
            // Only hourly-paid teachers' totals drive salary (§1); salaried
            // staff are still measured, for oversight.
            $table->string('employment_type');
            $table->date('active_from');
            $table->date('active_to')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
