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

```ns-tree
Electronics
  Computers
  Phones
```

`Computers` was appended first (becomes the first child); `Phones` was appended next (becomes the last child). The `lft` / `rgt` badges on each row show the slot range — Electronics now spans 1..6, with Computers at (2, 3) and Phones at (4, 5).

## prependToNode — insert as first child

```php
$audio = new Category(['name' => 'Audio']);
$audio->prependToNode($electronics->refresh())->save();
```

```ns-tree
Electronics
  Audio
  Computers
  Phones
```

Audio takes the first-child slot (lft=2). Computers and Phones shifted right by 2 slots each to make room — that's the single `CASE WHEN UPDATE` happening under the surface.

## insertBeforeNode / insertAfterNode — siblings

```php
$accessories = new Category(['name' => 'Accessories']);
$accessories->insertBeforeNode($phones->refresh())->save();

$tablets = new Category(['name' => 'Tablets']);
$tablets->insertAfterNode($phones->refresh())->save();
```

```ns-tree
Electronics
  Audio
  Computers
  Accessories
  Phones
  Tablets
```

Accessories landed at Phones' old slot; Phones shifted right. Tablets landed in the new slot just past Phones. Electronics' `rgt` grew by 4 (two inserts × 2 slots each) but `Audio` / `Computers` stayed where they were — they're left of the insertion point.

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

```ns-tree
Electronics
  Computers
  Accessories
  Phones
  Audio
  Tablets
```

Audio's lft/rgt have shifted from the very start of the subtree to slot index 3. Computers, Accessories, Phones each gained 2 slots of "lower" position (their lft/rgt values dropped because Audio's 2-slot subtree moved past them); Tablets' values are unchanged (right of the destination, unaffected by Audio's removal-then-reinsertion at index 3).

### moveBefore / moveAfter — sibling-relative aliases

When you already have a sibling reference, the explicit aliases read better than computing an index:

```php
$tablets->moveBefore($phones);   // wraps insertBeforeNode($phones)
$tablets->moveAfter($audio);     // wraps insertAfterNode($audio)
```

### Things to know

#### Same-position moves still emit `NodeMoved`

Resolving to a position the node already occupies skips the structural SQL — the underlying `CASE WHEN` is a no-op — but the event surface still fires with `fromBounds === toBounds`. Consumers wiring audit listeners that treat `NodeMoved` as "the tree changed" should filter on `fromBounds !== toBounds`.

#### Cross-scope rejection happens at different times depending on the position arm

Integer positions ≥ 1 do an eager `assertSameScope` (the sibling lookup needs scope to build the query); the string-equivalent arms (`'first'`, `'last'`, `0`) defer to `save()` time, matching the primitives they wrap.

#### The parent must be saved for integer positions ≥ 1

`moveTo($unsavedParent, 1)` throws `LogicException` because there's no parent key to look up siblings against. `'first'` / `'last'` / `0` delegate straight to the primitives without that constraint (though `save()` will still fail against an unplaced parent).

## up / down — reorder among siblings

`up()` swaps with the previous sibling; `down()` swaps with the next. Both return the wrapped `->save()` result:

- `true` — the swap ran and the save succeeded.
- `false` — either there was no neighbour to swap with, **or** the swap ran but the underlying `->save()` returned `false` (a `saving` observer returned `false`, a connection-level error, etc.). Don't treat `false` as "definitely a no-op"; check `wasChanged()` or `prevSibling()` / `nextSibling()` afterwards if you need to distinguish the two.

> **Tree corruption can mask "no neighbour" as a false return.** Both methods look up the sibling via `lft / rgt`, so on a tree with gap corruption (e.g. a leaf hard-delete that mis-shifted bounds) the sibling query may return `null` even though a logical sibling exists. The methods can't distinguish "genuinely no neighbour" from "tree is broken". Pair persistent unexpected `false` returns with `isBroken()` / `countErrors()` to rule out structural corruption before assuming the row really is at an edge.

```php
$audio->refresh()->down();    // Audio swaps places with Computers
```

```ns-tree
Electronics
  Computers
  Audio
  Accessories
  Phones
  Tablets
```

Computers (was second, lft=4) and Audio (was first, lft=2) swap slot ranges. Subtrees ride with their roots: anything under Computers or Audio would shift with them. The other siblings' bounds are untouched — the swap is bounded by the two participants' combined slot range.

## makeRoot — detach into a new tree

`makeRoot()` lifts a node (and its subtree) out of its current parent and reroots it as a standalone tree. `saveAsRoot()` is the same thing in one call.

```php
$phones->refresh()->makeRoot()->save();
```

```ns-tree
Electronics
  Computers
  Audio
  Accessories
  Tablets
Phones
```

Phones is now a sibling root of Electronics — same forest, but its `parent_id` is null and `depth` is 0. Its `lft` / `rgt` start fresh after Electronics' subtree ends (it's appended at the end of the forest, not inserted between existing roots). The Electronics subtree shrank by Phones' two slots; everything to the right (which was just Tablets) shifted left.

## Sibling lookups (read-only)

```php
$computers->refresh()->prevSibling();   // null — Computers is the first child
$computers->nextSibling()->name;        // 'Audio'
```

## The refresh footgun

> After mutating under a node you hold a reference to, call `->refresh()` on it before reading from it again.

The trait re-reads the target's `lft` / `rgt` from the database inside every mutation, so the **mutation itself is safe** against stale target instances — passing an out-of-date parent or sibling can't cause a wrong-slot insert. What goes stale is the in-memory model **after** the mutation: every insert shifts ancestors' `rgt` (and may shift siblings' bounds too), but the in-memory copy you handed in still carries the pre-mutation values.

The footgun is **subsequent reads** off that stale instance. Any method that derives from `getBounds()` — `->descendants()->get()`, `->getSubtreeSize()`, `getDescendantCount()`, the inspection predicates — uses the in-memory `lft` / `rgt`, so they return wrong answers until you refresh:

```php
$root->saveAsRoot();
$child->appendToNode($root)->save();

$root->descendants()->get();             // EMPTY — $root->rgt is still 2 in memory
$root->refresh()->descendants()->get();  // collection containing $child
```

The asymmetry is deliberate: the **target** node is read fresh from the DB inside the mutation (the package owns that read), but the **moving** node and any sibling/parent references you keep across calls are your objects — the package can't safely refresh them without clobbering pending in-memory changes you may want persisted. Rule of thumb: if you held a reference to a parent / sibling across a mutation that touched its subtree, refresh it before reading from it again.

## Cross-tree moves

`appendToNode` and friends accept any `HasNestedSet` of the same model class. Moving between scopes is rejected with `ScopeViolationException` — see [Scoped Trees](../querying/scoped-trees.html).
