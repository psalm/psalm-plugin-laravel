<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * This migration references UuidModel::class BEFORE the uuid_models table is created.
 * The FK should fall back to unsigned int because the PK type cannot be determined.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\UuidModel::class);
            $table->timestamps();
        });
    }
};
