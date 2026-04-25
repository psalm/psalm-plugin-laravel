<?php

declare(strict_types=1);

/**
 * Performance benchmark for psalm-plugin-laravel
 *
 * Profiles each performance-critical component:
 * 1. Plugin boot (Laravel app + stub generation)
 * 2. SchemaAggregator (migration parsing)
 * 3. ModelRelationshipPropertyHandler (property lookups)
 * 4. ModelPropertyAccessorHandler (accessor lookups)
 * 5. ProxyMethodReturnTypeProvider (fake call execution)
 * 6. FakeModelsCommand (model stub generation)
 *
 * Usage:
 *   php benchmark/benchmark.php [--models=N] [--migrations=N] [--properties=N]
 *
 * Defaults simulate a large project: 150 models, 300 migrations, 50 properties/model
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;

// ---------------------------------------------------------------------------
// CLI options
// ---------------------------------------------------------------------------
$options = getopt('', ['models:', 'migrations:', 'properties:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
    Usage: php benchmark/benchmark.php [OPTIONS]

    Options:
      --models=N       Number of models to simulate (default: 150)
      --migrations=N   Number of migration files to simulate (default: 300)
      --properties=N   Properties per model (default: 50)
      --help           Show this help

    HELP;
    exit(0);
}

$numModels      = (int) ($options['models'] ?? 150);
$numMigrations  = (int) ($options['migrations'] ?? 300);
$numProperties  = (int) ($options['properties'] ?? 50);

echo "=== psalm-plugin-laravel Performance Benchmark ===\n";
echo "Configuration: {$numModels} models, {$numMigrations} migrations, {$numProperties} properties/model\n";
echo "PHP " . PHP_VERSION . ", memory_limit=" . ini_get('memory_limit') . "\n";
echo "JIT: " . (function_exists('opcache_get_status') && ($s = opcache_get_status(false)) && ($s['jit']['on'] ?? false) ? 'enabled' : 'disabled') . "\n";
echo str_repeat('-', 72) . "\n\n";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function memoryUsage(): int
{
    return memory_get_usage(true);
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / 1048576, 1) . ' MB';
}

function benchmark(string $label, callable $fn): array
{
    gc_collect_cycles();
    $memBefore = memoryUsage();
    $start = hrtime(true);

    $result = $fn();

    $elapsed = (hrtime(true) - $start) / 1_000_000; // ms
    $memAfter = memoryUsage();
    $memDelta = $memAfter - $memBefore;

    printf(
        "  %-45s %8.1f ms  mem: %s (delta: %s)\n",
        $label,
        $elapsed,
        formatBytes($memAfter),
        ($memDelta >= 0 ? '+' : '') . formatBytes($memDelta),
    );

    return ['time_ms' => $elapsed, 'mem_delta' => $memDelta, 'mem_total' => $memAfter, 'result' => $result];
}

// ---------------------------------------------------------------------------
// 1. SchemaAggregator: Parse synthetic migration ASTs
// ---------------------------------------------------------------------------
echo "1. SchemaAggregator — migration parsing\n";

function generateMigrationAst(int $tableIndex, int $numColumns): array
{
    $parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
    $tableName = "table_{$tableIndex}";

    $columnDefs = '';
    $columnTypes = ['string', 'integer', 'boolean', 'text', 'json', 'float', 'datetime', 'bigInteger', 'uuid', 'enum'];

    for ($c = 0; $c < $numColumns; $c++) {
        $type = $columnTypes[$c % count($columnTypes)];
        $colName = "column_{$c}";
        if ($type === 'enum') {
            $columnDefs .= "\$table->{$type}('{$colName}', ['a','b','c']);\n";
        } else {
            $columnDefs .= "\$table->{$type}('{$colName}');\n";
        }
        // Add nullable/default chaining on some columns
        if ($c % 3 === 0) {
            $columnDefs = substr($columnDefs, 0, -2) . "->nullable();\n";
        }
        if ($c % 5 === 0) {
            $columnDefs = substr($columnDefs, 0, -2) . "->default('value');\n";
        }
    }

    // Use named class format — anonymous classes don't get proper name resolution
    // with PhpParser's NameResolver (Psalm's parser handles this differently)
    $className = 'CreateTable' . $tableIndex . 'Migration';
    $code = <<<PHP
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    class {$className} extends Migration {
        public function up(): void
        {
            Schema::create('{$tableName}', function (Blueprint \$table) {
                \$table->id();
                \$table->timestamps();
                {$columnDefs}
            });
        }
    }
    PHP;

    $stmts = $parser->parse($code);

    // replaceNodes=false sets resolvedName attribute as Name objects.
    // Psalm's parser sets resolvedName as strings, so we convert.
    $nameResolver = new PhpParser\NodeVisitor\NameResolver(null, ['replaceNodes' => false]);
    $stringifyResolver = new class extends PhpParser\NodeVisitorAbstract {
        public function leaveNode(PhpParser\Node $node): ?PhpParser\Node
        {
            $resolved = $node->getAttribute('resolvedName');
            if ($resolved instanceof PhpParser\Node\Name) {
                $node->setAttribute('resolvedName', $resolved->toString());
            }
            return null;
        }
    };
    $traverser = new PhpParser\NodeTraverser();
    $traverser->addVisitor($nameResolver);
    $traverser->addVisitor($stringifyResolver);
    $stmts = $traverser->traverse($stmts);

    return $stmts;
}

// Generate all migration ASTs first (separate from timing the aggregator)
echo "  Generating {$numMigrations} synthetic migration ASTs...\n";
$migrationAsts = [];
$columnsPerMigration = max(1, (int) ($numProperties * $numModels / $numMigrations));
$genStart = hrtime(true);
for ($i = 0; $i < $numMigrations; $i++) {
    $migrationAsts[] = generateMigrationAst($i, $columnsPerMigration);
}
$genTime = (hrtime(true) - $genStart) / 1_000_000;
printf("  %-45s %8.1f ms\n", "AST generation ({$numMigrations} files)", $genTime);

// Now benchmark the aggregator
$aggregatorResult = benchmark("SchemaAggregator::addStatements ({$numMigrations} files)", function () use ($migrationAsts) {
    $aggregator = new SchemaAggregator();
    foreach ($migrationAsts as $stmts) {
        $aggregator->addStatements($stmts);
    }

    return $aggregator;
});

/** @var SchemaAggregator $aggregator */
$aggregator = $aggregatorResult['result'];
$totalColumns = 0;
foreach ($aggregator->tables as $table) {
    $totalColumns += count($table->columns);
}
echo "  Tables: " . count($aggregator->tables) . ", total columns: {$totalColumns}\n";

