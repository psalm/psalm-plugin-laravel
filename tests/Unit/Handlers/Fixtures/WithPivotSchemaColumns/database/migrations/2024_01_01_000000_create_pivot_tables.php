<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Laravel's default joining table for Team <-> User.
        Schema::create('team_user', function (Blueprint $table): void {
            $table->foreignId('team_id');
            $table->foreignId('user_id');
            $table->string('role');
            $table->string('note')->nullable();
            $table->integer('rank');
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->foreignId('team_id');
            $table->foreignId('user_id');
            $table->boolean('admin');
        });

        // The conventional table of Crew <-> User, which Crew::joiningTable() overrides.
        Schema::create('crew_user', function (Blueprint $table): void {
            $table->integer('admin');
        });

        // morphToMany(Tag::class, 'taggable') joins the plural of the morph name.
        Schema::create('taggables', function (Blueprint $table): void {
            $table->foreignId('tag_id');
            $table->morphs('taggable');
            $table->float('weight');
        });
    }
};
