<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ulid_models', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
        });
    }
};
