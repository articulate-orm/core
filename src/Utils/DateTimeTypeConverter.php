<?php

namespace Articulate\Utils;

use DateTime;
use DateTimeImmutable;

class DateTimeTypeConverter implements TypeConverterInterface {
    /**
     * @param class-string<\DateTimeInterface>|null $defaultTargetType used when convertToPHP() gets no target type,
     *                                                    which is how the hydrators call it through TypeConverterInterface
     */
    public function __construct(
        private readonly ?string $defaultTargetType = null,
    ) {
    }

    public function convertToDatabase(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTime || $value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    public function convertToPHP(mixed $value, ?string $targetType = null): mixed
    {
        $targetType ??= $this->defaultTargetType;

        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable || $value instanceof DateTime) {
            if ($targetType === DateTimeImmutable::class && $value instanceof DateTime) {
                return DateTimeImmutable::createFromMutable($value);
            }

            if ($targetType === DateTime::class && $value instanceof DateTimeImmutable) {
                return DateTime::createFromImmutable($value);
            }

            return $value;
        }

        if (is_string($value)) {
            if ($targetType === DateTimeImmutable::class) {
                return new DateTimeImmutable($value);
            }

            return new DateTime($value);
        }

        return $value;
    }
}
