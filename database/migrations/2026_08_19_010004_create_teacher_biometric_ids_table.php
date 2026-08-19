<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // History of employeeNoString -> teacher mappings. Re-enrolment after
        // a worn fingerprint changes the device-side id; without this history
        // a re-enrolled teacher's past raw_events become permanently orphaned.
        //
        // Invariant (enforced in App\Services\Attendance\TeacherBiometricIdAssigner,
        // not the DB, since a partial unique index isn't portable across engines):
        // at most one row per biometric_id may have valid_to = null at a time.
        Schema::create('teacher_biometric_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->string('biometric_id');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index('biometric_id');
            $table->index(['teacher_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_biometric_ids');
    }
};
