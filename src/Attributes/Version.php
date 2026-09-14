<?php

namespace Articulate\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Version {
    /**
     * @param ?string $name Explicit column name, same semantics as #[Property]'s $name.
     *                       #[Version] implies #[Property]; a bare #[Version] property is
     *                       persisted using the same column-name/type resolution.
     */
    public function __construct(
        public ?string $name = null,
    ) {
    }
}
