<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // This non-method-call statement (an if block) previously caused `return`,
            // silently dropping all subsequent column definitions.
            if (true) {
                $table->string('conditional_column');
            }

            // These columns should still be detected after the non-method-call statement
            $table->string('email');
            $table->decimal('total', 10, 2);
            $table->timestamps();
        });
    }
};
