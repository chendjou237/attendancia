<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corridor_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->timestamps();

            $table->unique(['corridor_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
