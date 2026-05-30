<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;

/**
 * Thrown by the in-memory walker (`walk()`, `dfs()`, `dfsPostOrder()`,
 * `bfs()`, `flattenedSubtree()`) when no explicit subtree collection is
 * supplied AND the `descendants` relation is not loaded on the node.
 *
 * The walker is a pure in-memory traversal helper — it never issues its
 * own SQL. Eager-load `descendants` with `->load('descendants')` (or
 * via `with('descendants')` at query time), or pass an already-loaded
 * collection as the `$subtree` argument. This discipline is deliberate:
 * if a caller has narrowed the subtree on purpose (filtered, depth-
 * limited, ad-hoc), the walker honours that scope rather than silently
 * widening it.
 *
 * Extends LogicException because forgetting to load is a programmer
 * error at the call site, not a data corruption problem.
 */
final class UnloadedSubtreeException extends LogicException {}
