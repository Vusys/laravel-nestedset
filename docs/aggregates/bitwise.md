# Bitwise aggregates — bitOr, bitAnd, bitXor

Bitwise rollups roll up an integer-valued source column over a subtree, folding values with the corresponding bitwise operator. They're useful any time the source column packs independent yes/no facts into a single integer — feature flags, capability bits, status bitmasks.

```php
use Vusys\NestedSet\Attributes\NestedSetAggregate;

#[NestedSetAggregate(column: 'features_or',  bitOr:  'feature_bits')]
#[NestedSetAggregate(column: 'features_and', bitAnd: 'feature_bits')]
#[NestedSetAggregate(column: 'features_xor', bitXor: 'feature_bits')]
class Module extends Model implements HasNestedSet
{
    use NodeTrait;
}
```

Or via the method-override form:

```php
protected function nestedSetAggregates(): array
{
    return [
        Aggregate::bitOr('feature_bits')->into('features_or'),
        Aggregate::bitXor('row_hash')->into('subtree_fingerprint'),
    ];
}
```

## What each one answers

- **`bitOr`** — "**does any** descendant have feature X?" `parent.features_or = OR of every descendant's feature_bits`. Reading `($parent->features_or & FEATURE_PREMIUM) !== 0` tells you whether anyone in the subtree has the premium bit.
- **`bitAnd`** — "**do all** descendants have feature X?" `parent.features_and = AND of every descendant's feature_bits`. Reading `($parent->features_and & FEATURE_PREMIUM) !== 0` tells you every node in the subtree has premium.
- **`bitXor`** — order-independent subtree fingerprint. `parent.features_xor = XOR of every descendant's source value`. Two subtrees with the same set of descendant values produce the same XOR, regardless of which order they were inserted. Useful as a cheap "did anything in this subtree change?" check.

## Schema

```php
$table->nestedSetAggregate('features_or',  type: 'bitwise');
$table->nestedSetAggregate('features_and', type: 'bitwise');
$table->nestedSetAggregate('features_xor', type: 'bitwise');
```

The macro emits a nullable `bigint`. Empty subtrees read as `NULL` — distinguishable from "every descendant had zero bits set" (which reads as `0`).

## How they stay in sync

Each kind picks the cheapest correct maintenance strategy per mutation:

| Kind     | Insert                              | Source update                              | Delete                              |
| -------- | ----------------------------------- | ------------------------------------------ | ----------------------------------- |
| `bitOr`  | Delta: `parent \|= new`             | Chain recompute (lost-bit problem)         | Chain recompute (lost-bit problem)  |
| `bitAnd` | Chain recompute (insert may narrow) | Chain recompute                            | Chain recompute (may widen)         |
| `bitXor` | Delta: `parent ^= new`              | Delta: `parent ^= (old ^ new)`             | Delta: `parent ^= old_subtree_xor`  |

**`bitXor` is the headline case** — it's the only non-Sum-family aggregate with a delta path on every mutation. XOR is self-inverse, so adding *and* removing a contribution are the same operation. The "delete" delta uses the deleted node's stored `features_xor` column (its inclusive subtree XOR), not its source value — XOR-ing that out of every ancestor undoes the whole subtree's contribution in one statement.

`bitOr` and `bitAnd` rely on chain recompute for any mutation that could lose a bit (`bitOr` delete, `bitAnd` insert/delete) because the rolled-up value alone can't tell you whether a bit you're about to unset was held by the row you're touching or by some other descendant.

## Per-backend SQL

The package emits `BIT_OR(col)` / `BIT_AND(col)` / `BIT_XOR(col)` uniformly. MySQL, MariaDB, and PostgreSQL 14+ have all three natively. SQLite has none, so the package registers user-defined aggregates on the SQLite PDO connection at boot (and defensively before every bitwise read) — same SQL, same semantics across all four backends.

The XOR delta SET clause uses the portable identity `a XOR b = (a | b) - (a & b)` rather than `a ^ b`, because `^` is exponentiation on PostgreSQL and unrecognised on SQLite.

## Limitations

### Listener aggregates are rejected

Bitwise over a PHP-computed contribution is rejected at definition construction. Declare bitwise aggregates over a real source column or roll your own from a Sum + per-bit count.

### `bitOr` and `bitAnd` source updates route through chain recompute

For deep trees this is O(depth × subtree-size) per mutation. If your write path is hot and the subtree is large, prefer `bitXor` (full delta path) or defer maintenance via `queueFixAggregates` / `withDeferredAggregateMaintenance`.

### Source values are coerced to integer at SET-clause emission

Floats silently truncate. Bitwise operations only have well-defined semantics on integers; if your source column holds floats, the rollup is almost certainly wrong by design.