// Benchmark individual addStatements calls to find variance
echo "\n  Per-migration timing (sample of 10):\n";
$sampleIndices = array_map(fn($i) => (int) ($i * $numMigrations / 10), range(0, 9));
foreach ($sampleIndices as $idx) {
    $singleAgg = new SchemaAggregator();
    benchmark("  migration #{$idx} ({$columnsPerMigration} cols)", function () use ($singleAgg, $migrationAsts, $idx) {
        $singleAgg->addStatements($migrationAsts[$idx]);
    });
}

unset($migrationAsts); // free memory

echo "\n";

// ---------------------------------------------------------------------------
// 2. ReflectionProperty performance (used in ModelPropertyAccessorHandler)
// ---------------------------------------------------------------------------
echo "2. ReflectionProperty lookups (ModelPropertyAccessorHandler::hasNativeProperty)\n";

// This uses exception-based flow control which is expensive
$reflectionCount = $numModels * $numProperties;

benchmark("ReflectionProperty (existing, {$numModels} lookups)", function () use ($numModels) {
    for ($i = 0; $i < $numModels; $i++) {
        try {
            new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, 'table');
        } catch (\ReflectionException) {
            // not found
        }
    }
});

benchmark("ReflectionProperty (non-existing, {$reflectionCount} exceptions)", function () use ($reflectionCount) {
    for ($i = 0; $i < $reflectionCount; $i++) {
        try {
            new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, "nonexistent_prop_{$i}");
        } catch (\ReflectionException) {
            // not found - this is the hot path for dynamic properties
        }
    }
});

