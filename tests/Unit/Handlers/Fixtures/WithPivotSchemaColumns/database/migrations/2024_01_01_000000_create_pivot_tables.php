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

        // Ledger renames its updated column to modified_at.
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->foreignId('ledger_id');
            $table->foreignId('user_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->integer('updated_at');
            $table->integer('amount');
        });

        Schema::create('memberships_stamped', function (Blueprint $table): void {
            $table->foreignId('team_id');
            $table->foreignId('user_id');
            $table->string('role');
            $table->timestamps();
        });

        Schema::create('memberships_other', function (Blueprint $table): void {
            $table->foreignId('team_id');
            $table->foreignId('user_id');
            $table->integer('role');
            $table->string('rank');
        });

        // Sheet renames its created column to made_at.
        Schema::create('sheet_entries', function (Blueprint $table): void {
            $table->foreignId('sheet_id');
            $table->foreignId('user_id');
            $table->integer('hours');
            $table->timestamp('made_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
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
