<?php

use AutoloadCrashFixture\ForeignIdFor\DeprecatedCustomer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // SchemaAggregator checks whether the foreignIdFor() class is a Model at plugin init.
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(DeprecatedCustomer::class);
            $table->string('number');
        });

        // The enum cast types this column.
        Schema::create('casts_models', function (Blueprint $table): void {
            $table->string('status')->nullable();
        });

        // toArray() keys come from the schema.
        Schema::create('to_array_cast_models', function (Blueprint $table): void {
            $table->string('status');
            $table->string('price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('to_array_cast_models');
        Schema::dropIfExists('casts_models');
        Schema::dropIfExists('invoices');
    }
};
