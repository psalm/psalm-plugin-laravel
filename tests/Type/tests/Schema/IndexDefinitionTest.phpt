--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\IndexDefinition;
use Illuminate\Support\Facades\Schema;

// IndexDefinition fluent modifiers: verifies no UndefinedMagicMethod errors.
// Blueprint::index()/fullText()/spatialIndex()/primary()/unique()/rawIndex() all
// return IndexDefinition, whose fluent modifiers (algorithm(), language(), ...) are
// declared only as class-level @method tags and dispatched via Fluent::__call(). Those
// tags auto-seal the class's magic methods, so a modifier absent from the installed
// Laravel version's @method block raises UndefinedMagicMethod; the stub declares each
// modifier as a real method so it resolves before Psalm's sealed magic-method path.
//
// Guard scope: every Laravel version currently exercised (CI runs ^12.4 and ^13.0 at
// highest, both resolving to blocks that already list all 7 modifiers) resolves these
// via reflection even without the stub, so this test pins Blueprint's index-family
// return type to IndexDefinition and documents the full modifier contract. The stub is
// the load-bearing fix for installs on older Laravel whose @method block predates some
// modifiers (lock()/online()/nullsNotDistinct()).
// https://github.com/psalm/psalm-plugin-laravel/issues/1217
Schema::table('songs', function (Blueprint $table) {
    $_index = $table->fullText(['title', 'artist_name', 'album_name']);
    /** @psalm-check-type-exact $_index = IndexDefinition */

    // Exact reproduction from koel/koel migration
    $table->fullText(['title', 'artist_name', 'album_name'])->language('simple');

    // Every @method fluent modifier documented on IndexDefinition (Laravel 13)
    $table->index(['a', 'b'])->algorithm('btree');
    $table->fullText(['body'])->language('english');
    $table->unique(['email'])->deferrable();
    $table->unique(['slug'])->deferrable()->initiallyImmediate();
    $table->index(['status'])->lock('none');
    $table->unique(['token'])->nullsNotDistinct();
    $table->index(['created_at'])->online();

    // Every Blueprint index-family method returns IndexDefinition
    $table->primary(['id'])->algorithm('btree');
    $table->spatialIndex('location')->algorithm('gist');
    $table->rawIndex('lower(email)', 'idx_lower_email')->algorithm('btree');
});
?>
--EXPECTF--
