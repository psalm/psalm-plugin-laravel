<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('to_array_cast_models', function (Blueprint $table): void {
            $table->string('status');
            $table->string('price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('to_array_cast_models');
    }
};
