<?php

namespace Articulate\Modules\Cache;

use InvalidArgumentException;

/**
 * Produces a deterministic, type-tagged string for an identifier so distinct
 * ids never collide on the same key (e.g. int 1 vs string "1", or two value
 * objects that JSON-encode to the same shape). Composite keys (arrays) are
 * normalized recursively in a stable order.
 *
 * Shared by SecondLevelCache (per-entity region) and the reverse-index
 * writes/lookups in this module, so both halves of the L2 cache agree on
 * exactly the same id representation for the same identifier value.
 */
final class IdNormalizer {
    public static function normalize(mixed $id): string
    {
        if (is_int($id) || is_string($id)) {
            return (is_int($id) ? 'i:' : 's:') . $id;
        }

        if (is_bool($id)) {
            return 'b:' . ($id ? '1' : '0');
        }

        if (is_float($id)) {
            return 'd:' . $id;
        }

        if ($id instanceof \Stringable) {
            return 'o:' . $id::class . ':' . $id;
        }

        if (is_array($id)) {
            ksort($id);
            $parts = [];
            foreach ($id as $key => $value) {
                $parts[] = $key . '=' . self::normalize($value);
            }

            return 'a:[' . implode(',', $parts) . ']';
        }

        throw new InvalidArgumentException(
            'Cache identifier must be scalar, Stringable, or array; got ' . get_debug_type($id)
        );
    }
}
