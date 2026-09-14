<?php

namespace Articulate\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class VersionAware {
    /**
     * @param string[] $columns Version column names this class acknowledges writing through
     *                           without taking on their lost-update detection. Inert at runtime
     *                           (no SET, no WHERE, no bump) — consumed only by articulate:validate.
     */
    public function __construct(
        public array $columns,
    ) {
    }
}
