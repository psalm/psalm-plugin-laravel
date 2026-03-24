<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('body');
            // foreignIdFor with a namespaced string literal (no second arg) —
            // the plugin cannot resolve this statically and should skip it
            $table->foreignIdFor('App\Models\User');
            $table->timestamps();
        });
    }
};
