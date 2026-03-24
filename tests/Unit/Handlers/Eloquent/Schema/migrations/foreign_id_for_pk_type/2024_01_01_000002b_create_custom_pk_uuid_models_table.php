<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('custom_pk_uuid_models', function (Blueprint $table) {
            $table->uuid('custom_pk')->primary();
            $table->timestamps();
        });
    }
};
