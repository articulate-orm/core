<?php

namespace Articulate\Tests\Modules\Cache;

use Articulate\Modules\Cache\NullCollectionCache;
use PHPUnit\Framework\TestCase;

class NullCollectionCacheTest extends TestCase {
    public function testGetIdListIsAlwaysAMiss(): void
    {
        $cache = new NullCollectionCache();

        $this->assertNull($cache->getIdList('any-key'));
    }

    public function testSetIdListIsANoOp(): void
    {
        $cache = new NullCollectionCache();

        $cache->setIdList('any-key', [1, 2, 3], 60);

        // A no-op write must not make a subsequent read a hit.
        $this->assertNull($cache->getIdList('any-key'));
    }
}
