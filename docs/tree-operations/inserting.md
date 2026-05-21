# Inserting & Moving Nodes

Every mutation is a method on the model that queues a pending operation; the actual work happens on the next `save()`, wrapped in a transaction (configurable, on by default). This page walks through each positional method using a single example tree, and shows what the tree looks like after each operation.

For "put this node under that parent at slot N" — the common drag-and-drop / REST-PATCH shape — skip ahead to the `moveTo` section, which wraps the four primitives below into one ergonomic entry point.

## Building the example tree

```php
// Step 1: root
$electronics = new Category(['name' => 'Electronics']);
$electronics->saveAsRoot();

// Step 2: append a child
$computers = new Category(['name' => 'Computers']);
$computers->appendToNode($electronics)->save();

// Step 3: append another — appendToNode always becomes the LAST child
$phones = new Category(['name' => 'Phones']);
$phones->appendToNode($electronics->refresh())->save();
```

After step 3:

```text
Electronics
├── Computers     ← first child (was appended first)
└── Phones        ← last child
```

## prependToNode — insert as first child

```php
$audio = new Category(['name' => 'Audio']);
$audio->prependToNode($electronics->refresh())->save();
```

```text
Electronics
├── Audio         ← prepended in front
├── Computers
└── Phones
```

## insertBeforeNode / insertAfterNode — siblings

```php
$accessories = new Category(['name' => 'Accessories']);
$accessories->insertBeforeNode($phones->refresh())->save();

$tablets = new Category(['name' => 'Tablets']);
$tablets->insertAfterNode($phones->refresh())->save();
```

```text
Electronics
├── Audio
├── Computers
├── Accessories   ← inserted before Phones
├── Phones
└── Tablets       ← inserted after Phones
```

## moveTo — pick a destination by position

The four primitives above (`appendToNode`, `prependToNode`, `insertBeforeNode`, `insertAfterNode`) cover every move you can express in the nested set, but most call sites think in terms of "put this node under that parent at slot N". `moveTo` collapses the four into one entry point that picks the right primitive for you.

```php
$node->moveTo($parent, 'last');          // → appendToNode
$node->moveTo($parent, 'first');         // → prependToNode
$node->moveTo($parent, 0);               // → prependToNode (alias of 'first')
$node->moveTo($parent, 2);               // → insertBeforeNode(siblings[2])
$node->moveTo($parent, 99);              // → appendToNode (clamped past the end)
```

`'last'` is the default, so `$node->moveTo($parent)` is the same as `appendToNode`. Negative positions and unrecognised strings throw `LogicException`.

The integer index is **0-based** and counted *after* removing `$node` from its current siblings if it already lives under `$parent`. That means "position N" reads as "end up at final index N" rather than "skip N other siblings" — same-parent reorders use the same call shape:

```php
$audio->refresh()->moveTo($audio->parent, 3);   // move Audio so it lands at index 3
```

```text
Electronics
├── Computers
├── Accessories
├── Phones
├── Audio          ← was index 0, now index 3
└── Tablets
```

### moveBefore / moveAfter — sibling-relative aliases

When you already have a sibling reference, the explicit aliases read better than computing an index:

```php
$tablets->moveBefore($phones);   // wraps insertBeforeNode($phones)
$tablets->moveAfter($audio);     // wraps insertAfterNode($audio)
```

### Things to know

- **Same-position moves still emit `NodeMoved`.** Resolving to a position the node already occupies skips the structural SQL — the underlying `CASE WHEN` is a no-op — but the event surface still fires with `fromBounds === toBounds`. Consumers wiring audit listeners that treat `NodeMoved` as "the tree changed" should filter on `fromBounds !== toBounds`.
- **Cross-scope rejection happens at different times depending on the position arm.** Integer positions ≥ 1 do an eager `assertSameScope` (the sibling lookup needs scope to build the query); the string-equivalent arms (`'first'`, `'last'`, `0`) defer to `save()` time, matching the primitives they wrap.
- **The parent must be saved for integer positions ≥ 1.** `moveTo($unsavedParent, 1)` throws `LogicException` because there's no parent key to look up siblings against. `'first'` / `'last'` / `0` delegate straight to the primitives without that constraint (though `save()` will still fail against an unplaced parent).

## up / down — reorder among siblings

`up()` swaps with the previous sibling; `down()` swaps with the next. Both return `true` if a swap happened, `false` if there was no neighbour to swap with.

> **Tree corruption can mask "no neighbour" as a false return.** Both methods look up the sibling via `lft / rgt`, so on a tree with gap corruption (e.g. a leaf hard-delete that mis-shifted bounds) the sibling query may return `null` even though a logical sibling exists. The methods can't distinguish "genuinely no neighbour" from "tree is broken". Pair persistent unexpected `false` returns with `isBroken()` / `countErrors()` to rule out structural corruption before assuming the row really is at an edge.

```php
$audio->refresh()->down();    // Audio swaps places with Computers
```

```text
Electronics
├── Computers     ← was second, now first
├── Audio         ← was first, now second
├── Accessories
├── Phones
└── Tablets
```

## makeRoot — detach into a new tree

`makeRoot()` lifts a node (and its subtree) out of its current parent and reroots it as a standalone tree. `saveAsRoot()` is the same thing in one call.

```php
$phones->refresh()->makeRoot()->save();
```

```text
Electronics
├── Computers
├── Audio
├── Accessories
└── Tablets

Phones             ← own tree now
```

## Sibling lookups (read-only)

```php
$computers->refresh()->prevSibling();   // null — Computers is the first child
$computers->nextSibling()->name;        // 'Audio'
```

## The refresh footgun

> Pass a fresh copy of the parent / sibling (`->refresh()`) when you've inserted other rows since loading it.

The trait re-reads the target's bounds from the database before mutating, so the tree stays safe against stale `parent_id`s — but it can't refresh nodes you handed it. After any mutation, the in-memory copy you used as a target has stale `lft` / `rgt` values; the next mutation against that same instance must `->refresh()` first or risk inserting at the wrong slot.

The asymmetry is deliberate: the **target** node is read fresh from the DB inside every mutation (the package owns that read), so a stale target instance can't cause wrong-slot inserts — but the **moving** node is the user's object and the package can't safely refresh it without clobbering pending in-memory changes you may want persisted. If you've held a reference to a parent / sibling across multiple mutations, refresh that reference; if you've only handed it to one `appendToNode(...)->save()`, you don't need to.

## Cross-tree moves

`appendToNode` and friends accept any `HasNestedSet` of the same model class. Moving between scopes is rejected with `ScopeViolationException` — see [Scoped Trees](../querying/scoped-trees.html).
