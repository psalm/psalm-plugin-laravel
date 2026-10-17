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

        // Default joining table of Team <-> User (alphabetical, singular): `team_user`.
        Schema::create('team_user', function (Blueprint $table): void {
            $table->foreignId('team_id');
            $table->foreignId('user_id');
            $table->string('role');
            $table->integer('level')->nullable();
            $table->timestamps();
        });

        // Explicitly named pivot table (no default joining-table convention applies).
        Schema::create('team_memberships', function (Blueprint $table): void {
            $table->foreignId('team_id');
            $table->foreignId('user_id');
            $table->string('title');
            $table->boolean('is_owner');
        });

        // morphToMany(Tag::class, 'taggable') default table: `taggables`.
        Schema::create('taggables', function (Blueprint $table): void {
            $table->foreignId('tag_id');
            $table->morphs('taggable');
            $table->string('note');
        });

        Schema::create('stamped_teams', function (Blueprint $table): void {
            $table->id();
        });

        // Renamed (nullable) timestamp columns, matching StampedTeam::CREATED_AT / UPDATED_AT.
        Schema::create('stamped_team_user', function (Blueprint $table): void {
            $table->foreignId('stamped_team_id');
            $table->foreignId('user_id');
            $table->timestamp('made_at')->nullable();
            $table->timestamp('changed_at')->nullable();
        });

        Schema::create('no_created_at_teams', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('no_created_at_team_user', function (Blueprint $table): void {
            $table->foreignId('no_created_at_team_id');
            $table->foreignId('user_id');
            $table->string('role');
            $table->timestamps();
        });

        // Columns typed by CastedPivot::$casts: `status` (backed enum), `is_active` (boolean),
        // `approved_at` (datetime), `meta` (array, nullable).
        Schema::create('membership_details', function (Blueprint $table): void {
            $table->foreignId('team_id');
            $table->foreignId('user_id');
            $table->string('status');
            $table->boolean('is_active');
            $table->dateTime('approved_at');
            $table->json('meta')->nullable();
        });

        Schema::create('special_teams', function (Blueprint $table): void {
            $table->id();
        });

        // Default joining table of SpecialTeam <-> User (alphabetical, singular). Same column names as
        // `team_user` with different types, so a call site that resolved the declaring class's table
        // (`team_user`) instead of the receiver's would read `role:string`.
        Schema::create('special_team_user', function (Blueprint $table): void {
            $table->foreignId('special_team_id');
            $table->foreignId('user_id');
            $table->integer('role');
            $table->string('level')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_team_user');
        Schema::dropIfExists('special_teams');
        Schema::dropIfExists('membership_details');
        Schema::dropIfExists('no_created_at_team_user');
        Schema::dropIfExists('no_created_at_teams');
        Schema::dropIfExists('stamped_team_user');
        Schema::dropIfExists('stamped_teams');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('team_memberships');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('users');
        Schema::dropIfExists('teams');
    }
};
