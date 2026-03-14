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
            $table->string('old_status');
            $table->string('temp_flag');
            $table->timestamps();
        });

        // Blueprint::dropColumn() with array argument
        Schema::table('posts', static function (Blueprint $table) {
            $table->dropColumn(['slug', 'legacy_field']);
        });

        // Schema::dropColumns() with array argument (no closure)
        Schema::dropColumns('posts', ['old_status', 'temp_flag']);
    }
};
