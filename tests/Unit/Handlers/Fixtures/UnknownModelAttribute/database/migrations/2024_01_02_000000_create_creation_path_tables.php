<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id');
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->timestamps();
        });

        // The pivot columns are deliberately absent from `tags`/`posts`: they are only valid as the
        // second argument of BelongsToMany::create(), never as related-model attributes.
        Schema::create('post_tag', function (Blueprint $table): void {
            $table->foreignId('post_id');
            $table->foreignId('tag_id');
            $table->integer('pivot_col');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('authors');
    }
};
