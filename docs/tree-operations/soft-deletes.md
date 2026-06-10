# Soft Deletes

When the model uses Laravel's `SoftDeletes` trait, the package cascades soft-delete, restore, and force-delete through the subtree — descendants follow the anchor without per-row Eloquent events firing, but the package's typed lifecycle events fill the visibility gap.

```php
class Category extends Model implements MaintainsTreeAggregates
{
    use NodeTrait, SoftDeletes;
}

$category->delete();      // soft-deletes the whole subtree (same deleted_at stamp)
$category->restore();     // restores only descendants stamped with that exact stamp
$category->forceDelete(); // hard-deletes the whole subtree
```

## What the cascade looks like

Start with a live tree. No `deleted_at` set anywhere:

```ns-tree
Electronics
  Computers
    Laptops
    Desktops
  Phones
    iPhone
    Android
Books
```

Soft-delete the `Computers` interior node:

```php
$computers->delete();
```

`Computers` and every descendant get the same `deleted_at` stamp — the `Phones` subtree is untouched, `Books` is untouched, but `Laptops` and `Desktops` are now hidden behind the soft-delete scope alongside their parent:

```ns-tree
Electronics
  Computers {trashed=2026-06-01T12:00:00.000000}
    Laptops {trashed=2026-06-01T12:00:00.000000}
    Desktops {trashed=2026-06-01T12:00:00.000000}
  Phones
    iPhone
    Android
Books
```

This is **one** cascade `UPDATE`: every row in the `Computers` subtree where `deleted_at IS NULL` gets the same `deleted_at` stamp. No per-row Eloquent `deleted` event fires for `Laptops` or `Desktops` — only for `Computers` itself. The package's [`SubtreeSoftDeleted`](../reference/events.html#cascade-events-soft-delete--restore--force-delete) event carries the full descendant id list so listeners (search index pruning, cache invalidation) can react in one round.

The structural columns (`lft` / `rgt` / `depth`) are **untouched** by the soft-delete — the trashed rows still occupy their slots in the index, they just disappear from default queries. A subsequent `restore()` simply nulls `deleted_at` back out and the subtree pops back in place.

## Soft-delete cascade

Calling `delete()` on a node with descendants stamps the same `deleted_at` value across the whole subtree in a single UPDATE — no per-row `delete()` calls, no recursion. A descendant that was independently trashed before the parent gets a different `deleted_at` value and is left alone; the cascade's `WHERE deleted_at IS NULL` makes this safe by design.

The stamp is at **seconds** precision — exactly the form Eloquent's `fromDateTime()` writes to the deleted node's own row at the default model date format. The cascade writes that same string to the descendants, so the anchor and its whole subtree carry a byte-identical value, and `restore()`'s match works the same on every backend (SQLite text column or a real timestamp column).

> [!NOTE]
> Two **independent** cascades (or a nested delete-inner-then-outer) that land in the same wall-clock second share a marker, so restoring one also restores the other's same-second rows that fall inside its bounds. The cascade is bounds-scoped, so **disjoint** subtrees are always isolated regardless of timing. If you need same-second independence between overlapping cascades, that's a deliberate limitation of using a seconds-precision marker — the package keeps it at seconds because a finer marker rounds on a `DATETIME(0)`/`timestamp(0)` column while the in-memory cast truncates, which would diverge across backends.

## Restore cascade

`restore()` reads the anchor's `deleted_at` value as the **restore marker** before clearing it, then issues a cascade UPDATE that nulls `deleted_at` on every descendant whose stamp matches that marker exactly. Descendants with a different stamp (independently trashed before the parent was) stay soft-deleted — restore them separately to bring them back.

The marker capture happens inside Eloquent's `restoring` event hook, before the anchor's own row is cleared, so the value is read while it's still in the database. This is how the cascade survives the round-trip: Eloquent doesn't let observers reach into model state mid-restore, but the package's `SoftDeleteMarkerCaptured` event signals the buffered value and `applyRestoreCascade` consumes it.

## Force-delete cascade

`forceDelete()` on an interior node hard-deletes every descendant in a single raw query-builder `DELETE` (no per-row Eloquent `deleted` events for descendants), then closes the gap in `lft` / `rgt` like a normal mutation. This mirrors the soft-delete shape — both cascades issue exactly one descendant-touching SQL statement regardless of subtree size — so it's safe on multi-thousand-row subtrees without exploding the round-trip count.

Force-delete on a leaf (`rgt - lft === 1`) skips the descendant query entirely — there's nothing to cascade — and just runs the gap-close.

## Lifecycle events

Per-row Eloquent `deleted` / `restored` events **never fire** for cascaded descendants — only for the anchor. The package's typed cascade events close that gap so listeners (search indexes, cache invalidation, audit logs) can react to descendant changes:

| Event | Fires when |
|---|---|
| `SubtreeSoftDeleting` | before the cascade UPDATE that propagates `deleted_at` |
| `SubtreeSoftDeleted` | after the cascade UPDATE — carries `descendantIds` |
| `SoftDeleteMarkerCaptured` | inside `restoring`, when the package buffers the marker used to match descendants |
| `SubtreeRestoring` | before the restore-cascade UPDATE |
| `SubtreeRestored` | after the restore-cascade UPDATE — carries `descendantIds` |
| `SubtreeForceDeleting` | before the hard-delete cascade on `forceDelete()` of an interior node |
| `SubtreeForceDeleted` | after the cascade DELETE — carries `descendantIds` |

`descendantIds` is the **strict** descendant set (excludes the anchor). The anchor itself fires Eloquent's normal `deleted` / `restored` events in addition to the `Subtree*` pair.

The `descendantIds` collection is only populated when at least one listener for the corresponding event is registered (the package short-circuits the descendant SELECT when no one's listening). So subscribing to `SubtreeSoftDeleted` does add one extra SELECT per cascade — but not subscribing costs nothing.

See [Events → Cascade events](../reference/events.html#cascade-events-soft-delete--restore--force-delete) for the full payload reference and recipes.

## Aggregate maintenance

Soft-delete cascade **preserves stored aggregates on the soft-deleted subtree** — the column values inside the subtree are untouched. The ancestor chain is decremented (the soft-deleted subtree no longer contributes), and `restore()` re-adds the contribution. This matches the semantic that "soft-deleted means hidden, not gone".

Force-delete decrements the ancestor chain like a normal delete; the destroyed rows take their stored aggregate values with them.

See [Aggregates → Drift & Limitations](../aggregates/drift.html) for the full per-mutation accounting.

## Combining with scoped trees

Scoped models work the same way — every cascade query is constrained by the same scope columns as the anchor, so cross-scope soft-deletes don't accidentally cascade outside the anchor's tree.

## Limitations

### Soft-delete column rename is supported

The package reads the column name via reflection on `getDeletedAtColumn()`, so overriding `const DELETED_AT` or the method works without configuration.

### Microsecond precision depends on the column type

See the note above on `DATETIME(6)` vs `DATETIME(0)`.

### `withTrashed()` outer queries see soft-deleted descendants

Pair with `freshAggregate('col', withTrashed: true)` if you're auditing aggregates across the trashed surface. See [Reading Values](../aggregates/reading.html).
