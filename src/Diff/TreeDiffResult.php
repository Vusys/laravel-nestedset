<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Diff;

/**
 * Return value of {@see TreeDiff::apply()}. Lists the identity keys
 * actually mutated, partitioned by change type, plus a coarse
 * breakdown of the statements `apply()` issued.
 *
 * Dry-run callers receive a result with the same `added/removed/...`
 * key lists but `dryRun = true` and `plannedStatements` populated;
 * no statements were sent to the database.
 */
final readonly class TreeDiffResult
{
    /**
     * @param  list<int|string>  $added
     * @param  list<int|string>  $removed
     * @param  list<int|string>  $moved
     * @param  list<int|string>  $modified
     * @param  list<array{statement: string, rows: int}>  $plannedStatements
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $moved,
        public array $modified,
        public bool $dryRun,
        public array $plannedStatements,
    ) {}
}
