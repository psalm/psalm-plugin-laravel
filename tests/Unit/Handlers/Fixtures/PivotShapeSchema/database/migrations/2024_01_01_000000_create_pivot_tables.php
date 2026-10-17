<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('perches', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('racks', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('item_order', function (Blueprint $table): void {
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('order_id');
            $table->integer('quantity');
            $table->decimal('unit_price', 8, 2);
        });

        // The table a default derivation (`rack_user`) would name, see Rack::users(): Laravel reads `rack_slots`.
        Schema::create('rack_user', function (Blueprint $table): void {
            $table->string('role');
        });

        Schema::create('squads', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->unsignedInteger('level');
            $table->string('notes')->nullable();
            $table->enum('kind', ['lead', 'member']);
            $table->integer('score');
            $table->timestamp('added_at')->nullable();
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table): void {
            $table->unsignedBigInteger('tag_id');
            $table->unsignedBigInteger('taggable_id');
            $table->string('taggable_type');
            $table->integer('weight');
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->string('seat');
        });

        // The table a default derivation (`squad_user`) would name. Squad overrides joiningTableSegment(), so
        // Laravel really uses `seat_user` and the plugin must not read this one.
        // The table a default derivation (`perch_team`) would name, see Team::overriddenRelated().
        Schema::create('perch_team', function (Blueprint $table): void {
            $table->string('seat');
        });

        Schema::create('squad_user', function (Blueprint $table): void {
            $table->string('seat');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('squad_user');
        Schema::dropIfExists('rack_user');
        Schema::dropIfExists('item_order');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('items');
        Schema::dropIfExists('racks');
        Schema::dropIfExists('perch_team');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('squads');
        Schema::dropIfExists('perches');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('users');
        Schema::dropIfExists('teams');
    }
};
