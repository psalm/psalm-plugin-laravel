<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedInteger('stock');
            $table->unsignedSmallInteger('priority');
            $table->unsignedTinyInteger('rating');
            $table->unsignedMediumInteger('views');
            $table->foreignId('user_id');
            $table->increments('legacy_id');
            $table->bigIncrements('big_legacy_id');
            $table->tinyIncrements('tiny_legacy_id');
            $table->smallIncrements('small_legacy_id');
            $table->mediumIncrements('medium_legacy_id');
            $table->integerIncrements('int_legacy_id');
            $table->integer('signed_quantity');
            $table->bigInteger('signed_big');
            $table->integer('made_unsigned')->unsigned();
            $table->morphs('taggable');
            $table->nullableMorphs('imageable');
        });
    }
};
