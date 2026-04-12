<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            // foreignIdFor with a string column name override as second arg
            $table->foreignIdFor(\App\Models\Customer::class, 'author_id');
            $table->timestamps();
        });
    }
};
