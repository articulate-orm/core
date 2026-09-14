<?php

namespace Articulate\Tests\Attributes;

use Articulate\Attributes\Entity;
use Articulate\Attributes\Indexes\PrimaryKey;
use Articulate\Attributes\Property;
use Articulate\Attributes\Version;
use Articulate\Attributes\VersionAware;
use Articulate\Schema\EntityMetadata;
use PHPUnit\Framework\TestCase;

#[Entity(tableName: 'version_attr_checked')]
class VersionAttrCheckedEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    #[Version]
    public int $version = 0;
}

#[Entity(tableName: 'version_attr_aware')]
#[VersionAware(['version'])]
class VersionAttrAwareOnlyEntity {
    #[PrimaryKey]
    public ?int $id = null;
}

#[Entity(tableName: 'version_attr_both')]
#[VersionAware(['version'])]
class VersionAttrRedundantEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    #[Version]
    public int $version = 0;
}

#[Entity(tableName: 'version_attr_bad_type')]
class VersionAttrBadTypeEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    #[Version]
    public string $version = '0';
}

#[Entity(tableName: 'version_attr_none')]
class VersionAttrPlainEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[Property]
    public string $name = '';
}

#[Entity(tableName: 'version_attr_only')]
class VersionAttrOnlyEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[Version]
    public int $revisionCount = 0;
}

#[Entity(tableName: 'version_attr_named')]
class VersionAttrNamedEntity {
    #[PrimaryKey]
    public ?int $id = null;

    #[Version(name: 'lock_version')]
    public int $revisionCount = 0;
}

class VersionAttributesTest extends TestCase {
    public function testVersionColumnComesFromVersionProperty(): void
    {
        $metadata = new EntityMetadata(VersionAttrCheckedEntity::class);

        $this->assertSame(['version'], $metadata->getVersionColumns());
    }

    public function testVersionAwareContributesNothingToTheVersionColumn(): void
    {
        $metadata = new EntityMetadata(VersionAttrAwareOnlyEntity::class);

        $this->assertSame([], $metadata->getVersionColumns());
        $this->assertSame(['version'], $metadata->getAcknowledgedVersionColumns());
    }

    public function testPlainEntityHasNoVersionColumns(): void
    {
        $metadata = new EntityMetadata(VersionAttrPlainEntity::class);

        $this->assertSame([], $metadata->getVersionColumns());
        $this->assertSame([], $metadata->getAcknowledgedVersionColumns());
    }

    public function testGuardSetIsOwnPropertyColumnsExcludingPrimaryKeyAndVersion(): void
    {
        $this->assertSame([], (new EntityMetadata(VersionAttrCheckedEntity::class))->getGuardSet());
        $this->assertSame(['name'], (new EntityMetadata(VersionAttrPlainEntity::class))->getGuardSet());
    }

    public function testSameColumnInOwnVersionAndOwnVersionAwareIsMerelyRedundant(): void
    {
        $metadata = new EntityMetadata(VersionAttrRedundantEntity::class);

        $this->assertSame(['version'], $metadata->getVersionColumns());
        $this->assertSame(['version'], $metadata->getAcknowledgedVersionColumns());
    }

    public function testVersionAwareRequiresAnExplicitColumnList(): void
    {
        $required = (new \ReflectionClass(VersionAware::class))
            ->getConstructor()
            ->getNumberOfRequiredParameters();

        $this->assertSame(1, $required, 'VersionAware has no argless "acknowledge everything" form');
    }

    public function testNonIntVersionPropertyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EntityMetadata(VersionAttrBadTypeEntity::class);
    }

    public function testVersionPropertyDefaultsToZeroWhenNoExplicitDefaultGiven(): void
    {
        $metadata = new EntityMetadata(VersionAttrCheckedEntity::class);

        $this->assertSame('0', $metadata->getProperty('version')->getDefaultValue());
    }

    public function testBareVersionPropertyIsPersistedWithConventionColumnName(): void
    {
        $metadata = new EntityMetadata(VersionAttrOnlyEntity::class);

        $property = $metadata->getProperty('revisionCount');
        $this->assertNotNull($property);
        $this->assertSame('revision_count', $property->getColumnName());
        $this->assertSame('0', $property->getDefaultValue());
        $this->assertSame(['revision_count'], $metadata->getVersionColumns());
    }

    public function testVersionAcceptsExplicitColumnName(): void
    {
        $metadata = new EntityMetadata(VersionAttrNamedEntity::class);

        $property = $metadata->getProperty('revisionCount');
        $this->assertNotNull($property);
        $this->assertSame('lock_version', $property->getColumnName());
        $this->assertSame('0', $property->getDefaultValue());
        $this->assertSame(['lock_version'], $metadata->getVersionColumns());
    }

    public function testVersionWithPropertyOnSamePropertyDoesNotThrow(): void
    {
        $metadata = new EntityMetadata(VersionAttrCheckedEntity::class);

        $this->assertSame(['version'], $metadata->getVersionColumns());
    }
}
