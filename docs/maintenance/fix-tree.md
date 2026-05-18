# Tree Repair

Production tables get corrupted — failed migrations, manual SQL surgery,
bugs in old code. The repair toolkit lets you validate and rebuild:

```php
Category::isBroken();                       // bool
Category::countErrors();
// ['invalid_bounds' => 0, 'duplicate_lft' => 2, 'duplicate_rgt' => 0, 'orphans' => 1]

Category::fixTree();                        // rebuilds lft/rgt/depth from parent_id
// → TreeFixResult { nodesUpdated: 15, errors: [...counts after repair...] }
```

On a scoped model, an anchor node is required so the repair stays inside
one tree (this prevents accidental full-table walks on multi-million-row
forests):

```php
MenuItem::isBroken();                       // ScopeViolationException — no anchor
MenuItem::isBroken($anyNodeFromThatMenu);   // OK — scoped to that menu

MenuItem::fixTree($anchor);                 // repair one menu's tree
```

## What gets corrupted, what's auto-fixable, and how to avoid it

The package treats **`parent_id` as the source of truth**. `fixTree()`
rebuilds `lft`/`rgt`/`depth` from a `parent_id` walk, so as long as
`parent_id` describes the tree you actually want, every other column is
recoverable.

| Corruption | Detected by `countErrors()`? | Repaired by `fixTree()`? | Typical cause |
| --- | --- | --- | --- |
| `invalid_bounds` (`lft >= rgt`) | ✅ | ✅ | Raw `UPDATE` on `lft`/`rgt`; crashed transaction. |
| `duplicate_lft` / `duplicate_rgt` | ✅ | ✅ | Concurrent gap-shifts without locking; partial migration. |
| `orphans` (`parent_id` → missing row) | ✅ | ❌ — detected but not auto-repaired | Hard `DELETE` of a parent without cascading. |
| `parent_id` cycles | ❌ — not surfaced by `countErrors()` | ❌ — cycle members are silently skipped | Raw `UPDATE` on `parent_id` that bypassed Eloquent guards. |
| Aggregate drift (stored `articles_total` ≠ computed) | ✅ via `aggregateErrors()` | ✅ via `fixAggregates()` | Raw `UPDATE` on the source column. |

**Best practice in one rule:** mutate trees only through Eloquent on a
`NodeTrait` model. Every `appendToNode`/`prependToNode`/`insertBeforeNode`/
`insertAfterNode`/`makeRoot`/`delete`/`forceDelete`/`restore` call is
wrapped in a transaction and maintains every invariant. Most of the
corruption categories above are reachable only by bypassing that surface.

See [Corruption Reference](corruption.html) for the full taxonomy with
worked recovery recipes, diagnostic SQL for finding cycles, and
`tests/Feature/Corruption/` for executable examples of every category.
