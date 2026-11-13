<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->string('legacy_a');
            $table->string('legacy_b');
            $table->string('legacy_c');
            $table->string('kept');
        });

        Schema::table('articles', static function (Blueprint $table) {
            // Variadic form: every string argument is dropped, not only the first.
            $table->dropColumn('slug', 'legacy_a', 'legacy_b');
            $table->dropColumn('legacy_c');
            $table->dropVectorIndex('articles_embedding_vector_index');
        });
    }
};
