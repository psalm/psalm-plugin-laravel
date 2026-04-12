--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Cookie\QueueingFactory;
use Illuminate\Cookie\CookieJar;

/**
 * CookieJar implements QueueingFactory — the stub must declare this
 * so Psalm accepts CookieJar where QueueingFactory is expected.
 */
final class CookieJarTest
{
    public function acceptsQueueingFactory(QueueingFactory $factory): void {}

    public function passCookieJarAsQueueingFactory(CookieJar $jar): void
    {
        $this->acceptsQueueingFactory($jar);
    }
}
?>
--EXPECT--
