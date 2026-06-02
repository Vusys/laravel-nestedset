<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\TreeAggregateListener;

/**
 * Returns the numeric value of the `score` column as a node's contribution.
 * A null `score` excludes the row from the aggregate — useful for testing
 * the listener filter / variance / geomean / harmonic paths against rows
 * that genuinely don't participate.
 */
final class ScoreListener implements TreeAggregateListener
{
    public function contribution(Model $node): ?float
    {
        $value = $node->getAttribute('score');

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<string>
     */
    public function watchColumns(): array
    {
        return ['score'];
    }
}
