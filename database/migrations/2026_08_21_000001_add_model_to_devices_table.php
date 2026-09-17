<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which terminal model sits in each corridor. Nothing in the ingestion
 * code branches on this — DS-K1A8603 and DS-K1T8005EFX speak identical
 * ISAPI — but the fleet is now mixed, and the models differ in what
 * they physically accept: the DS-K1T8005EFX has a card reader as well
 * as a fingerprint sensor. Knowing which corridor has one tells an
 * Officer where a teacher might badge and wrongly believe they checked
 * in (see EventProcessor: card events are stored but never counted).
 *
 * Deliberately a free-text column rather than an enum: the fleet will
 * grow models this codebase has never heard of, and an unrecognised
 * model should be recordable, not rejected. The admin form suggests the
 * known ones without constraining them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('model')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};
