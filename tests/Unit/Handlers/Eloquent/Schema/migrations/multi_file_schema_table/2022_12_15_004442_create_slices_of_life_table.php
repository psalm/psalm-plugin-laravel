<?php

// Migration that creates one table and alters another — a common Laravel pattern.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('slice_of_lives', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->unsignedBigInteger('slice_of_life_id')->nullable()->after('journal_id');
        });
    }
};
