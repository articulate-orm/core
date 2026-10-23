<?php

namespace Articulate\Benchmarks;

use Articulate\QueryLogger\QueryLoggerInterface;

final class QueryCounter implements QueryLoggerInterface {
    public int $total = 0;

    /** @var array<string, int> */
    public array $byVerb = [];

    public function log(string $sql, array $parameters, float $durationMs): void
    {
        ++$this->total;
        $verb = strtoupper(strtok(ltrim($sql), " \n\t") ?: '?');
        $this->byVerb[$verb] = ($this->byVerb[$verb] ?? 0) + 1;
    }

    public function reset(): void
    {
        $this->total = 0;
        $this->byVerb = [];
    }
}
