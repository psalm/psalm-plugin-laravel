--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Support\Facades\Schema;

// ColumnDefinition fluent methods — verifies no UndefinedMagicMethod errors
Schema::create('test_table', function (Blueprint $table) {
    $_column = $table->string('name');
    /** @psalm-check-type-exact $_column = ColumnDefinition */

    // Chaining — the most common migration pattern
    $table->integer('amount')->unsigned()->nullable()->default(0);
    $table->string('slug')->index();
    $table->string('code')->unique();
    $table->string('uuid')->primary();
    $table->string('title')->change();
    $table->string('bio')->after('name');
    $table->string('data')->charset('utf8mb4');
    $table->timestamp('verified_at')->useCurrent();
    $table->string('computed')->storedAs('CONCAT(first, last)');
    $table->string('virtual_col')->virtualAs('CONCAT(first, last)');
    $table->text('body')->fulltext();
    $table->string('note')->comment('A note');
    $table->string('collated')->collation('utf8mb4_unicode_ci');
    $table->integer('seq')->autoIncrement();
    $table->string('first_col')->first();
    $table->integer('start_seq')->from(1000);
    $table->integer('start_val')->startingValue(100);
    $table->string('invisible_col')->invisible();
    $table->geometry('location')->spatialIndex();
    $table->string('type_col')->type('varchar');
    $table->string('persisted_col')->persisted();
    $table->integer('gen_col')->always();
    $table->integer('instant_col')->instant();
    $table->integer('gen_id')->generatedAs();
    $table->timestamp('updated_at')->useCurrentOnUpdate();
    $table->float('embedding', 1536)->vectorIndex();
    $table->string('lock_col')->lock('none');
});

// ForeignIdColumnDefinition: nullable + constrained chain
Schema::create('work_orders', function (Blueprint $table) {
    $_foreignId = $table->foreignId('vehicle_id');
    /** @psalm-check-type-exact $_foreignId = ForeignIdColumnDefinition */

    // nullable() preserves ForeignIdColumnDefinition type for constrained()
    $_afterNullable = $table->foreignId('temp_id')->nullable();
    /** @psalm-check-type-exact $_afterNullable = ForeignIdColumnDefinition&static */

    $_constrained = $table->foreignId('check_id')->constrained();
    /** @psalm-check-type-exact $_constrained = ForeignKeyDefinition */

    $_fkFromRef = $table->foreignId('ref_id')->references('id');
    /** @psalm-check-type-exact $_fkFromRef = ForeignKeyDefinition */

    // Common migration patterns
    $table->foreignId('mechanic_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
});

// ForeignKeyDefinition fluent methods
Schema::create('damage_reports', function (Blueprint $table) {
    $_fk = $table->foreign('work_order_id');
    /** @psalm-check-type-exact $_fk = ForeignKeyDefinition */

    // Full foreign key chain
    $table->foreign('work_order_id')->references('id')->on('work_orders')->onDelete('cascade');

    // Deferrable constraint (PostgreSQL)
    $table->foreign('vehicle_id')->references('id')->on('vehicles')
        ->deferrable()
        ->initiallyImmediate();

    // Real methods on ForeignKeyDefinition
    $table->foreign('mechanic_id')->references('id')->on('mechanics')->cascadeOnDelete();
    $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
    $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
    $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnUpdate();
    $table->foreign('part_id')->references('id')->on('parts')->nullOnUpdate();
    $table->foreign('reviewer_id')->references('id')->on('customers')->restrictOnUpdate();
    $table->foreign('approver_id')->references('id')->on('customers')->noActionOnDelete();
    $table->foreign('creator_id')->references('id')->on('customers')->noActionOnUpdate();

    // Stub-declared methods on ForeignKeyDefinition
    $table->foreign('dept_id')->references('id')->on('departments')->onUpdate('cascade');
    $table->foreign('team_id')->references('id')->on('teams')->lock('none');
});
?>
--EXPECT--