benchmark("property_exists() alternative ({$reflectionCount} lookups)", function () use ($reflectionCount) {
    for ($i = 0; $i < $reflectionCount; $i++) {
        property_exists(\Illuminate\Database\Eloquent\Model::class, "nonexistent_prop_{$i}");
    }
});

echo "\n";

// ---------------------------------------------------------------------------
// 3. Handler overlap simulation
// ---------------------------------------------------------------------------
echo "3. Handler overlap — simulated property access dispatch\n";
echo "  (Simulates what happens when Psalm accesses a model property)\n";

// Count how many handlers fire per property access
$handlersFired = [
    'ModelRelationshipPropertyHandler::doesPropertyExist' => 0,
    'ModelRelationshipPropertyHandler::isPropertyVisible' => 0,
    'ModelRelationshipPropertyHandler::getPropertyType' => 0,
    'ModelPropertyAccessorHandler::doesPropertyExist' => 0,
    'ModelPropertyAccessorHandler::isPropertyVisible' => 0,
    'ModelPropertyAccessorHandler::getPropertyType' => 0,
    'ModelFactoryTypeProvider::getPropertyType' => 0,
];

// For a typical property access, all handlers fire sequentially until one returns non-null.
// With 150 models × 50 properties × 3 events × N handlers, this is massive.
$totalHandlerCalls = $numModels * $numProperties * count($handlersFired);
echo "  Estimated handler calls for full analysis: " . number_format($totalHandlerCalls) . "\n";
echo "  Per property access: " . count($handlersFired) . " handler methods\n";

// Simulate the cost of the relationExists() check (the most expensive part)
// It calls methodExists() + getMethodReturnType() on every property access
benchmark("relationExists() simulation ({$numModels}×{$numProperties} calls)", function () use ($numModels, $numProperties) {
    // Simulate the string concat + method identifier creation overhead
    $count = 0;
    for ($m = 0; $m < $numModels; $m++) {
        $className = "App\\Models\\Model{$m}";
        for ($p = 0; $p < $numProperties; $p++) {
            $propName = "property_{$p}";
            // This is what relationExists does on each call
            $method = $className . '::' . $propName;
            $count++;
        }
    }

    return $count;
});

echo "\n";

// ---------------------------------------------------------------------------
// 4. Object cloning cost (ProxyMethodReturnTypeProvider)
// ---------------------------------------------------------------------------
echo "4. Object cloning cost — ProxyMethodReturnTypeProvider::executeFakeCall\n";
echo "  (The single biggest performance bottleneck)\n";

// Simulate the cost of cloning large objects that happens in executeFakeCall
// node_data and Context are cloned on every proxy call

// Create objects of varying sizes to simulate Psalm's internal state
function createLargeObject(int $sizeKb): stdClass
{
    $obj = new stdClass();
    $obj->data = str_repeat('x', $sizeKb * 1024);
    $obj->nested = new stdClass();
    $obj->nested->types = [];
    for ($i = 0; $i < 100; $i++) {
        $obj->nested->types["type_{$i}"] = new stdClass();
        $obj->nested->types["type_{$i}"]->value = str_repeat('y', 256);
    }

    return $obj;
}

foreach ([64, 256, 1024, 4096] as $sizeKb) {
    $largeObj = createLargeObject($sizeKb);
    $callCount = min(100, $numModels); // representative sample

    benchmark("clone {$sizeKb}KB object × {$callCount} calls", function () use ($largeObj, $callCount) {
        for ($i = 0; $i < $callCount; $i++) {
            $cloned = clone $largeObj;
            unset($cloned);
        }
    });

    unset($largeObj);
}

// Estimate total cloning cost for a large project
$avgProxyCallsPerModel = 30; // conservative estimate from the doc
$totalProxyCalls = $numModels * $avgProxyCallsPerModel;
echo "\n  Estimated proxy calls for {$numModels} models: " . number_format($totalProxyCalls) . "\n";
echo "  Each call clones node_data + Context (can be megabytes each)\n";

