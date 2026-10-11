<?php
use ViewContractFixture as Models;

/** @var Models\Thing $thing */
/** @var array<string, string> $row */
$row['name'] = 'Ada';

final class ScopeHelper
{
    /** @var string $prop = null */
    public string $prop = '';

    /** @var string $method */
    public function method(): string
    {
        return $this->prop;
    }
}

$items = [/** @var string $inner */ 1];
$callback = function () {
    /** @var string $nested */
    return 1;
};
?>
<p>{{ get_class($thing) }}{{ $row['name'] }}</p>
