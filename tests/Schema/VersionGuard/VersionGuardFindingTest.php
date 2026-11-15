<?php

namespace Articulate\Tests\Schema\VersionGuard;

use Articulate\Schema\VersionGuard\VersionGuardFinding;
use Articulate\Schema\VersionGuard\VersionGuardViolation;
use PHPUnit\Framework\TestCase;

class VersionGuardFindingTest extends TestCase {
    public function testRivalCountersMessage(): void
    {
        $finding = new VersionGuardFinding(VersionGuardViolation::RIVAL_COUNTERS, 'orders', null, ['a_version', 'b_version'], ['status', 'total']);

        $this->assertSame(
            'Rival counters on table "orders": #[Version] columns "a_version" and "b_version" both guard status, total.',
            $finding->message(),
        );
    }

    public function testPlainVersionColumnMessage(): void
    {
        $finding = new VersionGuardFinding(VersionGuardViolation::PLAIN_VERSION_COLUMN, 'orders', 'App\\Sneaky', ['version'], ['version']);

        $this->assertSame(
            'Class "App\\Sneaky" maps #[Version] column "version" of table "orders" as a plain property; '
            . 'only a #[Version] property may map a version column.',
            $finding->message(),
        );
    }

    public function testUnacknowledgedGuardedWriteMessage(): void
    {
        $finding = new VersionGuardFinding(VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE, 'orders', 'App\\Writer', ['version'], ['status', 'total']);

        $this->assertSame(
            'Class "App\\Writer" persists status, total guarded by #[Version] column "version" on table "orders" '
            . 'without its own #[Version] or a #[VersionAware([\'version\'])] acknowledgement.',
            $finding->message(),
        );
    }

    public function testOnlyUnacknowledgedGuardedWriteIsDowngradable(): void
    {
        $this->assertTrue(VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE->downgradable());
        $this->assertFalse(VersionGuardViolation::RIVAL_COUNTERS->downgradable());
        $this->assertFalse(VersionGuardViolation::PLAIN_VERSION_COLUMN->downgradable());
    }
}