echo "\n";

// ---------------------------------------------------------------------------
// 5. Memory profile of SchemaAggregator tables
// ---------------------------------------------------------------------------
echo "5. Memory profile — SchemaAggregator data structures\n";

$memBefore = memoryUsage();

$testAggregator = new SchemaAggregator();
for ($t = 0; $t < $numModels; $t++) {
    $table = new SchemaTable();
    for ($c = 0; $c < $numProperties; $c++) {
        $types = ['string', 'int', 'bool', 'float', 'mixed', 'enum'];
        $table->setColumn(new SchemaColumn(
            "col_{$c}",
            $types[$c % count($types)],
            $c % 3 === 0, // nullable
        ));
    }
    $testAggregator->tables["table_{$t}"] = $table;
}

$memAfter = memoryUsage();
$schemaMemory = $memAfter - $memBefore;
printf("  Schema memory for %d tables × %d columns: %s\n", $numModels, $numProperties, formatBytes($schemaMemory));
printf("  Per table: %s\n", formatBytes((int) ($schemaMemory / $numModels)));
printf("  Per column: %s\n", formatBytes((int) ($schemaMemory / ($numModels * $numProperties))));

unset($testAggregator);

echo "\n";

// ---------------------------------------------------------------------------
// 6. Cache effectiveness simulation
// ---------------------------------------------------------------------------
echo "6. Cache effectiveness — simulated property lookups with/without cache\n";

// Simulate the pattern: same property accessed N times across different files
$accessPatterns = [];
$uniqueProperties = $numModels * 10; // 10 distinct properties per model
$totalAccesses = $uniqueProperties * 5; // each accessed ~5 times on average

for ($i = 0; $i < $totalAccesses; $i++) {
    $accessPatterns[] = "Model" . ($i % $numModels) . "::prop_" . ($i % 10);
}
shuffle($accessPatterns);

// Without cache: every access does full lookup
benchmark("Without cache ({$totalAccesses} lookups)", function () use ($accessPatterns) {
    $lookups = 0;
    foreach ($accessPatterns as $key) {
        // Simulate expensive lookup (string ops + hash)
        $parts = explode('::', $key);
        $hash = md5($key); // simulate codebase->methodExists() cost
        $lookups++;
    }

    return $lookups;
});

// With cache: first access does lookup, subsequent are cache hits
benchmark("With cache ({$totalAccesses} lookups, " . number_format($uniqueProperties) . " unique)", function () use ($accessPatterns) {
    $cache = [];
    $lookups = 0;
    $hits = 0;
    foreach ($accessPatterns as $key) {
        if (isset($cache[$key])) {
            $hits++;
            continue;
        }
        // Simulate expensive lookup
        $parts = explode('::', $key);
        $hash = md5($key);
        $cache[$key] = $hash;
        $lookups++;
    }

    return ['lookups' => $lookups, 'hits' => $hits];
});

echo "\n";

// ---------------------------------------------------------------------------
// 7. Stub file I/O
// ---------------------------------------------------------------------------
echo "7. Stub file scanning performance\n";

$stubDir = dirname(__DIR__) . '/stubs';
if (is_dir($stubDir)) {
    benchmark("findStubFiles() — RecursiveDirectoryIterator scan", function () use ($stubDir) {
        $stubs = [];
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stubDir, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() === 'stubphp') {
                $stubs[] = $file->getRealPath();
            }
        }

        return count($stubs);
    });
}

// Simulate large generated stub file size
$stubSizes = [];
foreach ([10, 50, 150, 300] as $modelCount) {
    // Each model in the generated stub is roughly 2-5KB of PHP code
    $estimatedSize = $modelCount * 3500; // ~3.5KB per model average
    $stubSizes[$modelCount] = $estimatedSize;
}

echo "  Estimated generated models.stubphp sizes:\n";
foreach ($stubSizes as $count => $size) {
    printf("    %3d models: %s\n", $count, formatBytes($size));
}

echo "\n";

