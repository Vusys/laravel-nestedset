<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\BulkInsert;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasBulkInsert;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Fires once after the DFS planning walk inside
 * {@see HasBulkInsert::bulkInsertTree()} but before the transaction
 * opens and any row is saved.
 *
 * `$plan` is the flat list of `{attributes, lft, rgt, depth,
 * parentPlanIndex}` rows the package will save in order. lft/rgt
 * are **relative** at this point (1..2N) — the anchor offset is
 * applied inside the transaction, so absolute bounds are not yet
 * known.
 *
 * Useful for: pre-import telemetry ("this tree is 12 levels deep
 * with 5_000 nodes"), capacity guards ("reject imports over N
 * rows"), feature-flag-gated dry runs.
 *
 * Not queue-safe — the plan can contain arbitrary attribute values
 * the application chose to pass in.
 */
final readonly class BulkInsertTreePlanned
{
    /**
     * @param  list<array{
     *     attributes: array<string, mixed>,
     *     lft: int,
     *     rgt: int,
     *     depth: int,
     *     parentPlanIndex: int|null,
     * }>  $plan
     */
    public function __construct(
        public string $modelClass,
        public (Model&HasNestedSet)|null $appendTo,
        public array $plan,
    ) {}
}
