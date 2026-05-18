# Inserting & Moving Nodes

Every mutation is a method on the model that queues a pending
operation; the actual work happens on the next `save()`, wrapped in a
transaction (configurable, on by default). This page walks through
each positional method using a single example tree, and shows what the
tree looks like after each operation.

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

## up / down — reorder among siblings

`up()` swaps with the previous sibling; `down()` swaps with the next.
Both return `true` if a swap happened, `false` if there was no
neighbour to swap with.

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

`makeRoot()` lifts a node (and its subtree) out of its current parent
and reroots it as a standalone tree. `saveAsRoot()` is the same thing
in one call.

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

> Pass a fresh copy of the parent / sibling (`->refresh()`) when
> you've inserted other rows since loading it.

The trait re-reads the target's bounds from the database before
mutating, so the tree stays safe against stale `parent_id`s — but it
can't refresh nodes you handed it. After any mutation, the in-memory
copy you used as a target has stale `lft` / `rgt` values; the next
mutation against that same instance must `->refresh()` first or risk
inserting at the wrong slot.

## Cross-tree moves

`appendToNode` and friends accept any `HasNestedSet` of the same model
class. Moving between scopes is rejected with
`ScopeViolationException` — see [Scoped Trees](../querying/scoped-trees.html).
