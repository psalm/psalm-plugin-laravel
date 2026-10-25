<?php

use AutoloadCrashFixture\DeprecatedStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cast_models', function (Blueprint $table): void {
            $table->string('status');
            $table->string('price');
            // Built at plugin init, before Psalm's storage exists: SchemaAggregator must survive the failed load.
            $table->foreignIdFor(DeprecatedStatus::class);
        });
    }
};
