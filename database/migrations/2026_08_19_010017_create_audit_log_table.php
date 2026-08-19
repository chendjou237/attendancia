<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('entity');
            $table->unsignedBigInteger('entity_id');
            $table->string('action');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->timestamp('at');

            $table->index(['entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
