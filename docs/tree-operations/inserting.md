# Inserting & Moving Nodes

Every mutation is a method on the model that queues a pending operation;
the actual work happens on the next `save()`, wrapped in a transaction
(configurable, on by default).

```php
$root  = new Category(['name' => 'Root']);
$root->saveAsRoot();

$a = new Category(['name' => 'A']);
$a->appendToNode($root)->save();         // last child of $root

$first = new Category(['name' => 'First']);
$first->prependToNode($root->refresh())->save();   // first child of $root

$before = new Category(['name' => 'Before']);
$before->insertBeforeNode($a->refresh())->save();  // sibling before $a

$after = new Category(['name' => 'After']);
$after->insertAfterNode($a->refresh())->save();    // sibling after $a

$a->makeRoot()->save();   // detach to its own tree
$a->saveAsRoot();         // shorthand for makeRoot()->save()

$a->up();                 // swap with previous sibling (returns bool)
$a->down();               // swap with next sibling

$a->prevSibling();        // ?static — sibling immediately before this node
$a->nextSibling();        // ?static — sibling immediately after this node
```

> **Important:** pass a fresh copy of the parent / sibling (`->refresh()`)
> when you've inserted other rows since loading it. The trait re-reads
> the target's bounds from the database before mutating to stay safe
> against stale parent_ids, but the trait can't refresh nodes you handed
> it.

## Cross-tree moves

`appendToNode` and friends accept any `HasNestedSet` of the same model
class. Moving between scopes is rejected with `ScopeViolationException` —
see [Scoped Trees](../querying/scoped-trees.html).
