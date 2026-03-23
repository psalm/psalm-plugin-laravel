--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
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

// ForeignKeyDefinition fluent methods
Schema::create('comments', function (Blueprint $table) {
    $_fk = $table->foreign('post_id');
    /** @psalm-check-type-exact $_fk = ForeignKeyDefinition */

    // Full foreign key chain
    $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');

    // Deferrable constraint (PostgreSQL)
    $table->foreign('user_id')->references('id')->on('users')
        ->deferrable()
        ->initiallyImmediate();

    // Real methods on ForeignKeyDefinition
    $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('editor_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('reviewer_id')->references('id')->on('users')->restrictOnDelete();
    $table->foreign('approver_id')->references('id')->on('users')->cascadeOnUpdate();
    $table->foreign('creator_id')->references('id')->on('users')->nullOnUpdate();
    $table->foreign('manager_id')->references('id')->on('users')->restrictOnUpdate();
    $table->foreign('sponsor_id')->references('id')->on('users')->noActionOnDelete();
    $table->foreign('mentor_id')->references('id')->on('users')->noActionOnUpdate();

    // Stub-declared methods on ForeignKeyDefinition
    $table->foreign('dept_id')->references('id')->on('departments')->onUpdate('cascade');
    $table->foreign('team_id')->references('id')->on('teams')->lock('none');
});
?>
--EXPECT--
