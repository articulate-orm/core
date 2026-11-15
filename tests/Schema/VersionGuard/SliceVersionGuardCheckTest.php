<?php

namespace Articulate\Tests\Schema\VersionGuard;

use Articulate\Schema\EntityMetadata;
use Articulate\Schema\EntityMetadataRegistry;
use Articulate\Schema\VersionGuard\SliceVersionGuardCheck;
use Articulate\Schema\VersionGuard\VersionGuardFinding;
use Articulate\Schema\VersionGuard\VersionGuardViolation;
use PHPUnit\Framework\TestCase;

class SliceVersionGuardCheckTest extends TestCase {
    private const FIXTURES = 'Articulate\\Tests\\Schema\\VersionGuard\\Fixtures\\';

    private EntityMetadataRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new EntityMetadataRegistry();
    }

    /**
     * @return list<EntityMetadata>
     */
    private function slices(string $scenario, string ...$classes): array
    {
        $slices = [];
        foreach ($classes as $class) {
            $slices[] = $this->registry->getMetadata(self::FIXTURES . $scenario . '\\' . $class);
        }

        return $slices;
    }

    /**
     * @return list<VersionGuardFinding>
     */
    private function check(EntityMetadata ...$slices): array
    {
        return (new SliceVersionGuardCheck())->check(...$slices);
    }

    public function testNoSlicesHaveNoFindings(): void
    {
        $this->assertSame([], $this->check());
    }

    public function testCosmeticSliceWithNoVersionAttributeIsClean(): void
    {
        $this->assertSame([], $this->check(...$this->slices('CosmeticSlice', 'Owner', 'Cosmetic')));
    }

    public function testSliceWritingGuardedColumnWithoutAcknowledgementIsFound(): void
    {
        $findings = $this->check(...$this->slices('GuardedWriterUnacknowledged', 'Owner', 'Writer'));

        $this->assertEquals([
            new VersionGuardFinding(
                VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE,
                'vg_guarded',
                self::FIXTURES . 'GuardedWriterUnacknowledged\\Writer',
                ['version'],
                ['status'],
            ),
        ], $findings);
    }

    public function testAcknowledgementNamingTheVersionColumnClearsTheWrite(): void
    {
        $this->assertSame([], $this->check(...$this->slices('GuardedWriterAcknowledged', 'Owner', 'Writer')));
    }

    public function testAcknowledgingOneVersionColumnLeavesTheOtherChecked(): void
    {
        $findings = $this->check(...$this->slices('PartialAcknowledgement', 'StatusOwner', 'PriceOwner', 'PartialWriter'));

        $this->assertEquals([
            new VersionGuardFinding(
                VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE,
                'vg_two',
                self::FIXTURES . 'PartialAcknowledgement\\PartialWriter',
                ['price_version'],
                ['price'],
            ),
        ], $findings);
    }

    public function testGuardSetIsTheUnionOfEverySliceDeclaringTheVersionColumn(): void
    {
        $findings = $this->check(...$this->slices('GuardSetUnion', 'GuardsColA', 'GuardsColB', 'WritesBoth'));

        $this->assertEquals([
            new VersionGuardFinding(
                VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE,
                'vg_union',
                self::FIXTURES . 'GuardSetUnion\\WritesBoth',
                ['version'],
                ['col_a', 'col_b'],
            ),
        ], $findings);
    }

    public function testSliceOwningItsOwnVersionOverTheSameColumnIsClean(): void
    {
        $this->assertSame([], $this->check(...$this->slices('OwnVersionOverSameColumn', 'A', 'B')));
    }

    public function testDisjointVersionColumnsAreNotRivals(): void
    {
        $this->assertSame([], $this->check(...$this->slices('DisjointVersions', 'Left', 'Right')));
    }

    public function testOverlappingGuardSetsOfDistinctVersionColumnsAreRivalCounters(): void
    {
        $findings = $this->check(...$this->slices('RivalCounters', 'StatusSlice', 'OtherSlice'));

        $this->assertEquals(
            new VersionGuardFinding(VersionGuardViolation::RIVAL_COUNTERS, 'vg_rivals', null, ['a_version', 'b_version'], ['shared']),
            $findings[0],
        );
    }

    public function testRivalCountersCannotBeDowngraded(): void
    {
        $findings = $this->check(...$this->slices('RivalCounters', 'StatusSlice', 'OtherSlice'));

        $this->assertFalse($findings[0]->kind->downgradable());
    }

    public function testSiblingMappingVersionColumnAsPlainPropertyIsFoundDespiteAcknowledgement(): void
    {
        $findings = $this->check(...$this->slices('PlainVersionColumn', 'Owner', 'Sneaky'));

        $this->assertEquals([
            new VersionGuardFinding(
                VersionGuardViolation::PLAIN_VERSION_COLUMN,
                'vg_plain',
                self::FIXTURES . 'PlainVersionColumn\\Sneaky',
                ['version'],
                ['version'],
            ),
        ], $findings);
    }

    public function testEveryViolationOnATableWithThreeVersionColumnsIsFound(): void
    {
        $findings = $this->check(...$this->slices('BusyTable', 'NoteOwner', 'SharedOwner', 'OtherOwner', 'BothOwner', 'CounterMirror'));

        $busy = self::FIXTURES . 'BusyTable\\';
        $this->assertEquals([
            new VersionGuardFinding(VersionGuardViolation::RIVAL_COUNTERS, 'vg_busy', null, ['a_version', 'c_version'], ['shared']),
            new VersionGuardFinding(VersionGuardViolation::RIVAL_COUNTERS, 'vg_busy', null, ['b_version', 'c_version'], ['other']),
            new VersionGuardFinding(VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE, 'vg_busy', $busy . 'NoteOwner', ['c_version'], ['shared']),
            new VersionGuardFinding(VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE, 'vg_busy', $busy . 'SharedOwner', ['c_version'], ['shared']),
            new VersionGuardFinding(VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE, 'vg_busy', $busy . 'OtherOwner', ['c_version'], ['other']),
            new VersionGuardFinding(VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE, 'vg_busy', $busy . 'BothOwner', ['a_version'], ['shared']),
            new VersionGuardFinding(VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE, 'vg_busy', $busy . 'BothOwner', ['b_version'], ['other']),
            new VersionGuardFinding(VersionGuardViolation::PLAIN_VERSION_COLUMN, 'vg_busy', $busy . 'CounterMirror', ['a_version'], ['a_version']),
            new VersionGuardFinding(VersionGuardViolation::PLAIN_VERSION_COLUMN, 'vg_busy', $busy . 'CounterMirror', ['b_version'], ['b_version']),
        ], $findings);
    }

    public function testFindingsComePerTableInDiscoveryOrderRivalsFirstThenPlainBeforeUnacknowledgedPerSlice(): void
    {
        $findings = $this->check(
            ...$this->slices('FindingOrder', 'Owner', 'Rogue'),
            ...$this->slices('RivalCounters', 'StatusSlice', 'OtherSlice'),
        );

        $this->assertSame([
            [VersionGuardViolation::PLAIN_VERSION_COLUMN, 'vg_order', 'FindingOrder\\Rogue'],
            [VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE, 'vg_order', 'FindingOrder\\Rogue'],
            [VersionGuardViolation::RIVAL_COUNTERS, 'vg_rivals', null],
            [VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE, 'vg_rivals', 'RivalCounters\\StatusSlice'],
            [VersionGuardViolation::UNACKNOWLEDGED_GUARDED_WRITE, 'vg_rivals', 'RivalCounters\\OtherSlice'],
        ], array_map(
            fn (VersionGuardFinding $finding): array => [
                $finding->kind,
                $finding->table,
                $finding->sliceClass === null ? null : substr($finding->sliceClass, strlen(self::FIXTURES)),
            ],
            $findings,
        ));
    }
}
