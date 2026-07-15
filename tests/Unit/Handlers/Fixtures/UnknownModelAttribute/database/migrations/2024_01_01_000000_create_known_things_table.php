<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('known_things', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        Schema::create('dynamic_schema_things', function (Blueprint $table): void {
            $column = 'real_col';
            $table->string($column);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_schema_things');
        Schema::dropIfExists('known_things');
    }
};
