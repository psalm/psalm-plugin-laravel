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
            $table->string('slug');
            $table->text('body');
            $table->string('legacy_field');
            $table->timestamps();
        });

        Schema::table('posts', static function (Blueprint $table) {
            $table->dropColumn(['slug', 'legacy_field']);
        });
    }
};
