<?php

namespace tests\unit\Espo\Modules\QuickBooks;

use Espo\Modules\QuickBooks\Tools\ConflictResolver;
use PHPUnit\Framework\TestCase;

class ConflictResolverTest extends TestCase
{
    private ConflictResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ConflictResolver();
    }

    public function testQbWinsWhenNewer(): void
    {
        $result = $this->resolver->resolve('2024-06-10T12:00:00', '2024-06-09T12:00:00');

        $this->assertSame(ConflictResolver::WINNER_QB, $result);
    }

    public function testEspoWinsWhenNewer(): void
    {
        $result = $this->resolver->resolve('2024-06-08T12:00:00', '2024-06-10T12:00:00');

        $this->assertSame(ConflictResolver::WINNER_ESPO, $result);
    }

    public function testEspoWinsWhenSameTimestamp(): void
    {
        $result = $this->resolver->resolve('2024-06-10T12:00:00', '2024-06-10T12:00:00');

        $this->assertSame(ConflictResolver::WINNER_ESPO, $result);
    }

    public function testQbWinsWhenEspoSyncMissing(): void
    {
        $result = $this->resolver->resolve('2024-06-10T12:00:00', null);

        $this->assertSame(ConflictResolver::WINNER_QB, $result);
    }

    public function testEspoWinsWhenQbTimeMissing(): void
    {
        $result = $this->resolver->resolve(null, '2024-06-10T12:00:00');

        $this->assertSame(ConflictResolver::WINNER_ESPO, $result);
    }

    public function testNoneWhenBothMissing(): void
    {
        $result = $this->resolver->resolve(null, null);

        $this->assertSame(ConflictResolver::WINNER_NONE, $result);
    }

    public function testIsQbNewerTrue(): void
    {
        $this->assertTrue($this->resolver->isQbNewer('2024-06-10T12:00:00', '2024-06-09T12:00:00'));
    }

    public function testIsQbNewerFalse(): void
    {
        $this->assertFalse($this->resolver->isQbNewer('2024-06-08T12:00:00', '2024-06-10T12:00:00'));
    }
}
