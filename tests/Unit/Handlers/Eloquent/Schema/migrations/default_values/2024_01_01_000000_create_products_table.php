<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->integer('quantity')->default(0);
            $table->float('price')->default(9.99);
            $table->boolean('active')->default(true);
            $table->boolean('featured')->default(false);
            $table->string('description')->nullable()->default(null);
            $table->integer('negative')->default(-1);
            $table->string('no_default');
            $table->float('discount')->default(-0.5);
            $table->timestamp('published_at')->default(new Expression('NOW()'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
