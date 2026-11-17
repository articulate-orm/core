<?php

namespace Articulate\Modules\QueryBuilder;

/**
 * A "table [alias]" string as passed to from()/join(): the table is the first
 * token, the name the query refers to it by is the last.
 */
final class TableReference {
    public static function table(string $reference): string
    {
        return self::tokens($reference)[0];
    }

    public static function alias(string $reference): string
    {
        $tokens = self::tokens($reference);

        return $tokens[count($tokens) - 1];
    }

    /**
     * @return non-empty-list<string>
     */
    private static function tokens(string $reference): array
    {
        $tokens = preg_split('/\s+/', trim($reference));

        return $tokens === false || $tokens === [] ? [$reference] : $tokens;
    }
}
