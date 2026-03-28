<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();

            // FK to a UUID model — should resolve to string, not int
            $table->foreignIdFor(\App\Models\UuidModel::class);

            // FK to a ULID model — should resolve to string, not int
            $table->foreignIdFor(\App\Models\UlidModel::class);

            // FK to a standard int PK model — should stay unsigned int
            $table->foreignIdFor(\App\Models\User::class);

            // FK to a UUID model with custom column name — should still resolve to string
            $table->foreignIdFor(\App\Models\UuidModel::class, 'reviewer_id');

            // Nullable FK to a UUID model
            $table->foreignIdFor(\App\Models\UuidModel::class, 'editor_id')->nullable();

            // FK to a model with custom $primaryKey
            $table->foreignIdFor(\App\Models\CustomPkUuidModel::class);

            $table->timestamps();
        });
    }
};
