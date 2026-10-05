<?php

use AutoloadCrashFixture\Cases\ForeignIdFor\DeprecatedCustomer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(DeprecatedCustomer::class);
            $table->string('number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
