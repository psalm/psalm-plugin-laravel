<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->integer('generic_price');
            $table->integer('native_price');
            $table->integer('documented_price');
            $table->integer('trait_self');
            $table->integer('trait_static');
            $table->integer('static_base');
            $table->integer('parent_ret');
            $table->integer('inherited_doc');
            $table->integer('child_trait_self');
            $table->integer('child_trait_parent');
            $table->integer('multi');
            $table->integer('intersection');
            $table->integer('intersection_cs');
            $table->integer('self_casting');
            $table->integer('dual');
            $table->integer('dual_castable');
            $table->integer('child_native');
        });
    }
};
