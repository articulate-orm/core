---
title: Custom Types
description: Convert between PHP-specific values and database storage values.
sidebar:
  order: 8
---

import { Aside } from '@astrojs/starlight/components';

Convert between PHP-specific values and database storage values.

## `TypeConverterInterface`

```php
interface TypeConverterInterface
{
    public function convertToPHP(mixed $value): mixed;
    public function convertToDB(mixed $value): mixed;
}
```

Use a converter when the database representation is not the same shape as the PHP representation. Common examples are enums, value objects, booleans, dates, and geometry-like values.

## Built-in converters

Articulate ships built-in mappings — `bool` ↔ `TINYINT(1)`, `int` ↔ `INT`, `float` ↔ `FLOAT`, `string` ↔ `VARCHAR(255)`, `DateTimeInterface` ↔ `DATETIME` — plus named converters for common cases:

- `BoolTypeConverter`
- `DateTimeTypeConverter`
- `PointTypeConverter`

Custom class mappings and `TypeConverterInterface` cover complex types, with priority-based resolution when a class implements multiple interfaces with registered mappings.

## Registration

```php
$typeRegistry->registerType('point', new PointTypeConverter());
$typeRegistry->registerClassMapping(Point::class, 'point');
```

Once registered, entity properties can use the type name through `#[Property(type: 'point')]` or class mapping.

```php
public function setStatus(ProductStatus $status): void
{
    $this->status = (new ProductStatusConverter())->convertToDatabase($status);
}

public function statusEnum(): ProductStatus
{
    return (new ProductStatusConverter())->convertToPHP($this->status);
}
```

## Common pitfalls

<Aside type="caution">
- Register converters before hydrating or persisting values that require them.
- Keep database values stable even when PHP enum or value-object names change.
- Nullable custom fields still need converter code that handles `null` when the column allows it.
</Aside>