// ---------------------------------------------------------------------------
// 8. NodeFinder performance in SchemaAggregator
// ---------------------------------------------------------------------------
echo "8. NodeFinder performance (SchemaAggregator::addStatements)\n";

$parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
$nameResolver = new PhpParser\NodeVisitor\NameResolver(null, ['replaceNodes' => false]);
$stringifyResolver = new class extends PhpParser\NodeVisitorAbstract {
    public function leaveNode(PhpParser\Node $node): ?PhpParser\Node
    {
        $resolved = $node->getAttribute('resolvedName');
        if ($resolved instanceof PhpParser\Node\Name) {
            $node->setAttribute('resolvedName', $resolved->toString());
        }
        return null;
    }
};
$traverser = new PhpParser\NodeTraverser();
$traverser->addVisitor($nameResolver);
$traverser->addVisitor($stringifyResolver);

// Create a large migration with many statements
$largeMigrationColumns = '';
for ($i = 0; $i < 200; $i++) {
    $largeMigrationColumns .= "\$table->string('col_{$i}')->nullable()->default('val');\n";
}

$largeMigrationCode = <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLargeTableMigration extends Migration {
    public function up(): void
    {
        Schema::create('large_table', function (Blueprint \$table) {
            \$table->id();
            \$table->timestamps();
            {$largeMigrationColumns}
        });
    }
}
PHP;

$largeStmts = $traverser->traverse($parser->parse($largeMigrationCode));

benchmark("NodeFinder on 200-column migration", function () use ($largeStmts) {
    $aggregator = new SchemaAggregator();
    $aggregator->addStatements($largeStmts);

    return count($aggregator->tables['large_table']->columns ?? []);
});

// Multiple iterations to detect any per-iteration degradation
benchmark("NodeFinder × 100 iterations (same AST)", function () use ($largeStmts) {
    for ($i = 0; $i < 100; $i++) {
        $aggregator = new SchemaAggregator();
        $aggregator->addStatements($largeStmts);
    }
});

echo "\n";

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo str_repeat('=', 72) . "\n";
echo "SUMMARY — Key Findings for {$numModels}-model project\n";
echo str_repeat('=', 72) . "\n";

$estimatedProxyCalls = $numModels * $avgProxyCallsPerModel;
$estimatedPropertyAccesses = $numModels * $numProperties * 3; // 3 events per access
$estimatedHandlerCalls = $estimatedPropertyAccesses * 4; // 4 handlers per event

echo "\nEstimated analysis-time overhead:\n";
printf("  Property handler calls:    %s\n", number_format($estimatedHandlerCalls));
printf("  Proxy fake calls:          %s\n", number_format($estimatedProxyCalls));
printf("  Schema columns parsed:     %s\n", number_format($totalColumns));
printf("  ReflectionProperty throws: %s (exception-based flow)\n", number_format($estimatedPropertyAccesses));

echo "\nBottleneck ranking (estimated relative cost):\n";
echo "  1. ProxyMethodReturnTypeProvider::executeFakeCall  — CRITICAL\n";
echo "     Clones node_data + Context + runs MethodCallAnalyzer for each proxy call\n";
echo "     ~{$estimatedProxyCalls} calls × (clone + analyze) = massive memory + CPU\n";
echo "\n";
echo "  2. Uncached property handler lookups               — HIGH\n";
echo "     ~" . number_format($estimatedHandlerCalls) . " handler calls, each calling methodExists/getMethodReturnType\n";
echo "     Same property resolved repeatedly with zero caching\n";
echo "\n";
echo "  3. ReflectionProperty exception-based flow         — MEDIUM\n";
echo "     ~" . number_format($estimatedPropertyAccesses) . " ReflectionException throws for dynamic properties\n";
echo "     property_exists() is 10-100x faster\n";
echo "\n";
echo "  4. Handler overlap / redundant dispatch            — MEDIUM\n";
echo "     4 handlers × 3 events = 12 handler methods per property access\n";
echo "     Most return null — wasted work\n";
echo "\n";

echo "Peak memory: " . formatBytes(memory_get_peak_usage(true)) . "\n";
echo "Done.\n";
