<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('owners', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('string_filters', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id');
        });

        Schema::create('user_filters', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('user_id')->unsigned()->index();
            $table->string('filter_type');
            $table->string('line_1');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_filters');
        Schema::dropIfExists('string_filters');
        Schema::dropIfExists('owners');
    }
};
