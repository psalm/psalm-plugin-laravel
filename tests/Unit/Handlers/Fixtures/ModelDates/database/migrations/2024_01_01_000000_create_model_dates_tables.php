<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // `$timestamps = false`: the created/updated columns exist but Eloquent does not treat them as dates.
        Schema::create('snapshots', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        // `CREATED_AT = 'created'`, `UPDATED_AT = null`: the default `updated_at` column is no date column.
        Schema::create('entries', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('created')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('memos', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('journals', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('ledgers', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('logged_at');
        });

        Schema::create('moodies', function (Blueprint $table): void {
            $table->id();
            $table->boolean('active');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('flag');
            $table->enum('level', ['1', '2']);
            $table->string('sku');
        });

        // Laravel's own `make:notifications-table` migration, for DatabaseNotification.
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
};
